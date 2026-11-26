<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Qualified = 'qualified';
    case Rejected = 'rejected';
    case Rewarded = 'rewarded';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Qualified => 'Qualified',
            self::Rejected => 'Rejected',
            self::Rewarded => 'Rewarded',
            self::Revoked => 'Revoked',
        };
    }

    public function countsTowardMilestone(): bool
    {
        return in_array($this, [self::Qualified, self::Rewarded], true);
    }
}
