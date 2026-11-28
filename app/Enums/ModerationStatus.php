<?php

namespace App\Enums;

enum ModerationStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case Removed = 'removed';
}
