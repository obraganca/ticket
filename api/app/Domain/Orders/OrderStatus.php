<?php

namespace App\Domain\Orders;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case DECLINED = 'declined';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
    case REFUND_REQUIRED = 'refund_required';

    /** Estados que não mudam mais (fim do polling no front). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::DECLINED, self::EXPIRED, self::REFUNDED, self::REFUND_REQUIRED => true,
            default => false,
        };
    }
}
