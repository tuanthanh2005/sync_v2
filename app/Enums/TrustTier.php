<?php

namespace App\Enums;

enum TrustTier: string
{
    case NewMember = 'new_member';
    case StandardMember = 'standard_member';
    case PremiumMember = 'premium_member';
    case RestrictedMember = 'restricted_member';
    case AiAccount = 'ai_account';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::NewMember => __('New Member'),
            self::StandardMember => __('Standard Member'),
            self::PremiumMember => __('Premium Member'),
            self::RestrictedMember => __('Restricted Member'),
            self::AiAccount => __('AI Account'),
            self::Staff => __('Staff'),
        };
    }
}
