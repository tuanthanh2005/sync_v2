<?php

namespace App\Enums;

enum AdCampaignStatus: string
{
    case PendingPayment = 'pending_payment';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => __('Pending payment'),
            self::PendingReview => __('Pending review'),
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Completed => __('Completed'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
