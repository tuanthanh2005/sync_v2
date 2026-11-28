<?php

namespace App\Enums;

enum PostLifecycleStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Published = 'published';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Processing => __('Processing'),
            self::Published => __('Published'),
            self::PendingReview => __('Pending Review'),
            self::Rejected => __('Rejected'),
            self::Hidden => __('Hidden'),
            self::Deleted => __('Deleted'),
        };
    }
}
