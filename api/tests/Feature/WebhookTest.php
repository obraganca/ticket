<?php

use App\Models\Batch;
use App\Models\OutboxJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function webhookPayload(string $orderId, string $type = 'approved', ?string $eventId = null): array
{
    return ['event_id' => $eventId ?? (string) Str::uuid(), 'order_id' => $orderId, 'type' => $type, 'occurred_at' => now()->toIso8601String()];
}

beforeEach(function () {
    $this->batch = Batch::factory()->create(['total' => 10]);
    $this->order = placeOrder($this->batch, 2);
});

it('T9: o webhook só valida, grava e enfileira: nenhum e-mail, PDF ou chamada financeira na request', function () {
    Mail::fake();
    Queue::fake();
    $fakes = fakeInfra();

    signedWebhook($this, webhookPayload($this->order->id))->assertOk()->assertJson(['ok' => true]);

    expect($fakes['finance']->calls)->toBe([])->and($fakes['renderer']->renders)->toBe(0)->and($fakes['notifier']->attempts)->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    // o trabalho pesado ficou registrado no outbox para os workers
    expect(OutboxJob::where('order_id', $this->order->id)->count())->toBe(3);
    expect(Ticket::where('order_id', $this->order->id)->count())->toBe(2);
});

it('T9: assinatura inválida ou ausente => 401 e nada é gravado', function () {
    $payload = webhookPayload($this->order->id);

    signedWebhook($this, $payload, 'assinatura-errada')->assertStatus(401);
    $this->postJson('/api/v1/webhooks/payments', $payload)->assertStatus(401);

    $this->assertDatabaseCount('payment_events', 0);
    expect($this->order->fresh()->status->value)->toBe('pending');
});

it('T9/B22: order_id que não é UUID => 422 (e não 500)', function () {
    signedWebhook($this, webhookPayload('123'))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('T9/B22: valida event_id, type e occurred_at', function () {
    $ok = webhookPayload($this->order->id);

    signedWebhook($this, array_merge($ok, ['event_id' => str_repeat('x', 101)]))->assertStatus(422);
    signedWebhook($this, array_merge($ok, ['type' => 'chargeback']))->assertStatus(422);
    signedWebhook($this, array_merge($ok, ['occurred_at' => 'ontem']))->assertStatus(422);
    signedWebhook($this, array_diff_key($ok, ['event_id' => 1]))->assertStatus(422);
    $this->assertDatabaseCount('payment_events', 0);
});

it('T9: pedido inexistente => 404 sem gravar o evento', function () {
    signedWebhook($this, webhookPayload((string) Str::uuid()))->assertNotFound();

    $this->assertDatabaseCount('payment_events', 0);
});

it('T3: mesmo aviso reenviado várias vezes (HTTP) => 1 emissão, 3 jobs; 200 em todas', function () {
    $payload = webhookPayload($this->order->id);

    foreach (range(1, 4) as $_) {
        signedWebhook($this, $payload)->assertOk();
    }

    expect(Ticket::where('order_id', $this->order->id)->count())->toBe(2);
    expect(OutboxJob::where('order_id', $this->order->id)->count())->toBe(3);
    $this->assertDatabaseCount('payment_events', 1);
});

it('T3: duplicado => 1 chamada ao financeiro e 2 e-mails no total', function () {
    $fakes = fakeInfra();
    $payload = webhookPayload($this->order->id);
    foreach (range(1, 5) as $_) {
        signedWebhook($this, $payload)->assertOk();
    }

    drainOutbox();

    expect($fakes['finance']->calls)->toHaveCount(1)->and($fakes['finance']->count('sale'))->toBe(1);
    expect($fakes['notifier']->sent)->toHaveCount(2);
    expect($fakes['notifier']->sentCount('receipt'))->toBe(1)->and($fakes['notifier']->sentCount('tickets'))->toBe(1);
});

it('webhooks refunded/declined pelo HTTP seguem a máquina de estados', function () {
    signedWebhook($this, webhookPayload($this->order->id, 'declined'))->assertOk();
    expect($this->order->fresh()->status->value)->toBe('declined');
    expect($this->batch->fresh()->reserved)->toBe(0);
});
