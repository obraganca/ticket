<?php

use App\Actions\Orders\ApplyPaymentEvent;
use App\Actions\Orders\CreateOrder;
use App\Contracts\FinanceClient;
use App\Contracts\OrderNotifier;
use App\Contracts\TicketRenderer;
use App\Domain\Orders\PaymentEventType;
use App\Jobs\ProcessOutboxJob;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OutboxJob;
use Illuminate\Support\Str;
use Tests\Support\FakeFinanceClient;
use Tests\Support\FakeNotifier;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

const TEST_CPF = '52998224725';

function buyerPayload(int $batchId, int $quantity = 1, array $extra = []): array
{
    return [
        'batch_id' => $batchId,
        'quantity' => $quantity,
        'buyer' => ['name' => 'Maria Souza', 'email' => 'maria@example.com', 'document' => TEST_CPF],
    ] + $extra;
}

/** Cria um pedido PENDING pelo caminho REAL (reserva atômica + contadores do lote). */
function placeOrder(Batch $batch, int $quantity = 1, ?string $key = null): Order
{
    return app(CreateOrder::class)->execute($key ?? (string) Str::uuid(), buyerPayload($batch->id, $quantity))['order'];
}

/** Aplica um aviso do gateway direto na Action (sem HTTP). */
function gatewayEvent(Order|string $order, string $type, ?string $eventId = null, ?string $occurredAt = null): string
{
    $eventId ??= (string) Str::uuid();
    app(ApplyPaymentEvent::class)->execute(
        $eventId,
        $order instanceof Order ? $order->id : $order,
        PaymentEventType::from($type),
        new DateTimeImmutable($occurredAt ?? 'now'),
    );

    return $eventId;
}

/** POST assinado (HMAC do corpo bruto) no webhook, como o gateway faria. */
function signedWebhook(TestCase $test, array $payload, ?string $signature = null)
{
    $body = json_encode($payload);

    return $test->call('POST', '/api/v1/webhooks/payments', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'test-secret'),
    ], $body);
}

/** @return array{finance: FakeFinanceClient, notifier: FakeNotifier, renderer: FakeRenderer} */
function fakeInfra(): array
{
    $fakes = ['finance' => new FakeFinanceClient, 'notifier' => new FakeNotifier, 'renderer' => new FakeRenderer];
    app()->instance(FinanceClient::class, $fakes['finance']);
    app()->instance(OrderNotifier::class, $fakes['notifier']);
    app()->instance(TicketRenderer::class, $fakes['renderer']);

    return $fakes;
}

/** Executa o job do outbox exatamente como o worker faria (claim atômico incluso). */
function runOutboxJob(OutboxJob|int $job): OutboxJob
{
    $id = $job instanceof OutboxJob ? $job->id : $job;
    (new ProcessOutboxJob($id))->handle(app(FinanceClient::class), app(TicketRenderer::class), app(OrderNotifier::class));

    return OutboxJob::findOrFail($id);
}

/** Processa todos os jobs `pending` (ignora next_run_at: o teste controla o "relógio"). */
function drainOutbox(int $maxRounds = 20): void
{
    for ($i = 0; $i < $maxRounds; $i++) {
        $pending = OutboxJob::where('status', OutboxJob::STATUS_PENDING)->pluck('id');
        if ($pending->isEmpty()) {
            return;
        }
        foreach ($pending as $id) {
            runOutboxJob($id);
        }
    }
}

function outboxJob(Order|string $order, string $kind): ?OutboxJob
{
    return OutboxJob::where('order_id', $order instanceof Order ? $order->id : $order)->where('kind', $kind)->first();
}
