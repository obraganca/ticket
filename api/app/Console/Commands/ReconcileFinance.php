<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Compara vendas PAID locais com o que o finance-sim registrou. Em operação normal,
 * "duplicadas" é sempre 0 (Idempotency-Key por operação) e "faltantes" converge a 0
 * quando o outbox termina de tentar.
 */
class ReconcileFinance extends Command
{
    protected $signature = 'reconcile:finance';

    protected $description = 'Compara pedidos PAID locais com os registros do finance-sim.';

    public function handle(): int
    {
        $response = Http::timeout(10)->get(rtrim(config('services.finance.url'), '/').'/sales');

        if ($response->failed()) {
            $this->error('finance-sim indisponível: '.$response->status());

            return self::FAILURE;
        }

        $sales = collect($response->json('data', []))->where('kind', 'sale')->pluck('order_id');
        $localIds = Order::where('status', 'paid')->pluck('id');

        $missing = $localIds->diff($sales)->values();
        $duplicated = $sales->countBy()->filter(fn ($n) => $n > 1)->keys();

        $this->info('Faltantes: '.$missing->count().' | Duplicadas: '.$duplicated->count());

        if ($missing->isNotEmpty()) {
            $this->line('IDs faltantes: '.$missing->take(20)->implode(', '));
        }

        return self::SUCCESS;
    }
}
