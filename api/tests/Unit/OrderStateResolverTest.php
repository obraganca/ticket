<?php

use App\Domain\Orders\OrderStateResolver;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\PaymentEventType;

it('moves pending to paid on approved', function () {
    expect(OrderStateResolver::resolve(OrderStatus::PENDING, PaymentEventType::APPROVED))
        ->toBe(OrderStatus::PAID);
});

it('moves pending to declined on declined', function () {
    expect(OrderStateResolver::resolve(OrderStatus::PENDING, PaymentEventType::DECLINED))
        ->toBe(OrderStatus::DECLINED);
});

it('moves paid to refunded on refunded', function () {
    expect(OrderStateResolver::resolve(OrderStatus::PAID, PaymentEventType::REFUNDED))
        ->toBe(OrderStatus::REFUNDED);
});

it('refunded is absorbing: nothing moves it away, even an approved arriving late', function () {
    expect(OrderStateResolver::resolve(OrderStatus::REFUNDED, PaymentEventType::APPROVED))
        ->toBe(OrderStatus::REFUNDED);
    expect(OrderStateResolver::resolve(OrderStatus::REFUNDED, PaymentEventType::DECLINED))
        ->toBe(OrderStatus::REFUNDED);
});

it('out-of-order delivery still lands on refunded (P5 core case)', function () {
    // Gateway entrega "refunded" antes de "approved" (item 4.2: fora de ordem).
    $afterRefund = OrderStateResolver::resolve(OrderStatus::PENDING, PaymentEventType::REFUNDED);
    expect($afterRefund)->toBe(OrderStatus::REFUNDED);

    $afterLateApprove = OrderStateResolver::resolve($afterRefund, PaymentEventType::APPROVED);
    expect($afterLateApprove)->toBe(OrderStatus::REFUNDED);
});

it('duplicate approved on an already-paid order is a no-op', function () {
    expect(OrderStateResolver::resolve(OrderStatus::PAID, PaymentEventType::APPROVED))
        ->toBe(OrderStatus::PAID);
});

it('declined cannot move a paid order', function () {
    expect(OrderStateResolver::resolve(OrderStatus::PAID, PaymentEventType::DECLINED))
        ->toBe(OrderStatus::PAID);
});

it('every permutation of duplicated/out-of-order events for a fixed final outcome converges', function () {
    // approved + refunded, em qualquer ordem, sempre termina em REFUNDED (P5).
    $orderings = [
        [PaymentEventType::APPROVED, PaymentEventType::REFUNDED],
        [PaymentEventType::REFUNDED, PaymentEventType::APPROVED],
        [PaymentEventType::APPROVED, PaymentEventType::APPROVED, PaymentEventType::REFUNDED],
        [PaymentEventType::REFUNDED, PaymentEventType::REFUNDED, PaymentEventType::APPROVED],
    ];

    foreach ($orderings as $events) {
        $state = OrderStatus::PENDING;
        foreach ($events as $event) {
            $state = OrderStateResolver::resolve($state, $event);
        }
        expect($state)->toBe(OrderStatus::REFUNDED);
    }
});

it('sortEvents breaks ties with refunded > declined > approved', function () {
    $now = now()->toIso8601String();
    $sorted = OrderStateResolver::sortEvents([
        ['type' => 'approved', 'occurred_at' => $now],
        ['type' => 'refunded', 'occurred_at' => $now],
        ['type' => 'declined', 'occurred_at' => $now],
    ]);

    expect(array_column($sorted, 'type'))->toBe(['refunded', 'declined', 'approved']);
});
