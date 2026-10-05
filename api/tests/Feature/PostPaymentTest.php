<?php

use App\Jobs\ProcessOutboxJob;
use App\Models\Batch;
use App\Models\EmailDelivery;
use App\Models\OutboxJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->batch = Batch::factory()->create(['total' => 10]);
    $this->fakes = fakeInfra();
    $this->order = placeOrder($this->batch, 2);
    gatewayEvent($this->order, 'approved');
});

it('T7: financeiro falha 4 vezes e depois funciona => exatamente 1 registro', function () {
    config(['tickets.outbox_max_attempts' => 8]);
    $this->fakes['finance']->failNext(4, afterRegister: true); // pior caso: gravou e a resposta se perdeu
    $job = outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE);

    foreach (range(1, 4) as $i) {
        $job = runOutboxJob($job);
        expect($job->status)->toBe('pending')->and($job->attempts)->toBe($i)->and($job->last_error)->not->toBeNull();
    }
    $job = runOutboxJob($job);

    expect($job->status)->toBe('done');
    expect($this->fakes['finance']->count('sale'))->toBe(1);
    expect($this->order->fresh()->finance_registered_at)->not->toBeNull();

    runOutboxJob($job); // job repetido depois de concluído: não chama o financeiro
    expect($this->fakes['finance']->calls)->toHaveCount(5);
});

it('T7/B14: e-mail falha e depois funciona => ENVIADO uma única vez (regressão)', function () {
    $this->fakes['notifier']->failNext(2);
    $job = outboxJob($this->order, OutboxJob::KIND_RECEIPT_EMAIL);

    $job = runOutboxJob($job);
    expect($job->status)->toBe('pending')->and($this->fakes['notifier']->sent)->toBe([]);
    expect(EmailDelivery::first()->sent_at)->toBeNull(); // tentativa registrada, mas NÃO conta como enviado

    $job = runOutboxJob($job);
    $job = runOutboxJob($job); // terceira tentativa: SMTP voltou
    expect($job->status)->toBe('done');
    expect($this->fakes['notifier']->sentCount('receipt'))->toBe(1);
    expect(EmailDelivery::whereNotNull('sent_at')->count())->toBe(1);

    $job->update(['status' => 'pending']);
    runOutboxJob($job); // reprocessamento acidental: não reenvia
    expect($this->fakes['notifier']->sentCount('receipt'))->toBe(1);
});

it('T7: os jobs são independentes (financeiro fora do ar não impede e-mails)', function () {
    $this->fakes['finance']->failNext(99);

    drainOutbox(3);

    expect($this->fakes['notifier']->sentCount('receipt'))->toBe(1);
    expect($this->fakes['notifier']->sentCount('tickets'))->toBe(1);
    expect($this->fakes['finance']->registered)->toBe([]);
    expect(outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE)->status)->toBe('pending');
});

it('T7/B21: job esgotado fica failed e outbox:retry o recupera sem perder a venda', function () {
    $this->fakes['finance']->failNext(99);
    $job = outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE);

    foreach (range(1, config('tickets.outbox_max_attempts')) as $_) {
        $job = runOutboxJob($job); // cada falha reagenda; ao esgotar as tentativas vira failed
    }
    expect($job->fresh()->status)->toBe('failed');
    expect($this->fakes['finance']->registered)->toBe([]);

    $this->fakes['finance']->failNext(0);
    $this->artisan('outbox:retry', ['--all-failed' => true])->assertSuccessful();
    $job = $job->fresh();
    expect($job->status)->toBe('pending')->and($job->attempts)->toBe(0);

    runOutboxJob($job);
    expect($this->fakes['finance']->count('sale'))->toBe(1);
});

it('B21: outbox:retry exige um filtro e respeita --kind e --order', function () {
    $this->artisan('outbox:retry')->assertFailed();

    $other = placeOrder($this->batch);
    gatewayEvent($other, 'approved');
    OutboxJob::query()->update(['status' => 'failed']);

    $this->artisan('outbox:retry', ['--order' => $this->order->id, '--kind' => OutboxJob::KIND_REGISTER_FINANCE])->assertSuccessful();

    expect(OutboxJob::where('status', 'pending')->count())->toBe(1);
    expect(outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE)->status)->toBe('pending');
});

it('5.4: sweeper recupera job pending esquecido e running travado', function () {
    Queue::fake();
    OutboxJob::query()->update(['next_run_at' => now()->subMinutes(5)]);
    $stuck = outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE);
    $stuck->update(['status' => 'running', 'locked_until' => now()->subMinute()]);

    $this->artisan('outbox:sweep')->assertSuccessful();

    Queue::assertPushed(ProcessOutboxJob::class, 3);
    expect($stuck->fresh()->status)->toBe('pending');
});

it('job em falha definitiva fora do fluxo devolve a linha running ao sweeper (failed())', function () {
    $job = outboxJob($this->order, OutboxJob::KIND_REGISTER_FINANCE);
    $job->update(['status' => 'running']);

    (new ProcessOutboxJob($job->id))->failed(new RuntimeException('timeout do worker'));

    expect($job->fresh()->status)->toBe('pending')->and($job->fresh()->last_error)->toBe('timeout do worker');
});
