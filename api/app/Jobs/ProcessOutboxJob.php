<?php

namespace App\Jobs;

use App\Contracts\FinanceClient;
use App\Contracts\OrderNotifier;
use App\Contracts\TicketRenderer;
use App\Domain\Orders\OrderStatus;
use App\Models\OutboxJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Executa UMA linha do outbox (fonte da verdade). O retry é controlado pela linha
 * (attempts/next_run_at), não pelo Laravel Queue: `$tries = 1`.
 *
 * `$timeout` (60s) > timeout HTTP do financeiro (10s) e < retry_after da fila (90s).
 */
class ProcessOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /** Segundos que o claim "reserva" a linha; deve cobrir $timeout. */
    private const LOCK_SECONDS = 90;

    /** Reagendamento do estorno enquanto a venda ainda não foi registrada (não consome tentativas). */
    private const WAIT_FOR_SALE_SECONDS = 10;

    public function __construct(public int $outboxId) {}

    public function handle(FinanceClient $finance, TicketRenderer $renderer, OrderNotifier $notifier): void
    {
        // Claim atômico: 0 linhas => outro worker (ou o sweeper) já pegou, ou não está mais pending.
        $claimed = DB::table('outbox_jobs')
            ->where('id', $this->outboxId)
            ->where('status', OutboxJob::STATUS_PENDING)
            ->update([
                'status' => OutboxJob::STATUS_RUNNING,
                'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        /** @var OutboxJob $job */
        $job = OutboxJob::with('order.batch')->findOrFail($this->outboxId);

        try {
            $outcome = match ($job->kind) {
                OutboxJob::KIND_RECEIPT_EMAIL => $this->sendEmail($job, 'receipt', fn (string $id) => $notifier->sendReceipt($job->order, $id)),
                OutboxJob::KIND_TICKETS_EMAIL => $this->sendEmail($job, 'tickets', fn (string $id) => $notifier->sendTickets($job->order, $renderer->renderPdf($job->order), $id)),
                OutboxJob::KIND_REGISTER_FINANCE => $this->registerSale($job, $finance),
                OutboxJob::KIND_REGISTER_FINANCE_REFUND => $this->registerRefund($job, $finance),
            };

            match ($outcome) {
                OutboxJob::STATUS_CANCELLED => $job->update(['status' => OutboxJob::STATUS_CANCELLED, 'locked_until' => null]),
                'wait' => $this->waitForSale($job),
                default => $job->update(['status' => OutboxJob::STATUS_DONE, 'locked_until' => null, 'last_error' => null]),
            };
        } catch (\Throwable $e) {
            $this->scheduleRetry($job, $e);
        }
    }

    /** Falha fora do try/catch (ex.: linha inexistente): registra e deixa em failed_jobs. */
    public function failed(?\Throwable $e): void
    {
        Log::error('outbox job falhou fora do fluxo de retry', ['outbox_id' => $this->outboxId, 'error' => $e?->getMessage()]);

        // Se o worker morreu/estourou o timeout, devolve a linha para o sweeper reprocessar.
        DB::table('outbox_jobs')
            ->where('id', $this->outboxId)
            ->where('status', OutboxJob::STATUS_RUNNING)
            ->update(['status' => OutboxJob::STATUS_PENDING, 'locked_until' => null, 'last_error' => $e?->getMessage(), 'updated_at' => now()]);
    }

    /**
     * E-mail "pelo menos uma vez" com Message-ID determinístico (ver README, B14).
     * A linha de email_deliveries só conta como enviada com sent_at preenchido:
     * se o SMTP falhar, o retry reenvia; se já foi enviado, pula.
     */
    private function sendEmail(OutboxJob $job, string $kind, callable $send): string
    {
        $order = $job->order;

        // Reembolsado entre a aprovação e o processamento: não manda nada.
        if ($order->status !== OrderStatus::PAID) {
            return OutboxJob::STATUS_CANCELLED;
        }

        DB::table('email_deliveries')->insertOrIgnore([
            'order_id' => $order->id,
            'kind' => $kind,
            'message_id' => $messageId = "{$order->id}.{$kind}@codificar-ticketing.local",
            'sent_at' => null,
            'attempted_at' => now(),
        ]);

        $alreadySent = DB::table('email_deliveries')
            ->where('order_id', $order->id)->where('kind', $kind)->whereNotNull('sent_at')->exists();

        if ($alreadySent) {
            return OutboxJob::STATUS_DONE;
        }

        $send($messageId); // lança se o SMTP falhar => sent_at continua nulo => retry reenvia

        DB::table('email_deliveries')
            ->where('order_id', $order->id)->where('kind', $kind)
            ->update(['sent_at' => now()]);

        return OutboxJob::STATUS_DONE;
    }

    private function registerSale(OutboxJob $job, FinanceClient $finance): string
    {
        $order = $job->order;

        if ($order->finance_registered_at !== null) {
            return OutboxJob::STATUS_DONE;
        }

        // Chave própria da operação: o finance-sim deduplica por ela (uma venda nunca entra 2x).
        $finance->register("{$order->id}:sale", [
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
            'kind' => 'sale',
        ]);

        $order->update(['finance_registered_at' => now()]);

        return OutboxJob::STATUS_DONE;
    }

    private function registerRefund(OutboxJob $job, FinanceClient $finance): string
    {
        $order = $job->order;

        $sale = OutboxJob::where('order_id', $order->id)->where('kind', OutboxJob::KIND_REGISTER_FINANCE)->first();

        if ($sale === null || $sale->status === OutboxJob::STATUS_CANCELLED) {
            return OutboxJob::STATUS_CANCELLED; // a venda nunca foi enviada: nada a estornar
        }

        if ($sale->status !== OutboxJob::STATUS_DONE) {
            return 'wait'; // a venda ainda está em andamento/retry: o estorno só vale depois dela
        }

        $finance->register("{$order->id}:refund", [
            'order_id' => $order->id,
            'amount_cents' => -$order->total_cents,
            'kind' => 'refund',
        ]);

        return OutboxJob::STATUS_DONE;
    }

    private function waitForSale(OutboxJob $job): void
    {
        $job->update([
            'status' => OutboxJob::STATUS_PENDING,
            'next_run_at' => now()->addSeconds(self::WAIT_FOR_SALE_SECONDS),
            'locked_until' => null,
        ]);

        self::dispatch($job->id)->onQueue($job->queueName())->delay(self::WAIT_FOR_SALE_SECONDS);
    }

    private function scheduleRetry(OutboxJob $job, \Throwable $e): void
    {
        $attempts = $job->attempts + 1;
        $maxAttempts = (int) config('tickets.outbox_max_attempts', 8);

        if ($attempts >= $maxAttempts) {
            // Estado terminal visível no painel; `php artisan outbox:retry` recupera (5.4).
            $job->update([
                'status' => OutboxJob::STATUS_FAILED,
                'attempts' => $attempts,
                'locked_until' => null,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return;
        }

        $backoffSeconds = min(300, (2 ** $attempts) + random_int(0, 5)); // exponencial + jitter

        $job->update([
            'status' => OutboxJob::STATUS_PENDING,
            'attempts' => $attempts,
            'next_run_at' => now()->addSeconds($backoffSeconds),
            'locked_until' => null,
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        self::dispatch($job->id)->onQueue($job->queueName())->delay($backoffSeconds);
    }
}
