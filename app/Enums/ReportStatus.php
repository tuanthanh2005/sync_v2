<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Dismissed = 'dismissed';
    case Appealed = 'appealed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending Review'),
            self::UnderReview => __('Under Review'),
            self::Resolved => __('Resolved'),
            self::Rejected => __('Rejected'),
            self::Dismissed => __('Dismissed'),
            self::Appealed => __('Appealed'),
        };
    }
}
