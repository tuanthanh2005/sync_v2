<?php

namespace App\Services\Growth;

use App\Enums\AccountType;
use App\Enums\ActivationMilestone;
use App\Enums\ReferralStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\EarlyMember;
use App\Models\GrowthAnalytic;
use App\Models\GrowthCampaign;
use App\Models\GrowthFraudReview;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserActivationEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GrowthAnalyticsService
{
    /**
     * Compute and persist a real human-only daily Growth Analytics snapshot (strictly excluding AI Agents & Test Accounts).
     */
    public function snapshotDailyMetrics(?CarbonInterface $forDate = null): GrowthAnalytic
    {
        $now = $forDate ?? now();
        $dateString = $now->toDateString();
        $dayStart = $now->copy()->startOfDay();
        $dayEnd = $now->copy()->endOfDay();

        $humanQuery = static fn () => User::query()
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false);

        $totalHumans = $humanQuery()->count();
        $newHumansToday = $humanQuery()->whereBetween('created_at', [$dayStart, $dayEnd])->count();
        $verifiedHumans = $humanQuery()->whereNotNull('email_verified_at')->count();

        $activatedUsers = UserActivationEvent::query()
            ->whereIn('milestone', [
                ActivationMilestone::FirstCommunityJoined->value,
                ActivationMilestone::FirstInteraction->value,
                ActivationMilestone::FirstPostPublished->value,
            ])
            ->whereHas('user', static fn ($q) => $q->where('account_type', AccountType::Human->value)->where('is_test_account', false))
            ->distinct('user_id')
            ->count('user_id');

        $dau = $humanQuery()->where('last_activity_at', '>=', $now->copy()->subDay())->count();
        $wau = $humanQuery()->where('last_activity_at', '>=', $now->copy()->subDays(7))->count();
        $mau = $humanQuery()->where('last_activity_at', '>=', $now->copy()->subDays(30))->count();

        $referralRegs = Referral::query()->count();
        $qualifiedRefs = Referral::query()
            ->whereIn('status', [ReferralStatus::Qualified->value, ReferralStatus::Rewarded->value])
            ->count();

        $organicRegs = $humanQuery()
            ->whereIn('acquisition_source', ['organic_search', 'google', 'bing', 'organic'])
            ->count();

        $d1 = $this->cohortRetentionRate(1, $now);
        $d7 = $this->cohortRetentionRate(7, $now);
        $d30 = $this->cohortRetentionRate(30, $now);

        $rewardDaysGranted = (int) ReferralReward::query()->where('status', 'granted')->sum('days_granted')
            + (int) EarlyMember::query()->where('status', 'active')->sum('premium_days_granted');

        $promoPremiumUsers = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNull('payment_id')
            ->distinct('user_id')
            ->count('user_id');

        $paidPremiumUsers = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNotNull('payment_id')
            ->where('amount_paid', '>', 0)
            ->distinct('user_id')
            ->count('user_id');

        $sources = DB::table('users')
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false)
            ->whereNull('deleted_at')
            ->select(
                DB::raw("COALESCE(NULLIF(acquisition_source, ''), 'direct') as src"),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('src')
            ->orderByDesc('total')
            ->get()
            ->map(static fn (\stdClass $r): array => ['source' => (string) $r->src, 'count' => (int) $r->total])
            ->values()
            ->all();

        $funnelCounts = DB::table('user_activation_events')
            ->join('users', 'users.id', '=', 'user_activation_events.user_id')
            ->where('users.account_type', AccountType::Human->value)
            ->where('users.is_test_account', false)
            ->select('milestone', DB::raw('COUNT(DISTINCT user_activation_events.user_id) as total'))
            ->groupBy('milestone')
            ->pluck('total', 'milestone')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        return GrowthAnalytic::query()->updateOrCreate(
            ['date' => $dateString],
            [
                'total_human_users' => $totalHumans,
                'new_human_registrations' => $newHumansToday,
                'verified_human_users' => $verifiedHumans,
                'activated_users' => $activatedUsers,
                'dau_human' => $dau,
                'wau_human' => $wau,
                'mau_human' => $mau,
                'referral_registrations' => $referralRegs,
                'qualified_referrals' => $qualifiedRefs,
                'organic_registrations' => $organicRegs,
                'd1_retention_rate' => $d1,
                'd7_retention_rate' => $d7,
                'd30_retention_rate' => $d30,
                'premium_rewards_granted_days' => $rewardDaysGranted,
                'promotional_premium_users' => $promoPremiumUsers,
                'paid_premium_users' => $paidPremiumUsers,
                'metrics_payload' => [
                    'sources' => $sources,
                    'activation_funnel' => $funnelCounts,
                ],
            ],
        );
    }

    /**
     * Precomputed Growth Center Admin Overview payload (never recalculates heavy cohorts on every click).
     *
     * @return array<string, mixed>
     */
    public function adminOverviewPayload(int $days = 30): array
    {
        $today = now()->toDateString();
        $latest = GrowthAnalytic::query()->where('date', $today)->first()
            ?? $this->snapshotDailyMetrics();

        $series = GrowthAnalytic::query()
            ->where('date', '>=', now()->subDays(max(7, min(90, $days)))->toDateString())
            ->orderBy('date')
            ->get()
            ->map(static fn (GrowthAnalytic $g): array => [
                'date' => $g->date,
                'total_human_users' => $g->total_human_users,
                'new_human_registrations' => $g->new_human_registrations,
                'dau_human' => $g->dau_human,
                'qualified_referrals' => $g->qualified_referrals,
                'organic_registrations' => $g->organic_registrations,
            ])
            ->values()
            ->all();

        // Top communities ranked by real human member count (strictly excluding AI Agents)
        $communityGrowth = Community::query()
            ->where('status', 'active')
            ->orderByDesc('members_count')
            ->limit(10)
            ->get(['id', 'name', 'slug', 'members_count', 'posts_count'])
            ->map(static function (Community $c): array {
                $humanMembers = CommunityMember::query()
                    ->where('community_id', $c->id)
                    ->whereHas('user', static fn ($q) => $q->where('account_type', AccountType::Human->value)->where('is_test_account', false))
                    ->count();

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'human_members_count' => $humanMembers,
                    'posts_count' => $c->posts_count,
                ];
            })
            ->values()
            ->all();

        $referralConversionRate = $latest->referral_registrations > 0
            ? round(($latest->qualified_referrals / $latest->referral_registrations) * 100, 1)
            : 0.0;

        return [
            'kpis' => [
                'total_human_users' => $latest->total_human_users,
                'new_human_registrations' => $latest->new_human_registrations,
                'verified_human_users' => $latest->verified_human_users,
                'activated_users' => $latest->activated_users,
                'dau_human' => $latest->dau_human,
                'wau_human' => $latest->wau_human,
                'mau_human' => $latest->mau_human,
                'referral_registrations' => $latest->referral_registrations,
                'qualified_referrals' => $latest->qualified_referrals,
                'referral_conversion_rate' => $referralConversionRate,
                'organic_registrations' => $latest->organic_registrations,
                'd1_retention_rate' => $latest->d1_retention_rate,
                'd7_retention_rate' => $latest->d7_retention_rate,
                'd30_retention_rate' => $latest->d30_retention_rate,
                'premium_rewards_granted_days' => $latest->premium_rewards_granted_days,
                'promotional_premium_users' => $latest->promotional_premium_users,
                'paid_premium_users' => $latest->paid_premium_users,
                'early_members_count' => EarlyMember::query()->where('status', 'active')->count(),
                'pending_fraud_reviews' => GrowthFraudReview::query()->where('status', 'pending')->count(),
                'active_campaigns' => GrowthCampaign::query()->where('status', 'active')->count(),
                'ai_agents_excluded' => User::query()->where('account_type', AccountType::AiAgent->value)->count(),
            ],
            'sources' => is_array($latest->metrics_payload['sources'] ?? null) ? $latest->metrics_payload['sources'] : [],
            'activationFunnel' => is_array($latest->metrics_payload['activation_funnel'] ?? null) ? $latest->metrics_payload['activation_funnel'] : [],
            'series' => $series,
            'communityGrowth' => $communityGrowth,
        ];
    }

    private function cohortRetentionRate(int $daysAgo, CarbonInterface $asOf): float
    {
        $cohortStart = $asOf->copy()->subDays($daysAgo)->startOfDay();
        $cohortEnd = $asOf->copy()->subDays($daysAgo)->endOfDay();

        $cohortSize = User::query()
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false)
            ->whereBetween('created_at', [$cohortStart, $cohortEnd])
            ->count();

        if ($cohortSize === 0) {
            return 0.0;
        }

        $retained = User::query()
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false)
            ->whereBetween('created_at', [$cohortStart, $cohortEnd])
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '>=', $cohortStart->copy()->addDays($daysAgo))
            ->count();

        return round(($retained / $cohortSize) * 100, 2);
    }
}
