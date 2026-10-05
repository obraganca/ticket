<?php

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OutboxJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** Estado do lote como tupla legível: [reserved, sold, revenue]. */
function counters(Batch $batch): array
{
    $b = $batch->fresh();

    return [$b->reserved, $b->sold, $b->revenue_cents];
}

function effects(Order $order): array
{
    return [
        'tickets' => Ticket::where('order_id', $order->id)->count(),
        'jobs' => OutboxJob::where('order_id', $order->id)->count(),
    ];
}

beforeEach(function () {
    $this->batch = Batch::factory()->create(['total' => 10, 'price_cents' => 1000]);
});

it('T4/B23: refunded ANTES de approved (pending) => REFUNDED, devolve reserva; approved tardio é ignorado', function () {
    $order = placeOrder($this->batch, 2);
    expect(counters($this->batch))->toBe([2, 0, 0]);

    gatewayEvent($order, 'refunded', occurredAt: '2026-01-01T10:00:10Z');
    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED);
    expect(counters($this->batch))->toBe([0, 0, 0]);

    gatewayEvent($order, 'approved', occurredAt: '2026-01-01T10:00:00Z'); // aconteceu antes, chegou depois

    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED);
    expect(counters($this->batch))->toBe([0, 0, 0]);
    expect(effects($order))->toBe(['tickets' => 0, 'jobs' => 0]); // sem ingressos, e-mails nem financeiro
});

it('T4/B23: approved após declined é ignorado', function () {
    $order = placeOrder($this->batch);
    gatewayEvent($order, 'declined');
    expect(counters($this->batch))->toBe([0, 0, 0]);

    gatewayEvent($order, 'approved');

    expect($order->fresh()->status)->toBe(OrderStatus::DECLINED);
    expect(counters($this->batch))->toBe([0, 0, 0]);
    expect(effects($order))->toBe(['tickets' => 0, 'jobs' => 0]);
});

it('T4/B23: approved após expired COM estoque => reserva de novo e vira PAID', function () {
    $order = placeOrder($this->batch, 3);
    $order->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::EXPIRED);
    expect(counters($this->batch))->toBe([0, 0, 0]);

    gatewayEvent($order, 'approved');

    expect($order->fresh()->status)->toBe(OrderStatus::PAID);
    expect(counters($this->batch))->toBe([0, 3, 3000]);
    expect(effects($order))->toBe(['tickets' => 3, 'jobs' => 3]);
});

it('T4/B23: approved após expired SEM estoque => REFUND_REQUIRED, sem ingressos, sem overselling', function () {
    $late = placeOrder($this->batch, 6);
    $late->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire')->assertSuccessful();

    placeOrder($this->batch, 8); // outro comprador leva o estoque liberado
    expect(counters($this->batch))->toBe([8, 0, 0]);

    gatewayEvent($late, 'approved');

    expect($late->fresh()->status)->toBe(OrderStatus::REFUND_REQUIRED);
    expect(counters($this->batch))->toBe([8, 0, 0]); // nenhum contador mudou
    expect(effects($late))->toBe(['tickets' => 0, 'jobs' => 0]);
});

it('T4/B23: refunded em pedido REFUND_REQUIRED => REFUNDED, sem mexer no estoque', function () {
    $late = placeOrder($this->batch, 6);
    $late->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire');
    placeOrder($this->batch, 8);
    gatewayEvent($late, 'approved');

    gatewayEvent($late, 'refunded');

    expect($late->fresh()->status)->toBe(OrderStatus::REFUNDED);
    expect(counters($this->batch))->toBe([8, 0, 0]);
});

it('T4/B23: refunded em pedido expired ou declined (pagamento nunca confirmado) registra o evento e NÃO muda o estado', function () {
    $expired = placeOrder($this->batch);
    $expired->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire');
    $declined = placeOrder($this->batch);
    gatewayEvent($declined, 'declined');

    $eventA = gatewayEvent($expired, 'refunded');
    $eventB = gatewayEvent($declined, 'refunded');

    expect($expired->fresh()->status)->toBe(OrderStatus::EXPIRED);
    expect($declined->fresh()->status)->toBe(OrderStatus::DECLINED);
    expect(counters($this->batch))->toBe([0, 0, 0]);
    $this->assertDatabaseHas('payment_events', ['event_id' => $eventA, 'type' => 'refunded']);
    $this->assertDatabaseHas('payment_events', ['event_id' => $eventB, 'type' => 'refunded']);
});

it('T4: approved repetido com event_id DIFERENTE em pedido pago é no-op (não emite de novo)', function () {
    $order = placeOrder($this->batch, 2);
    gatewayEvent($order, 'approved');
    gatewayEvent($order, 'approved'); // outro event_id

    expect(effects($order))->toBe(['tickets' => 2, 'jobs' => 3]);
    expect(counters($this->batch))->toBe([0, 2, 2000]);
});

it('T4: mesmo event_id repetido não tem efeito nenhum', function () {
    $order = placeOrder($this->batch);
    $id = gatewayEvent($order, 'approved');
    foreach (range(1, 4) as $_) {
        gatewayEvent($order, 'approved', eventId: $id);
    }

    expect(effects($order))->toBe(['tickets' => 1, 'jobs' => 3]);
    expect(DB::table('payment_events')->count())->toBe(1);
});
