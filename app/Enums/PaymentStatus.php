<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Processing => __('Processing'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
            self::Refunded => __('Refunded'),
        };
    }
}
