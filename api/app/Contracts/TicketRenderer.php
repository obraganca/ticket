<?php

namespace App\Contracts;

use App\Models\Order;

interface TicketRenderer
{
    /** Gera um PDF com QR Code por ingresso e retorna o conteúdo binário do PDF. */
    public function renderPdf(Order $order): string;
}
