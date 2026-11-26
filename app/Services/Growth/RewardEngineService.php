<?php

namespace App\Services\Growth;

use App\Enums\GrowthCampaignStatus;
use App\Enums\ReferralStatus;
use App\Models\GrowthCampaign;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\ReferralRewardRule;
use App\Models\User;
use App\Services\Monetization\PremiumSubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardEngineService
{
    public function __construct(
        private readonly GrowthConfigService $config,
        private readonly PremiumSubscriptionService $premiumService,
    ) {}

    /**
     * Evaluate all active cumulative referral milestones for a referrer and grant ONLY incremental delta days.
     * Example: Rule 1 = 3d total, Rule 2 = 7d total -> crossing Rule 2 grants (7 - 3) = 4 extra days (NEVER 7 again!).
     * Strictly atomic with User::lockForUpdate(), unique (user_id, rule_id, claim_index), and idempotency keys.
     *
     * @return list<ReferralReward>
     */
    public function evaluateAndGrantForUser(User $referrer, ?User $actor = null, bool $forceManual = false): array
    {
        if (! $referrer->isHumanMember() || ! $referrer->isActive()) {
            return [];
        }

        if (! $forceManual && ! $this->config->bool('referral_rewards_auto_grant', false)) {
            return [];
        }

        $granted = [];

        DB::transaction(function () use ($referrer, $actor, &$granted): void {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($referrer->id)->lockForUpdate()->firstOrFail();

            $qualifiedCount = Referral::query()
                ->where('referrer_id', $lockedUser->id)
                ->whereIn('status', [ReferralStatus::Qualified->value, ReferralStatus::Rewarded->value])
                ->count();

            if ($qualifiedCount <= 0) {
                return;
            }

            $rules = ReferralRewardRule::query()
                ->with('campaign')
                ->where('is_active', true)
                ->where('required_qualified_referrals', '<=', $qualifiedCount)
                ->orderBy('required_qualified_referrals')
                ->orderBy('sort_order')
                ->get();

            $maxPerUser = max(1, $this->config->int('referral_max_reward_days_per_user', 365));

            $alreadyGrantedDays = (int) ReferralReward::query()
                ->where('user_id', $lockedUser->id)
                ->where('source', 'referral_reward')
                ->where('status', 'granted')
                ->sum('days_granted');

            foreach ($rules as $rule) {
                // Respect linked campaign active window & reward budget if attached
                if ($rule->campaign !== null) {
                    /** @var GrowthCampaign $lockedCampaign */
                    $lockedCampaign = GrowthCampaign::query()->whereKey($rule->campaign_id)->lockForUpdate()->firstOrFail();
                    if (! $lockedCampaign->isCurrentlyActive()) {
                        continue;
                    }
                } else {
                    $lockedCampaign = null;
                }

                $existingClaims = ReferralReward::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('rule_id', $rule->id)
                    ->whereIn('status', ['granted', 'held'])
                    ->count();

                $maxClaims = $rule->is_repeatable ? max(1, $rule->max_claims_per_user) : 1;
                if ($existingClaims >= $maxClaims) {
                    continue;
                }

                $claimIndex = $existingClaims + 1;
                $idempotencyKey = "ref_reward:{$lockedUser->id}:rule:{$rule->id}:claim:{$claimIndex}";

                if (ReferralReward::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                // Cumulative milestone delta calculation (NEVER double-grant!)
                $deltaDays = $rule->is_repeatable
                    ? $rule->cumulative_premium_days
                    : max(0, $rule->cumulative_premium_days - $alreadyGrantedDays);

                if ($deltaDays <= 0) {
                    continue;
                }

                // Enforce per-user total referral reward ceiling
                $remainingCap = max(0, $maxPerUser - $alreadyGrantedDays);
                $deltaDays = min($deltaDays, $remainingCap);
                if ($deltaDays <= 0) {
                    break;
                }

                // Enforce campaign reward days budget if set
                if ($lockedCampaign !== null && ! $lockedCampaign->hasRemainingBudget($deltaDays)) {
                    continue;
                }

                $subscription = $this->premiumService->grantPromotionalPremium(
                    target: $lockedUser,
                    durationDays: $deltaDays,
                    source: 'referral_reward',
                    reason: "Referral milestone: {$rule->name} ({$rule->required_qualified_referrals} qualified referrals)",
                    actor: $actor,
                    metadata: [
                        'rule_id' => $rule->id,
                        'rule_slug' => $rule->slug,
                        'milestone_referrals' => $rule->required_qualified_referrals,
                        'cumulative_days_target' => $rule->cumulative_premium_days,
                    ],
                );

                $reward = ReferralReward::query()->create([
                    'user_id' => $lockedUser->id,
                    'rule_id' => $rule->id,
                    'campaign_id' => $lockedCampaign?->id,
                    'subscription_id' => $subscription->id,
                    'idempotency_key' => $idempotencyKey,
                    'claim_index' => $claimIndex,
                    'milestone_referrals' => $rule->required_qualified_referrals,
                    'days_granted' => $deltaDays,
                    'cumulative_days_target' => $rule->cumulative_premium_days,
                    'source' => 'referral_reward',
                    'status' => 'granted',
                    'note' => "Milestone {$rule->name}: +{$deltaDays}d (cumulative {$rule->cumulative_premium_days}d)",
                    'granted_by' => $actor?->id,
                    'granted_at' => now(),
                ]);

                if ($lockedCampaign !== null) {
                    $lockedCampaign->increment('used_reward_days_budget', $deltaDays);
                }

                $alreadyGrantedDays += $deltaDays;
                $granted[] = $reward;

                // Mark qualified referrals as rewarded
                Referral::query()
                    ->where('referrer_id', $lockedUser->id)
                    ->where('status', ReferralStatus::Qualified->value)
                    ->update(['status' => ReferralStatus::Rewarded->value]);

                $this->config->logActivity('growth.reward.granted', $actor, $lockedUser->id, ReferralReward::class, $reward->id, [
                    'rule_slug' => $rule->slug,
                    'days_granted' => $deltaDays,
                    'subscription_id' => $subscription->id,
                ]);
            }
        });

        return $granted;
    }

    /**
     * Administratively grant a manual promotional Premium reward with mandatory audit reason.
     */
    public function manualGrantReward(User $target, int $days, string $source, string $reason, User $actor): ReferralReward
    {
        if (! $target->isHumanMember()) {
            throw ValidationException::withMessages([
                'user' => [__('Growth rewards can only be granted to real human accounts.')],
            ]);
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages(['reason' => [__('A clear reason is required.')]]);
        }

        $safeDays = max(1, min(365, $days));
        $sub = $this->premiumService->grantPromotionalPremium(
            target: $target,
            durationDays: $safeDays,
            source: $source,
            reason: $cleanReason,
            actor: $actor,
        );

        $reward = ReferralReward::query()->create([
            'user_id' => $target->id,
            'rule_id' => null,
            'campaign_id' => null,
            'subscription_id' => $sub->id,
            'idempotency_key' => 'manual_grant:'.$target->id.':'.now()->timestamp.':'.bin2hex(random_bytes(4)),
            'claim_index' => 1,
            'milestone_referrals' => 0,
            'days_granted' => $safeDays,
            'cumulative_days_target' => $safeDays,
            'source' => $source,
            'status' => 'granted',
            'note' => $cleanReason,
            'granted_by' => $actor->id,
            'granted_at' => now(),
        ]);

        $this->config->logActivity('growth.reward.manual_granted', $actor, $target->id, ReferralReward::class, $reward->id, [
            'days' => $safeDays,
            'source' => $source,
            'reason' => $cleanReason,
        ]);

        return $reward;
    }

    /**
     * Revoke a fraudulent or erroneous referral reward while preserving the member's purchased Premium time.
     */
    public function revokeReward(ReferralReward $reward, string $reason, User $actor): ReferralReward
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages(['reason' => [__('Revocation reason is required.')]]);
        }

        if ($reward->status === 'revoked') {
            return $reward;
        }

        return DB::transaction(function () use ($reward, $cleanReason, $actor): ReferralReward {
            /** @var ReferralReward $locked */
            $locked = ReferralReward::query()->with('subscription')->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'revoked') {
                return $locked;
            }

            if ($locked->subscription !== null) {
                $this->premiumService->revokePromotionalPremium($locked->subscription, $cleanReason, $actor);
            }

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revoke_reason' => $cleanReason,
            ])->save();

            $this->config->logActivity('growth.reward.revoked', $actor, $locked->user_id, ReferralReward::class, $locked->id, [
                'days_granted' => $locked->days_granted,
                'reason' => $cleanReason,
            ]);

            return $locked;
        });
    }

    /**
     * Helper to check active referral campaign.
     */
    public function activeReferralCampaign(): ?GrowthCampaign
    {
        return GrowthCampaign::query()
            ->where('type', 'referral')
            ->where('status', GrowthCampaignStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->first(static fn (GrowthCampaign $c): bool => $c->isCurrentlyActive());
    }
}
