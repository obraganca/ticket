<?php

namespace App\Actions\Orders;

use App\Domain\Orders\OrderStateResolver;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\PaymentEventType;
use App\Jobs\ProcessOutboxJob;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OutboxJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Executa em UMA transação curta, bem abaixo do timeout de 3s do gateway
 * (item 4.2): nunca chama financeiro/e-mail/PDF aqui dentro — só decide o
 * estado e grava jobs no outbox (P1, P6). Retorna rápido mesmo sob carga.
 */
class ApplyPaymentEvent
{
    public function execute(string $eventId, string $orderId, PaymentEventType $type, \DateTimeInterface $occurredAt, ?array $rawPayload = null): void
    {
        DB::transaction(function () use ($eventId, $orderId, $type, $occurredAt, $rawPayload) {
            // Dedup do próprio aviso: PK insertOrIgnore. Reenvio do MESMO
            // event_id (item 4.2) não produz nenhum efeito adicional.
            $inserted = DB::table('payment_events')->insertOrIgnore([
                'event_id' => $eventId,
                'order_id' => $orderId,
                'type' => $type->value,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'payload' => $rawPayload ? json_encode($rawPayload) : null,
            ]);

            if ($inserted === 0) {
                return; // aviso duplicado, nada a fazer (idempotente)
            }

            /** @var Order $order */
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $batch = Batch::where('id', $order->batch_id)->lockForUpdate()->firstOrFail();

            $from = $order->status;
            $to = $this->resolveTarget($order, $type);

            if ($to === $from) {
                return; // no-op idempotente (ex.: approved chegando de novo)
            }

            $this->applyCounters($batch, $from, $to, $order->quantity, $order->total_cents);
            $order->status = $to;
            $order->save();

            match ($to) {
                OrderStatus::PAID => $this->onPaid($order),
                OrderStatus::REFUNDED => $this->onRefunded($order),
                default => null,
            };
        });
    }

    private function resolveTarget(Order $order, PaymentEventType $type): OrderStatus
    {
        // Caso especial: pedido EXPIRED recebendo "approved" tardiamente
        // (pagamento chegou depois do prazo). Tenta reservar de novo; se não
        // houver mais estoque, vai para REFUND_REQUIRED em vez de travar.
        if ($order->status === OrderStatus::EXPIRED && $type === PaymentEventType::APPROVED) {
            $batch = Batch::where('id', $order->batch_id)
                ->where(DB::raw('total - reserved - sold'), '>=', $order->quantity)
                ->lockForUpdate()
                ->exists();

            return $batch ? OrderStatus::PAID : OrderStatus::REFUND_REQUIRED;
        }

        return OrderStateResolver::resolve($order->status, $type);
    }

    private function applyCounters(Batch $batch, OrderStatus $from, OrderStatus $to, int $qty, int $totalCents): void
    {
        if ($from === OrderStatus::PENDING && $to === OrderStatus::PAID) {
            $batch->reserved -= $qty;
            $batch->sold += $qty;
            $batch->revenue_cents += $totalCents;
        } elseif ($from === OrderStatus::EXPIRED && $to === OrderStatus::PAID) {
            $batch->reserved += $qty; // reserva de novo antes de vender (caso tardio)
            $batch->reserved -= $qty;
            $batch->sold += $qty;
            $batch->revenue_cents += $totalCents;
        } elseif ($from === OrderStatus::PENDING && in_array($to, [OrderStatus::DECLINED, OrderStatus::EXPIRED, OrderStatus::REFUNDED], true)) {
            $batch->reserved -= $qty;
        } elseif ($from === OrderStatus::PAID && $to === OrderStatus::REFUNDED) {
            $batch->sold -= $qty;
            $batch->revenue_cents -= $totalCents;
        }
        // EXPIRED/PENDING -> REFUND_REQUIRED: nenhum contador muda (nunca houve reserva ativa).

        $batch->save();
    }

    private function onPaid(Order $order): void
    {
        $holders = $order->ticket_holders ?? [];

        foreach (range(0, $order->quantity - 1) as $index) {
            $holder = $holders[$index] ?? null;

            Ticket::create([
                'order_id' => $order->id,
                'code' => Ticket::generateCode(),
                'status' => Ticket::STATUS_ACTIVE,
                // Sem titulares informados, o titular é o comprador (decisão de produto 7).
                'holder_name' => $holder['name'] ?? $order->buyer_name,
                'holder_email' => $holder['email'] ?? $order->buyer_email,
            ]);
        }

        foreach ([OutboxJob::KIND_RECEIPT_EMAIL, OutboxJob::KIND_TICKETS_EMAIL, OutboxJob::KIND_REGISTER_FINANCE] as $kind) {
            $this->enqueue($order, $kind);
        }
    }

    /**
     * Reembolso. Ordem de decisão sobre o financeiro (B17):
     *  - venda `running`/`done`, ou `pending` já tentada (attempts > 0, o financeiro pode ter
     *    gravado e a resposta se perdido): cria o job de ESTORNO. Ele só executa depois que a
     *    venda estiver de fato registrada (ver ProcessOutboxJob).
     *  - venda `pending` nunca tentada: nada foi enviado, apenas cancela.
     */
    private function onRefunded(Order $order): void
    {
        Ticket::where('order_id', $order->id)->update(['status' => Ticket::STATUS_INVALIDATED]);

        OutboxJob::where('order_id', $order->id)
            ->whereIn('kind', [OutboxJob::KIND_RECEIPT_EMAIL, OutboxJob::KIND_TICKETS_EMAIL])
            ->where('status', OutboxJob::STATUS_PENDING)
            ->update(['status' => OutboxJob::STATUS_CANCELLED]);

        $sale = OutboxJob::where('order_id', $order->id)
            ->where('kind', OutboxJob::KIND_REGISTER_FINANCE)
            ->lockForUpdate()
            ->first();

        if ($sale === null) {
            return; // pedido nunca foi pago: não há venda para estornar
        }

        $neverAttempted = $sale->status === OutboxJob::STATUS_PENDING && $sale->attempts === 0;

        if ($neverAttempted) {
            $sale->update(['status' => OutboxJob::STATUS_CANCELLED]);

            return;
        }

        if ($sale->status !== OutboxJob::STATUS_CANCELLED) {
            $this->enqueue($order, OutboxJob::KIND_REGISTER_FINANCE_REFUND);
        }
    }

    /** UNIQUE(order_id, kind) garante 1 job por tipo mesmo com webhook duplicado concorrente. */
    private function enqueue(Order $order, string $kind): void
    {
        $job = OutboxJob::firstOrCreate(
            ['order_id' => $order->id, 'kind' => $kind],
            ['status' => OutboxJob::STATUS_PENDING]
        );

        // Dispatch só depois do COMMIT. Se o Redis cair aqui, a linha do outbox já
        // existe e o sweeper agendado a recupera (nenhuma venda se perde).
        DB::afterCommit(fn () => ProcessOutboxJob::dispatch($job->id)->onQueue($job->queueName()));
    }
}
