<?php

namespace App\Domain\Orders;

use Carbon\CarbonImmutable;

/**
 * Função pura, sem I/O: dado o estado atual do pedido e o tipo do evento de
 * pagamento que chegou, decide o estado-alvo. Não depende da ordem física de
 * chegada do webhook — só do que o pedido já era.
 *
 * Regras de negócio (P5 - "pedido reembolsado continuou aparecendo como pago"):
 *  - REFUNDED é absorvente: uma vez visto (mesmo que o "approved" chegue
 *    DEPOIS do "refunded" por causa de reentrega fora de ordem do gateway),
 *    o pedido nunca mais sai desse estado e nada é emitido/enviado depois.
 *  - "approved" só move PENDING -> PAID. Em qualquer outro estado atual é
 *    um no-op idempotente (cobre reenvio duplicado do mesmo aviso e envios
 *    tardios após declined/expired, que são tratados à parte pois podem
 *    precisar tentar reservar estoque de novo — ver 4.3 no domínio de Order).
 *  - "declined" só move PENDING -> DECLINED.
 *  - Em caso de eventos com o mesmo occurred_at (empate) ao reconstruir o
 *    histórico completo, o desempate documentado é:
 *    refunded > declined > approved (o mais definitivo vence).
 */
final class OrderStateResolver
{
    /** @var array<string, array<string>> transições permitidas a partir de cada estado */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['paid', 'declined', 'expired', 'refund_required', 'refunded'],
        'paid' => ['refunded'],
        'declined' => [],
        'expired' => ['paid', 'refund_required'], // pagamento tardio, ver Actions\Orders\ApplyPaymentEvent
        'refund_required' => ['refunded'],
        'refunded' => [], // absorvente
    ];

    public static function resolve(OrderStatus $current, PaymentEventType $incoming): OrderStatus
    {
        if ($current === OrderStatus::REFUNDED) {
            return OrderStatus::REFUNDED;
        }

        $target = match ($incoming) {
            PaymentEventType::REFUNDED => OrderStatus::REFUNDED,
            PaymentEventType::DECLINED => $current === OrderStatus::PENDING
                ? OrderStatus::DECLINED
                : $current,
            PaymentEventType::APPROVED => $current === OrderStatus::PENDING
                ? OrderStatus::PAID
                : $current,
        };

        return self::isAllowed($current, $target) ? $target : $current;
    }

    public static function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        if ($from === $to) {
            return true; // no-op idempotente (webhook duplicado)
        }

        return in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Ordena uma lista de eventos [type, occurred_at] com o critério de
     * desempate documentado acima. Usado para reprocessar/reconciliar o
     * histórico completo de um pedido de forma determinística.
     *
     * @param  array<int, array{type: string, occurred_at: string}>  $events
     * @return array<int, array{type: string, occurred_at: string}>
     */
    public static function sortEvents(array $events): array
    {
        $rank = [
            PaymentEventType::REFUNDED->value => 0,
            PaymentEventType::DECLINED->value => 1,
            PaymentEventType::APPROVED->value => 2,
        ];

        usort($events, function ($a, $b) use ($rank) {
            $ta = CarbonImmutable::parse($a['occurred_at']);
            $tb = CarbonImmutable::parse($b['occurred_at']);
            if (! $ta->equalTo($tb)) {
                return $ta <=> $tb;
            }

            return $rank[$a['type']] <=> $rank[$b['type']];
        });

        return $events;
    }
}
