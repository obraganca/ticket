<?php

namespace App\Contracts;

use App\Models\Order;

interface OrderNotifier
{
    public function sendReceipt(Order $order, string $messageId): void;

    public function sendTickets(Order $order, string $pdfContent, string $messageId): void;
}
