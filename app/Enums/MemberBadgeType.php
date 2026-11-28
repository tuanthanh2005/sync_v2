<?php

namespace App\Enums;

enum MemberBadgeType: string
{
    case OfficialAccount = 'official_account';
    case OfficialStaff = 'official_staff';
    case Moderator = 'moderator';
    case PremiumMember = 'premium_member';
    case EarlyMember = 'early_member';
    case AiAccount = 'ai_account';
    case FoundingMember = 'founding_member';
    case ReferralAmbassador = 'referral_ambassador';
    case CommunityBuilder = 'community_builder';

    public function label(): string
    {
        return match ($this) {
            self::OfficialAccount => 'Official Account',
            self::OfficialStaff => 'Official Staff',
            self::Moderator => 'Moderator',
            self::PremiumMember => 'Premium Member',
            self::EarlyMember => 'Early Member',
            self::AiAccount => 'AI Account',
            self::FoundingMember => 'Founding Member',
            self::ReferralAmbassador => 'Referral Ambassador',
            self::CommunityBuilder => 'Community Builder',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::OfficialAccount => 'badges.officialAccount.label',
            self::OfficialStaff => 'badges.officialStaff.label',
            self::Moderator => 'badges.moderator.label',
            self::PremiumMember => 'badges.premiumMember.label',
            self::EarlyMember => 'badges.earlyMember.label',
            self::AiAccount => 'badges.aiAccount.label',
            self::FoundingMember => 'growth.badges.foundingMember',
            self::ReferralAmbassador => 'growth.badges.referralAmbassador',
            self::CommunityBuilder => 'growth.badges.communityBuilder',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::OfficialAccount => 'badges.officialAccount.description',
            self::OfficialStaff => 'badges.officialStaff.description',
            self::Moderator => 'badges.moderator.description',
            self::PremiumMember => 'badges.premiumMember.description',
            self::EarlyMember => 'badges.earlyMember.description',
            self::AiAccount => 'badges.aiAccount.description',
            self::FoundingMember => 'badges.foundingMember.description',
            self::ReferralAmbassador => 'badges.referralAmbassador.description',
            self::CommunityBuilder => 'badges.communityBuilder.description',
        };
    }

    public function colorHex(): string
    {
        return match ($this) {
            self::OfficialAccount, self::OfficialStaff => '#EAB308',
            self::Moderator => '#94A3B8',
            self::PremiumMember => '#1D9BF0',
            self::EarlyMember => '#A855F7',
            self::AiAccount => '#14B8A6',
            self::FoundingMember => '#10B981',
            self::ReferralAmbassador => '#4F46E5',
            self::CommunityBuilder => '#0EA5E9',
        };
    }

    public function iconKey(): string
    {
        return match ($this) {
            self::OfficialAccount, self::OfficialStaff => 'official_checkmark',
            self::Moderator => 'shield_check',
            self::PremiumMember => 'premium_checkmark',
            self::EarlyMember => 'star',
            self::AiAccount => 'bot',
            self::FoundingMember => 'crown',
            self::ReferralAmbassador => 'gift',
            self::CommunityBuilder => 'users',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::OfficialAccount => 100,
            self::OfficialStaff => 90,
            self::Moderator => 80,
            self::PremiumMember => 70,
            self::EarlyMember => 60,
            self::FoundingMember => 55,
            self::ReferralAmbassador => 50,
            self::CommunityBuilder => 45,
            self::AiAccount => 40,
        };
    }

    public function isAuthority(): bool
    {
        return match ($this) {
            self::OfficialAccount, self::OfficialStaff, self::Moderator => true,
            default => false,
        };
    }

    public function isGoldCheckmark(): bool
    {
        return $this === self::OfficialAccount || $this === self::OfficialStaff;
    }

    public function isManuallyGrantable(): bool
    {
        return match ($this) {
            self::OfficialAccount,
            self::OfficialStaff,
            self::Moderator,
            self::EarlyMember,
            self::FoundingMember,
            self::ReferralAmbassador,
            self::CommunityBuilder => true,
            self::PremiumMember, self::AiAccount => false,
        };
    }

    /**
     * Authority and recognition badges managed via Admin Badge Management.
     *
     * @return list<self>
     */
    public static function adminManageableCases(): array
    {
        return [
            self::OfficialAccount,
            self::OfficialStaff,
            self::Moderator,
            self::EarlyMember,
            self::FoundingMember,
        ];
    }

    /**
     * All primary badge precedence cases excluding independent dynamic badges.
     *
     * @return list<self>
     */
    public static function coreDisplayCases(): array
    {
        return [
            self::OfficialAccount,
            self::OfficialStaff,
            self::Moderator,
            self::PremiumMember,
            self::EarlyMember,
            self::AiAccount,
        ];
    }
}
