<?php

namespace App\Enums;

enum SanctionType: string
{
    case Warning = 'warning';
    case Suspend = 'suspend';
    case Ban = 'ban';
    case Unban = 'unban';
    case Unsuspend = 'unsuspend';
    case RestrictPost = 'restrict_post';
    case RestrictComment = 'restrict_comment';
    case RestrictCommunity = 'restrict_community';
    case RestrictMessage = 'restrict_message';
    case RestrictAd = 'restrict_ad';
    case LiftRestriction = 'lift_restriction';

    public function label(): string
    {
        return match ($this) {
            self::Warning => __('Warning'),
            self::Suspend => __('Temporary Suspension'),
            self::Ban => __('Permanent Ban'),
            self::Unban => __('Unban'),
            self::Unsuspend => __('Lift Suspension'),
            self::RestrictPost => __('Restrict Posting'),
            self::RestrictComment => __('Restrict Commenting'),
            self::RestrictCommunity => __('Restrict Community Creation'),
            self::RestrictMessage => __('Restrict Messaging'),
            self::RestrictAd => __('Restrict Advertising'),
            self::LiftRestriction => __('Lift Restriction'),
        };
    }
}
