<?php

namespace App\Enums;

enum AiDraftStatus: string
{
    case PendingGeneration = 'pending_generation';
    case Generating = 'generating';
    case WaitingForQuota = 'waiting_for_quota';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::PendingGeneration => 'Pending Generation',
            self::Generating => 'Generating',
            self::WaitingForQuota => 'Waiting for Quota',
            self::Draft => 'Draft',
            self::PendingReview => 'Pending Review',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Archived => 'Archived',
        };
    }
}
