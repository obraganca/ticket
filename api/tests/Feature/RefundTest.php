<?php

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\OutboxJob;
use App\Models\Ticket;

beforeEach(function () {
    $this->batch = Batch::factory()->create(['total' => 10, 'price_cents' => 1000]);
});

it('T5: reembolso devolve estoque UMA vez, invalida ingressos e não reprocessa com outro event_id', function () {
    $order = placeOrder($this->batch, 3);
    gatewayEvent($order, 'approved');
    expect($this->batch->fresh()->only(['reserved', 'sold', 'revenue_cents']))->toBe(['reserved' => 0, 'sold' => 3, 'revenue_cents' => 3000]);

    gatewayEvent($order, 'refunded');
    gatewayEvent($order, 'refunded'); // outro event_id: no-op (REFUNDED é absorvente)

    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED);
    expect($this->batch->fresh()->only(['reserved', 'sold', 'revenue_cents']))->toBe(['reserved' => 0, 'sold' => 0, 'revenue_cents' => 0]);
    expect(Ticket::where('order_id', $order->id)->where('status', Ticket::STATUS_INVALIDATED)->count())->toBe(3);
    expect(Ticket::where('order_id', $order->id)->where('status', Ticket::STATUS_ACTIVE)->count())->toBe(0);
});

it('T5: cancela e-mails pendentes e cria o estorno com chave {order}:refund depois da venda concluída', function () {
    $fakes = fakeInfra();
    $order = placeOrder($this->batch);
    gatewayEvent($order, 'approved');
    runOutboxJob(outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE)); // venda registrada

    gatewayEvent($order, 'refunded');

    expect(outboxJob($order, OutboxJob::KIND_RECEIPT_EMAIL)->status)->toBe('cancelled');
    expect(outboxJob($order, OutboxJob::KIND_TICKETS_EMAIL)->status)->toBe('cancelled');

    drainOutbox();
    expect(array_keys($fakes['finance']->registered))->toEqualCanonicalizing(["{$order->id}:sale", "{$order->id}:refund"]);
    expect($fakes['notifier']->sent)->toBe([]); // nenhum e-mail saiu para um pedido reembolsado
});

it('T5/B17: reembolso com a venda `running` cria o estorno, que espera a venda terminar', function () {
    $fakes = fakeInfra();
    $order = placeOrder($this->batch);
    gatewayEvent($order, 'approved');
    $sale = outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE);
    $sale->update(['status' => 'running', 'attempts' => 1]); // worker no meio da chamada lenta

    gatewayEvent($order, 'refunded');

    $refund = outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE_REFUND);
    expect($refund)->not->toBeNull()->and($sale->fresh()->status)->toBe('running');

    // O estorno roda antes da venda terminar: NÃO chama o financeiro e não gasta tentativa.
    $refund = runOutboxJob($refund);
    expect($refund->status)->toBe('pending')->and($refund->attempts)->toBe(0);
    expect($fakes['finance']->calls)->toBe([]);

    // A venda conclui; só então o estorno vai.
    $sale->update(['status' => 'pending']);
    runOutboxJob($sale);
    runOutboxJob($refund);
    expect(array_keys($fakes['finance']->registered))->toEqualCanonicalizing(["{$order->id}:sale", "{$order->id}:refund"]);
});

it('T5/B17: venda pending nunca tentada é apenas cancelada (nada a estornar)', function () {
    $fakes = fakeInfra();
    $order = placeOrder($this->batch);
    gatewayEvent($order, 'approved');

    gatewayEvent($order, 'refunded');

    expect(outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE)->status)->toBe('cancelled');
    expect(outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE_REFUND))->toBeNull();
    drainOutbox();
    expect($fakes['finance']->calls)->toBe([]);
});

it('T5/B17: venda pending já tentada (resposta perdida) também gera estorno', function () {
    fakeInfra();
    $order = placeOrder($this->batch);
    gatewayEvent($order, 'approved');
    outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE)->update(['attempts' => 2]);

    gatewayEvent($order, 'refunded');

    expect(outboxJob($order, OutboxJob::KIND_REGISTER_FINANCE_REFUND))->not->toBeNull();
});
