<?php

use App\Contracts\FinanceClient;
use App\Contracts\OrderNotifier;
use App\Contracts\TicketRenderer;
use App\Infrastructure\DompdfTicketRenderer;
use App\Jobs\ProcessOutboxJob;
use App\Models\Batch;
use App\Models\OutboxJob;
use App\Models\Ticket;

it('T11/B13: o renderer gera um PDF válido com um QR Code por ingresso ativo', function () {
    $order = placeOrder(Batch::factory()->create(), 3);
    gatewayEvent($order, 'approved');

    $pdf = (new DompdfTicketRenderer)->renderPdf($order->fresh());

    expect(substr($pdf, 0, 4))->toBe('%PDF');
    expect(preg_match_all('#/Subtype\s*/Image#', $pdf))->toBe(3); // 1 QR por ingresso
    expect(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(3); // uma página por ingresso
});

it('T11: ingressos invalidados não entram no PDF', function () {
    $order = placeOrder(Batch::factory()->create(), 3);
    gatewayEvent($order, 'approved');
    Ticket::where('order_id', $order->id)->first()->update(['status' => Ticket::STATUS_INVALIDATED]);

    $pdf = (new DompdfTicketRenderer)->renderPdf($order->fresh());

    expect(preg_match_all('#/Subtype\s*/Image#', $pdf))->toBe(2);
});

it('T11/B15: e-mails reais (mailer array) saem com Message-ID válido, PDF anexo e sem reenvio', function () {
    $order = placeOrder(Batch::factory()->create(), 2);
    gatewayEvent($order, 'approved');
    $deps = [app(FinanceClient::class), app(TicketRenderer::class), app(OrderNotifier::class)];

    foreach ([OutboxJob::KIND_RECEIPT_EMAIL, OutboxJob::KIND_TICKETS_EMAIL] as $kind) {
        $job = outboxJob($order, $kind);
        (new ProcessOutboxJob($job->id))->handle(...$deps);
        expect($job->fresh()->status)->toBe('done');
        (new ProcessOutboxJob($job->id))->handle(...$deps); // reprocesso acidental: não reenvia
    }

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(2);
    $tickets = $messages->map(fn ($m) => $m->getOriginalMessage())->first(fn ($m) => $m->getSubject() === 'Seus ingressos');
    expect($tickets->getAttachments())->toHaveCount(1);
});

it('T11/B15: o Message-ID determinístico passa pelo Symfony Mailer de verdade (sem colchetes)', function () {
    config(['mail.default' => 'array']);
    $order = placeOrder(Batch::factory()->create(), 1);
    gatewayEvent($order, 'approved');
    $id = "{$order->id}.tickets@codificar-ticketing.local";

    app(OrderNotifier::class)->sendTickets($order->fresh(), app(TicketRenderer::class)->renderPdf($order->fresh()), $id);

    $sent = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($sent->getHeaders()->get('Message-ID')->getBodyAsString())->toBe("<{$id}>");
    $attachments = $sent->getAttachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
        ->and(substr($attachments[0]->getBody(), 0, 4))->toBe('%PDF');
    expect($sent->getTo()[0]->getAddress())->toBe('maria@example.com');
});

it('T11: comprovante sai sem anexo e com o e-mail do comprador', function () {
    config(['mail.default' => 'array']);
    $order = placeOrder(Batch::factory()->create(), 1);
    gatewayEvent($order, 'approved');

    app(OrderNotifier::class)->sendReceipt($order->fresh(), "{$order->id}.receipt@codificar-ticketing.local");

    $sent = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($sent->getAttachments())->toBeEmpty()->and($sent->getSubject())->toBe('Comprovante de pagamento');
});
