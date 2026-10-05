<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `loadtest:storm`: simula a abertura de vendas. A cada onda, `--concurrency` compras
 * (~10% com Idempotency-Key repetida = clique duplo) disparam AO MESMO TEMPO que
 * `--pollers` requisições ao painel (a equipe com o painel aberto). Mede a latência de
 * CADA requisição e, no final, confere no banco que sold + reserved <= total.
 *
 * Use com ORDERS_RATE_LIMIT_PER_MINUTE=0 (senão o limite por IP responde 429).
 */
class LoadtestStorm extends Command
{
    protected $signature = 'loadtest:storm {--batch=} {--buyers=500} {--concurrency=100} {--pollers=30}';

    protected $description = 'Simula abertura de vendas: compradores concorrentes + pollers do painel.';

    public function handle(): int
    {
        $batchId = $this->option('batch') ?? Batch::orderBy('id')->value('id');
        $admin = User::where('role', User::ROLE_ADMIN)->first();

        if (! $batchId || ! $admin) {
            $this->error('Rode o seed primeiro (precisa de lote e admin).');

            return self::FAILURE;
        }

        $buyers = (int) $this->option('buyers');
        $concurrency = max(1, (int) $this->option('concurrency'));
        $pollers = (int) $this->option('pollers');
        $baseUrl = rtrim(env('LOADTEST_BASE_URL', 'http://127.0.0.1:8000'), '/').'/api/v1';
        $tokenModel = $admin->createToken('loadtest');
        $token = $tokenModel->plainTextToken;

        $this->info("{$buyers} compras (ondas de {$concurrency}) + {$pollers} pollers do painel simultâneos, lote {$batchId}");

        $status = [];
        $lat = ['compra' => [], 'painel' => []];
        $started = microtime(true);

        foreach (collect(range(1, $buyers))->chunk($concurrency) as $wave) {
            $responses = Http::pool(function (Pool $pool) use ($wave, $pollers, $batchId, $baseUrl, $token) {
                $requests = [];
                foreach ($wave as $i) {
                    // Clique duplo: mesma chave E mesmo corpo (senão seria 422 por payload diferente).
                    $dup = $i % 10 === 0;
                    $key = $dup ? 'storm-dup-'.intdiv($i, 100) : (string) Str::uuid();
                    $who = $dup ? 'dup-'.intdiv($i, 100) : $i;
                    $requests[] = $pool->as("compra-{$i}")->timeout(30)->withHeaders(['Idempotency-Key' => $key])
                        ->post("{$baseUrl}/orders", [
                            'batch_id' => (int) $batchId, 'quantity' => 1,
                            'buyer' => ['name' => "Comprador {$who}", 'email' => "buyer{$who}@example.com", 'document' => '52998224725'],
                        ]);
                }
                for ($p = 1; $p <= $pollers; $p++) {
                    $requests[] = $pool->as("painel-{$p}")->timeout(30)->withToken($token)->get("{$baseUrl}/admin/dashboard");
                }

                return $requests;
            });

            foreach ($responses as $name => $response) {
                $kind = str_starts_with($name, 'compra') ? 'compra' : 'painel';
                if ($response instanceof ConnectionException) {
                    $status["{$kind}:erro"] = ($status["{$kind}:erro"] ?? 0) + 1;

                    continue;
                }
                $status["{$kind}:{$response->status()}"] = ($status["{$kind}:{$response->status()}"] ?? 0) + 1;
                $lat[$kind][] = ($response->handlerStats()['total_time'] ?? 0) * 1000;
            }
        }

        $elapsed = microtime(true) - $started;
        $tokenModel->accessToken->delete();
        ksort($status);

        $pct = function (array $v, float $p): float {
            sort($v);

            return $v ? round($v[min(count($v) - 1, (int) floor(count($v) * $p))], 1) : 0.0;
        };

        $this->table(['Resposta', 'Qtd'], collect($status)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->table(['Latência por requisição (ms)', 'p50', 'p95', 'p99', 'n'], collect($lat)->map(
            fn ($v, $k) => [$k, $pct($v, .5), $pct($v, .95), $pct($v, .99), count($v)]
        )->values()->all());
        $this->line(sprintf('Duração total: %.1fs (%.0f compras/s)', $elapsed, $buyers / max($elapsed, 0.001)));

        $batch = Batch::find($batchId);
        $ok = ($batch->sold + $batch->reserved) <= $batch->total
            && Order::where('batch_id', $batchId)->sum('quantity') >= $batch->reserved + $batch->sold;
        $this->{$ok ? 'info' : 'error'}(sprintf(
            'Sem overselling: %s (reservado %d + vendido %d <= total %d)',
            $ok ? 'OK' : 'FALHOU', $batch->reserved, $batch->sold, $batch->total
        ));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
