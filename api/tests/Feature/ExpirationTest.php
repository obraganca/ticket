<?php

use App\Actions\Orders\SoldOutException;
use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\Order;

beforeEach(function () {
    $this->batch = Batch::factory()->create(['total' => 10]);
});

it('T6/B12: pedido pending vencido volta ao estoque', function () {
    $order = placeOrder($this->batch, 4);
    $order->update(['expires_at' => now()->subMinute()]);

    $this->artisan('orders:expire')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::EXPIRED);
    expect($this->batch->fresh()->reserved)->toBe(0);
});

it('T6: pedido ainda dentro do prazo não expira', function () {
    $order = placeOrder($this->batch, 2);
    $this->artisan('orders:expire')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);
    expect($this->batch->fresh()->reserved)->toBe(2);
});

it('T6: pedido pago nunca expira, mesmo vencido', function () {
    $order = placeOrder($this->batch, 2);
    gatewayEvent($order, 'approved');
    $order->update(['expires_at' => now()->subHour()]);

    $this->artisan('orders:expire')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::PAID);
    expect($this->batch->fresh()->sold)->toBe(2);
});

it('T6: rodar orders:expire 2 vezes não devolve 2 vezes', function () {
    $a = placeOrder($this->batch, 3);
    placeOrder($this->batch, 2); // continua válido
    $a->update(['expires_at' => now()->subMinute()]);

    $this->artisan('orders:expire')->assertSuccessful();
    $this->artisan('orders:expire')->assertSuccessful();

    expect($this->batch->fresh()->reserved)->toBe(2);
    expect(Order::where('status', OrderStatus::EXPIRED)->count())->toBe(1);
});

it('T6: o estoque liberado pode ser vendido de novo', function () {
    $full = placeOrder($this->batch, 6);
    placeOrder($this->batch, 4);
    expect(fn () => placeOrder($this->batch, 1))->toThrow(SoldOutException::class);

    $full->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire');

    expect(placeOrder($this->batch, 6)->quantity)->toBe(6);
});
