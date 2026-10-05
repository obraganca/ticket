<?php

namespace App\Console\Commands;

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Roda a cada 10-60s no scheduler (P8). Usa FOR UPDATE SKIP LOCKED para
 * que múltiplas instâncias deste comando (ou do scheduler em réplicas) nunca
 * devolvam o mesmo estoque duas vezes: cada linha só é pega por uma delas.
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire {--limit=200}';

    protected $description = 'Expira pedidos PENDING vencidos e devolve o estoque reservado (P8).';

    public function handle(): int
    {
        $expired = 0;

        DB::transaction(function () use (&$expired) {
            $orders = Order::where('status', OrderStatus::PENDING->value)
                ->where('expires_at', '<', now())
                ->lock('for update skip locked') // Eloquent não tem skipLocked(): usa a cláusula crua
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($orders as $order) {
                // Mesma transação: o status sai de PENDING junto com a devolução, então
                // uma 2ª execução não encontra o pedido e nunca devolve duas vezes.
                Batch::where('id', $order->batch_id)->update([
                    'reserved' => DB::raw('reserved - '.(int) $order->quantity),
                ]);
                $order->update(['status' => OrderStatus::EXPIRED]);
                $expired++;
            }
        });

        $this->info("Pedidos expirados: {$expired}");

        return self::SUCCESS;
    }
}
