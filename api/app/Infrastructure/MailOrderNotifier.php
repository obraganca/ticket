<?php

namespace App\Infrastructure;

use App\Contracts\OrderNotifier;
use App\Models\Order;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Message-ID determinístico (`order_id.kind@domínio`, SEM os colchetes: o Symfony
 * Mailer os adiciona e rejeita ids já entre `<>`). Se o mesmo e-mail for
 * reenviado, o Message-ID é idêntico, o que mitiga a duplicidade visível.
 */
class MailOrderNotifier implements OrderNotifier
{
    public function sendReceipt(Order $order, string $messageId): void
    {
        Mail::send('emails.receipt', ['order' => $order], function (Message $m) use ($order, $messageId) {
            $m->to($order->buyer_email)->subject('Comprovante de pagamento');
            $m->getHeaders()->addIdHeader('Message-ID', $messageId);
        });
    }

    public function sendTickets(Order $order, string $pdfContent, string $messageId): void
    {
        Mail::send('emails.tickets', ['order' => $order], function (Message $m) use ($order, $pdfContent, $messageId) {
            $m->to($order->buyer_email)
                ->subject('Seus ingressos')
                ->attachData($pdfContent, "ingressos-{$order->id}.pdf", ['mime' => 'application/pdf']);
            $m->getHeaders()->addIdHeader('Message-ID', $messageId);
        });
    }
}
