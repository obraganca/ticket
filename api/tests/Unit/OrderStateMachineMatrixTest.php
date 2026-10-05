<?php

use App\Domain\Orders\OrderStateResolver;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\PaymentEventType as E;

// Matriz completa: estado atual x aviso do gateway => estado resultante da FUNÇÃO PURA.
// (expired + approved é decidido em ApplyPaymentEvent, que depende de estoque: ver OutOfOrderEventsTest.)
dataset('matrix', [
    'pending+approved' => ['pending', E::APPROVED, 'paid'],
    'pending+declined' => ['pending', E::DECLINED, 'declined'],
    'pending+refunded' => ['pending', E::REFUNDED, 'refunded'],
    'paid+approved' => ['paid', E::APPROVED, 'paid'],
    'paid+declined' => ['paid', E::DECLINED, 'paid'],
    'paid+refunded' => ['paid', E::REFUNDED, 'refunded'],
    'declined+approved' => ['declined', E::APPROVED, 'declined'],
    'declined+declined' => ['declined', E::DECLINED, 'declined'],
    'declined+refunded' => ['declined', E::REFUNDED, 'declined'],
    'expired+approved' => ['expired', E::APPROVED, 'expired'],
    'expired+declined' => ['expired', E::DECLINED, 'expired'],
    'expired+refunded' => ['expired', E::REFUNDED, 'expired'],
    'refund_required+approved' => ['refund_required', E::APPROVED, 'refund_required'],
    'refund_required+declined' => ['refund_required', E::DECLINED, 'refund_required'],
    'refund_required+refunded' => ['refund_required', E::REFUNDED, 'refunded'],
    'refunded+approved' => ['refunded', E::APPROVED, 'refunded'],
    'refunded+declined' => ['refunded', E::DECLINED, 'refunded'],
    'refunded+refunded' => ['refunded', E::REFUNDED, 'refunded'],
]);

it('T8: transição resultante para cada par estado x aviso', function (string $from, E $event, string $expected) {
    expect(OrderStateResolver::resolve(OrderStatus::from($from), $event)->value)->toBe($expected);
})->with('matrix');

it('T8: isAllowed aceita só as transições válidas (e no-op idempotente)', function () {
    $valid = [
        ['pending', 'paid'], ['pending', 'declined'], ['pending', 'expired'], ['pending', 'refunded'], ['pending', 'refund_required'],
        ['paid', 'refunded'], ['expired', 'paid'], ['expired', 'refund_required'], ['refund_required', 'refunded'],
    ];
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            $expected = $from === $to || in_array([$from->value, $to->value], $valid, true);
            expect(OrderStateResolver::isAllowed($from, $to))->toBe($expected, "{$from->value} -> {$to->value}");
        }
    }
});

it('T8: REFUNDED é absorvente e DECLINED é terminal', function () {
    foreach (OrderStatus::cases() as $to) {
        if ($to !== OrderStatus::REFUNDED) {
            expect(OrderStateResolver::isAllowed(OrderStatus::REFUNDED, $to))->toBeFalse();
        }
        if ($to !== OrderStatus::DECLINED) {
            expect(OrderStateResolver::isAllowed(OrderStatus::DECLINED, $to))->toBeFalse();
        }
    }
});
