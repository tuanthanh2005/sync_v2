<?php

namespace App\Enums;

enum SeoTranslationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Archived = 'archived';
}
