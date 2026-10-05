<?php

namespace Database\Factories;

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cria um pedido "solto": NÃO mexe nos contadores do lote. Nos testes que dependem de
 * contadores, use App\Actions\Orders\CreateOrder (o caminho real) ou ajuste o lote.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'quantity' => 1,
            'total_cents' => 10000,
            'status' => OrderStatus::PENDING,
            'buyer_name' => fake()->name(),
            'buyer_email' => fake()->safeEmail(),
            'buyer_document' => '52998224725',
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
