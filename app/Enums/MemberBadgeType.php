<?php

namespace App\Enums;

enum MemberBadgeType: string
{
    case EarlyMember = 'early_member';
    case FoundingMember = 'founding_member';
    case OfficialAccount = 'official_account';
    case ReferralAmbassador = 'referral_ambassador';
    case CommunityBuilder = 'community_builder';

    public function label(): string
    {
        return match ($this) {
            self::EarlyMember => 'Early Member',
            self::FoundingMember => 'Founding Member',
            self::OfficialAccount => 'Official Account',
            self::ReferralAmbassador => 'Referral Ambassador',
            self::CommunityBuilder => 'Community Builder',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::EarlyMember => 'growth.badges.earlyMember',
            self::FoundingMember => 'growth.badges.foundingMember',
            self::OfficialAccount => 'growth.badges.officialAccount',
            self::ReferralAmbassador => 'growth.badges.referralAmbassador',
            self::CommunityBuilder => 'growth.badges.communityBuilder',
        };
    }
}
