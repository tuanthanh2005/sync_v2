<?php

namespace App\Enums;

enum SeoIndexEligibility: string
{
    case Eligible = 'eligible';
    case Excluded = 'excluded';
    case PendingQualityReview = 'pending_quality_review';
}
