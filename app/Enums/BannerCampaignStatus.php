<?php

namespace App\Enums;

enum BannerCampaignStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case PendingReview = 'pending_review';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function occupiesSlot(): bool
    {
        return in_array($this, [self::PendingReview, self::Scheduled, self::Active], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingPayment => __('Pending payment'),
            self::PendingReview => __('Pending review'),
            self::Scheduled => __('Scheduled'),
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Rejected => __('Rejected'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
