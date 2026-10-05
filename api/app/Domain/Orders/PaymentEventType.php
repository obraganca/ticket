<?php

namespace App\Domain\Orders;

enum PaymentEventType: string
{
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case REFUNDED = 'refunded';
}
