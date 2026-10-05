<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Limpa TODAS as tabelas (commits reais => não dá para contar com rollback). */
function truncateAll(): void
{
    Artisan::call('migrate', ['--force' => true]);
    DB::statement('TRUNCATE TABLE personal_access_tokens, email_deliveries, outbox_jobs, payment_events, tickets, idempotency_keys, orders, batches, ticket_types, events, users RESTART IDENTITY CASCADE');
}

/**
 * Sobe $processes processos PHP independentes; cada um executa o lote de tentativas devolvido por
 * $attemptsFor($i). Todos disparam no mesmo instante. Retorna os resultados de todas as tentativas.
 *
 * @return list<array<string, mixed>>
 */
function spawnWorkers(string $task, int $processes, callable $attemptsFor): array
{
    $startAt = microtime(true) + 1.5 + $processes * 0.02; // tempo para todos bootarem e ficarem na barreira
    $running = [];

    for ($i = 0; $i < $processes; $i++) {
        $spec = json_encode(['task' => $task, 'start_at' => $startAt, 'attempts' => $attemptsFor($i)]);
        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/concurrency_worker.php', $spec],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            array_merge(getenv(), ['DB_DATABASE' => 'ticketing_test', 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'null', 'CACHE_STORE' => 'array'])
        );
        $running[] = [$proc, $pipes];
    }

    $results = [];
    foreach ($running as [$proc, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($proc);

        if (trim($stderr) !== '') {
            throw new RuntimeException("worker falhou: $stderr");
        }
        foreach (array_filter(explode("\n", $stdout)) as $line) {
            $results[] = json_decode($line, true);
        }
    }

    return $results;
}

function tally(array $results): array
{
    return array_count_values(array_column($results, 'result'));
}
