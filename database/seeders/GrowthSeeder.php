<?php

namespace Database\Seeders;

use App\Enums\GrowthCampaignStatus;
use App\Models\Achievement;
use App\Models\GrowthCampaign;
use App\Models\GrowthCampaignVersion;
use App\Models\GrowthSetting;
use App\Models\ReferralRewardRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class GrowthSeeder extends Seeder
{
    public function run(): void
    {
        self::seedDefaultsIfEmpty();
    }

    public static function seedDefaultsIfEmpty(): void
    {
        if (! Schema::hasTable('growth_settings') || ! Schema::hasTable('growth_campaigns')) {
            return;
        }

        // Strict governance defaults: Referral tracking ready, reward campaigns start PAUSED/DRAFT until Admin activates!
        $settings = [
            ['key' => 'referral_enabled', 'group' => 'referral', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'referral_rewards_auto_grant', 'group' => 'referral', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'referral_attribution_days', 'group' => 'referral', 'value' => '30', 'type' => 'integer'],
            ['key' => 'referral_verification_hours', 'group' => 'referral', 'value' => '72', 'type' => 'integer'],
            ['key' => 'referral_require_email_verified', 'group' => 'referral', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'referral_require_onboarding_or_activity', 'group' => 'referral', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'referral_max_reward_days_per_user', 'group' => 'referral', 'value' => '365', 'type' => 'integer'],
            ['key' => 'referral_visit_retention_days', 'group' => 'privacy', 'value' => '90', 'type' => 'integer'],
            ['key' => 'early_member_enabled', 'group' => 'early_member', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'early_member_max_slots', 'group' => 'early_member', 'value' => '1000', 'type' => 'integer'],
            ['key' => 'early_member_premium_days', 'group' => 'early_member', 'value' => '30', 'type' => 'integer'],
            ['key' => 'early_member_badge_enabled', 'group' => 'early_member', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'founding_member_enabled', 'group' => 'founding_member', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'founding_member_max_slots', 'group' => 'founding_member', 'value' => '500', 'type' => 'integer'],
            ['key' => 'onboarding_enabled', 'group' => 'onboarding', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'retention_reengagement_enabled', 'group' => 'retention', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'retention_digest_enabled', 'group' => 'retention', 'value' => '0', 'type' => 'boolean'],
            ['key' => 'retention_reengagement_days', 'group' => 'retention', 'value' => '3,7,30', 'type' => 'string'],
            ['key' => 'retention_frequency_cap_hours', 'group' => 'retention', 'value' => '72', 'type' => 'integer'],
            ['key' => 'fraud_velocity_hourly_threshold', 'group' => 'fraud', 'value' => '5', 'type' => 'integer'],
            ['key' => 'fraud_manual_review_score', 'group' => 'fraud', 'value' => '50', 'type' => 'integer'],
        ];

        foreach ($settings as $row) {
            GrowthSetting::query()->firstOrCreate(
                ['key' => $row['key']],
                [
                    'group' => $row['group'],
                    'value' => $row['value'],
                    'type' => $row['type'],
                ],
            );
        }

        // Default Referral Program Campaign (Draft until Admin explicitly enables)
        $referralCampaign = GrowthCampaign::query()->firstOrCreate(
            ['slug' => 'syncviet-referral-v1'],
            [
                'name' => 'SysNoxis Member Referral Program',
                'description' => 'Invite real friends to SysNoxis and earn cumulative SysNoxis Premium days when referrals verify email and complete onboarding.',
                'type' => 'referral',
                'status' => GrowthCampaignStatus::Draft,
                'target_audience' => 'human_members',
                'target_languages' => ['en', 'vi', 'es', 'pt-BR', 'id', 'hi', 'th', 'fil', 'fr', 'de', 'ja', 'ko', 'zh-CN', 'ar', 'tr'],
                'qualification_rules' => [
                    'require_email_verified' => true,
                    'verification_hours' => 72,
                    'require_activity_or_onboarding' => true,
                ],
                'reward_config' => [
                    'mode' => 'cumulative_milestones',
                    'reward_type' => 'premium_days',
                ],
                'max_reward_days_budget' => 50000,
            ],
        );

        if ($referralCampaign->active_version_id === null) {
            $ver = GrowthCampaignVersion::query()->firstOrCreate(
                ['campaign_id' => $referralCampaign->id, 'version_number' => 1],
                [
                    'qualification_rules' => $referralCampaign->qualification_rules,
                    'reward_config' => $referralCampaign->reward_config,
                    'notes' => 'Initial referral program terms',
                ],
            );
            $referralCampaign->forceFill(['active_version_id' => $ver->id])->save();
        }

        // Default Early Members 1,000 Campaign (Draft until Admin enables)
        GrowthCampaign::query()->firstOrCreate(
            ['slug' => 'syncviet-early-members-1000'],
            [
                'name' => 'SysNoxis Early Members (First 1,000)',
                'description' => 'Permanent Early Member Badge and 30 days of complimentary SysNoxis Premium for the first 1,000 verified human members.',
                'type' => 'early_member',
                'status' => GrowthCampaignStatus::Draft,
                'target_audience' => 'first_1000_verified_humans',
                'qualification_rules' => [
                    'require_email_verified' => true,
                    'exclude_ai_and_system' => true,
                ],
                'reward_config' => [
                    'badge_type' => 'early_member',
                    'premium_days' => 30,
                ],
                'max_participants' => 1000,
                'max_reward_days_budget' => 30000,
            ],
        );

        // Default Founding Member Program (Draft — Admin must configure pricing & activate)
        GrowthCampaign::query()->firstOrCreate(
            ['slug' => 'syncviet-founding-members'],
            [
                'name' => 'SysNoxis Founding Members',
                'description' => 'Limited early supporter campaign granting the permanent Founding Member Badge with an annual Premium plan.',
                'type' => 'founding_member',
                'status' => GrowthCampaignStatus::Draft,
                'target_audience' => 'annual_premium_supporters',
                'qualification_rules' => [
                    'require_paid_annual_premium' => true,
                ],
                'reward_config' => [
                    'badge_type' => 'founding_member',
                    'duration_months' => 12,
                ],
                'max_participants' => 500,
            ],
        );

        // Cumulative Referral Reward Milestones (PHẦN 6)
        $milestones = [
            ['slug' => 'ref-tier-1', 'name' => '1 Qualified Referral', 'req' => 1, 'days' => 3, 'sort' => 1],
            ['slug' => 'ref-tier-3', 'name' => '3 Qualified Referrals', 'req' => 3, 'days' => 7, 'sort' => 2],
            ['slug' => 'ref-tier-5', 'name' => '5 Qualified Referrals', 'req' => 5, 'days' => 15, 'sort' => 3],
            ['slug' => 'ref-tier-10', 'name' => '10 Qualified Referrals', 'req' => 10, 'days' => 30, 'sort' => 4],
            ['slug' => 'ref-tier-25', 'name' => '25 Qualified Referrals', 'req' => 25, 'days' => 90, 'sort' => 5],
            ['slug' => 'ref-tier-50', 'name' => '50 Qualified Referrals', 'req' => 50, 'days' => 180, 'sort' => 6],
            ['slug' => 'ref-tier-100', 'name' => '100 Qualified Referrals', 'req' => 100, 'days' => 365, 'sort' => 7],
        ];

        foreach ($milestones as $m) {
            ReferralRewardRule::query()->firstOrCreate(
                ['slug' => $m['slug']],
                [
                    'campaign_id' => $referralCampaign->id,
                    'name' => $m['name'],
                    'required_qualified_referrals' => $m['req'],
                    'cumulative_premium_days' => $m['days'],
                    'is_repeatable' => false,
                    'max_claims_per_user' => 1,
                    'is_active' => true,
                    'sort_order' => $m['sort'],
                ],
            );
        }

        // Meaningful Value-Driven Achievements (PHẦN 12 — never raw spam counts)
        $achievements = [
            ['slug' => 'first-post', 'name' => 'First Post', 'desc' => 'Published your first approved public post on SysNoxis.', 'icon' => 'pen-line', 'color' => 'indigo', 'type' => 'first_post', 'badge' => null, 'sort' => 1],
            ['slug' => 'first-community-joined', 'name' => 'First Community Joined', 'desc' => 'Joined your first community to connect around shared interests.', 'icon' => 'users', 'color' => 'emerald', 'type' => 'first_community_joined', 'badge' => null, 'sort' => 2],
            ['slug' => 'first-helpful-reply', 'name' => 'First Helpful Reply', 'desc' => 'Contributed a thoughtful comment on another member\'s discussion.', 'icon' => 'message-circle', 'color' => 'sky', 'type' => 'first_helpful_reply', 'badge' => null, 'sort' => 3],
            ['slug' => 'community-contributor', 'name' => 'Community Contributor', 'desc' => 'Completed onboarding, joined a community, and participated constructively.', 'icon' => 'sparkles', 'color' => 'violet', 'type' => 'community_contributor', 'badge' => null, 'sort' => 4],
            ['slug' => 'knowledge-contributor', 'name' => 'Knowledge Contributor', 'desc' => 'Shared multiple substantial approved guides or discussions.', 'icon' => 'book-open', 'color' => 'blue', 'type' => 'knowledge_contributor', 'badge' => null, 'sort' => 5],
            ['slug' => 'early-member', 'name' => 'Early Member', 'desc' => 'Recognized among the first 1,000 verified pioneer members of SysNoxis.', 'icon' => 'award', 'color' => 'amber', 'type' => 'early_member', 'badge' => 'early_member', 'sort' => 6],
            ['slug' => 'referral-ambassador', 'name' => 'Referral Ambassador', 'desc' => 'Successfully invited at least 5 verified real members to SysNoxis.', 'icon' => 'gift', 'color' => 'indigo', 'type' => 'referral_ambassador', 'badge' => 'referral_ambassador', 'sort' => 7],
            ['slug' => 'community-builder', 'name' => 'Community Builder', 'desc' => 'Created an active community welcoming verified real members.', 'icon' => 'crown', 'color' => 'amber', 'type' => 'community_builder', 'badge' => 'community_builder', 'sort' => 8],
        ];

        foreach ($achievements as $a) {
            Achievement::query()->firstOrCreate(
                ['slug' => $a['slug']],
                [
                    'name' => $a['name'],
                    'description' => $a['desc'],
                    'icon' => $a['icon'],
                    'color' => $a['color'],
                    'criteria_type' => $a['type'],
                    'criteria_config' => [],
                    'reward_premium_days' => 0,
                    'grants_badge_type' => $a['badge'],
                    'is_active' => true,
                    'sort_order' => $a['sort'],
                ],
            );
        }
    }
}
