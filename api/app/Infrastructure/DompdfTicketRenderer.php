<?php

namespace App\Infrastructure;

use App\Contracts\TicketRenderer;
use App\Models\Order;
use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/** Um QR Code por ingresso ATIVO (conteúdo = código do ticket), em um único PDF do pedido. */
class DompdfTicketRenderer implements TicketRenderer
{
    public function renderPdf(Order $order): string
    {
        $tickets = $order->tickets()
            ->where('status', Ticket::STATUS_ACTIVE)
            ->orderBy('created_at')->orderBy('code')
            ->get()
            ->map(function (Ticket $ticket) {
                // endroid/qr-code 5.x: objeto imutável, configurado pelo construtor.
                $png = (new PngWriter)->write(new QrCode(data: $ticket->code, size: 220));

                return [
                    'code' => $ticket->code,
                    'holder' => $ticket->holder_name,
                    'qr_base64' => base64_encode($png->getString()),
                ];
            });

        return Pdf::loadView('tickets.pdf', ['order' => $order->loadMissing('batch'), 'tickets' => $tickets])->output();
    }
}
