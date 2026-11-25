<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Suspended => __('Suspended'),
            self::Revoked => __('Revoked'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
