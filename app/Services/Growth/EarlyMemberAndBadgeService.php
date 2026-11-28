<?php

namespace App\Services\Growth;

use App\Enums\MemberBadgeType;
use App\Enums\ModerationStatus;
use App\Enums\ReferralStatus;
use App\Models\Achievement;
use App\Models\Comment;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\EarlyMember;
use App\Models\GrowthCampaign;
use App\Models\OnboardingProgress;
use App\Models\Post;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use App\Services\Monetization\PremiumSubscriptionService;
use App\Services\Social\UserBadgeResolverService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EarlyMemberAndBadgeService
{
    public function __construct(
        private readonly GrowthConfigService $config,
        private readonly PremiumSubscriptionService $premiumService,
    ) {}

    /**
     * Atomically enroll a verified human member into the Early Member Program (default max 1,000 slots).
     * Uses row-locking on the campaign + unique(member_number) + unique(user_id) so concurrent signups
     * NEVER exceed max_slots and NEVER reuse previously issued member numbers.
     */
    public function enrollEarlyMemberIfEligible(User $user, ?User $actor = null, bool $forceManual = false): ?EarlyMember
    {
        if (! $user->isHumanMember() || ! $user->isActive() || ! $user->hasVerifiedEmail()) {
            return null;
        }

        if (! $forceManual && ! $this->config->bool('early_member_enabled', false)) {
            return null;
        }

        $existing = EarlyMember::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $maxSlots = max(1, $this->config->int('early_member_max_slots', 1000));
        $premiumDays = max(0, $this->config->int('early_member_premium_days', 30));
        $badgeEnabled = $this->config->bool('early_member_badge_enabled', true);

        return DB::transaction(function () use ($user, $actor, $forceManual, $maxSlots, $premiumDays, $badgeEnabled): ?EarlyMember {
            /** @var GrowthCampaign|null $campaign */
            $campaign = GrowthCampaign::query()
                ->where('slug', 'syncviet-early-members-1000')
                ->lockForUpdate()
                ->first();

            if ($campaign !== null && ! $forceManual && ! $campaign->isCurrentlyActive()) {
                return null;
            }

            if (EarlyMember::query()->where('user_id', $user->id)->exists()) {
                return EarlyMember::query()->where('user_id', $user->id)->first();
            }

            $effectiveMax = $campaign !== null && $campaign->max_participants !== null
                ? min($maxSlots, $campaign->max_participants)
                : $maxSlots;

            // Never reassign previously issued member numbers even if a slot was revoked
            $highestIssued = (int) (EarlyMember::query()->lockForUpdate()->max('member_number') ?? 0);
            if ($highestIssued >= $effectiveMax) {
                return null;
            }

            $nextNumber = $highestIssued + 1;
            $subscription = null;

            if ($premiumDays > 0) {
                $subscription = $this->premiumService->grantPromotionalPremium(
                    target: $user,
                    durationDays: $premiumDays,
                    source: 'early_member_reward',
                    reason: "SysNoxis Early Member #{$nextNumber}",
                    actor: $actor,
                    metadata: ['early_member_number' => $nextNumber],
                );
            }

            $early = EarlyMember::query()->create([
                'user_id' => $user->id,
                'member_number' => $nextNumber,
                'campaign_id' => $campaign?->id,
                'subscription_id' => $subscription?->id,
                'status' => 'active',
                'premium_days_granted' => $premiumDays,
                'qualified_at' => now(),
            ]);

            if ($campaign !== null) {
                $campaign->increment('current_participants');
                if ($premiumDays > 0) {
                    $campaign->increment('used_reward_days_budget', $premiumDays);
                }
            }

            if ($badgeEnabled) {
                $this->grantBadge(
                    user: $user,
                    type: MemberBadgeType::EarlyMember,
                    source: 'early_member_program',
                    actor: $actor,
                    campaignId: $campaign?->id,
                    metadata: ['member_number' => $nextNumber],
                );
            }

            $this->config->logActivity('growth.early_member.enrolled', $actor ?? $user, $user->id, EarlyMember::class, $early->id, [
                'member_number' => $nextNumber,
                'premium_days' => $premiumDays,
            ]);

            return $early;
        });
    }

    public function revokeEarlyMember(EarlyMember $earlyMember, string $reason, User $actor): EarlyMember
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages(['reason' => [__('Revocation reason is required.')]]);
        }

        return DB::transaction(function () use ($earlyMember, $cleanReason, $actor): EarlyMember {
            /** @var EarlyMember $locked */
            $locked = EarlyMember::query()->with('subscription')->whereKey($earlyMember->id)->lockForUpdate()->firstOrFail();

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

            UserBadge::query()
                ->where('user_id', $locked->user_id)
                ->where('badge_type', MemberBadgeType::EarlyMember->value)
                ->whereNull('revoked_at')
                ->update([
                    'status' => UserBadge::STATUS_REVOKED,
                    'revoked_by' => $actor->id,
                    'revoked_at' => now(),
                    'revoke_reason' => $cleanReason,
                ]);

            $this->config->logActivity('growth.early_member.revoked', $actor, $locked->user_id, EarlyMember::class, $locked->id, [
                'member_number' => $locked->member_number,
                'reason' => $cleanReason,
            ]);

            app(UserBadgeResolverService::class)->invalidateForUser($locked->user_id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function grantBadge(
        User $user,
        MemberBadgeType $type,
        string $source,
        ?User $actor = null,
        ?int $campaignId = null,
        ?CarbonInterface $expiresAt = null,
        array $metadata = [],
    ): UserBadge {
        $existingActive = UserBadge::query()
            ->where('user_id', $user->id)
            ->where('badge_type', $type->value)
            ->active()
            ->first();

        if ($existingActive !== null) {
            if ($metadata !== []) {
                $existingActive->forceFill(['metadata' => $metadata])->save();
            }
            app(UserBadgeResolverService::class)->invalidateForUser($user->id);

            return $existingActive;
        }

        $badge = new UserBadge;
        $badge->forceFill([
            'user_id' => $user->id,
            'badge_type' => $type->value,
            'status' => UserBadge::STATUS_ACTIVE,
            'label_key' => $type->labelKey(),
            'icon' => $type->iconKey(),
            'color' => $type->colorHex(),
            'grant_source' => $source,
            'campaign_id' => $campaignId,
            'granted_by' => $actor?->id,
            'granted_at' => now(),
            'grant_reason' => $source,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'revoked_by' => null,
            'revoke_reason' => null,
            'is_public' => true,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
        $badge->save();

        $this->config->logActivity('growth.badge.granted', $actor, $user->id, UserBadge::class, $badge->id, [
            'badge_type' => $type->value,
            'source' => $source,
        ]);

        app(UserBadgeResolverService::class)->invalidateForUser($user->id);

        return $badge;
    }

    public function revokeBadge(UserBadge $badge, string $reason, User $actor): UserBadge
    {
        $badge->forceFill([
            'status' => UserBadge::STATUS_REVOKED,
            'revoked_by' => $actor->id,
            'revoked_at' => now(),
            'revoke_reason' => trim($reason) ?: 'Revoked by administrator',
        ])->save();

        $this->config->logActivity('growth.badge.revoked', $actor, $badge->user_id, UserBadge::class, $badge->id, [
            'badge_type' => $badge->badge_type,
            'reason' => $badge->revoke_reason,
        ]);

        app(UserBadgeResolverService::class)->invalidateForUser($badge->user_id);

        return $badge;
    }

    /**
     * Batch-load active badges for a list of user IDs (zero N+1 queries on Feed/Comments/Search/Communities!).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function activeBadgesForUsers(array $userIds): array
    {
        $cleanIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($cleanIds === [] || ! Schema::hasTable('user_badges')) {
            return [];
        }

        $users = User::query()
            ->with('rbacRole:id,slug,name')
            ->whereIn('id', $cleanIds)
            ->get();

        $resolved = app(UserBadgeResolverService::class)->resolveForUsers($users);
        $map = [];
        foreach ($resolved as $uid => $data) {
            $map[$uid] = $data['profile'];
        }

        return $map;
    }

    /**
     * Evaluate value-driven achievements cleanly (never rewards spam volume!).
     */
    public function evaluateAchievements(User $user): int
    {
        if (! $user->isHumanMember() || ! $user->isActive() || ! Schema::hasTable('achievements')) {
            return 0;
        }

        $unlockedNow = 0;
        $activeAchievements = Achievement::query()->where('is_active', true)->get();
        $already = UserAchievement::query()
            ->where('user_id', $user->id)
            ->pluck('achievement_id')
            ->all();

        foreach ($activeAchievements as $ach) {
            if (in_array($ach->id, $already, true)) {
                continue;
            }

            $qualified = match ($ach->criteria_type) {
                'first_post' => Post::query()->where('user_id', $user->id)->where('moderation_status', ModerationStatus::Approved->value)->exists(),
                'first_community_joined' => CommunityMember::query()->where('user_id', $user->id)->exists(),
                'first_helpful_reply' => Comment::query()
                    ->where('user_id', $user->id)
                    ->where('moderation_status', ModerationStatus::Approved->value)
                    ->whereHas('post', static fn ($q) => $q->where('user_id', '!=', $user->id))
                    ->exists(),
                'community_contributor' => OnboardingProgress::query()->where('user_id', $user->id)->whereNotNull('completed_at')->exists()
                    && CommunityMember::query()->where('user_id', $user->id)->exists()
                    && Comment::query()->where('user_id', $user->id)->where('moderation_status', ModerationStatus::Approved->value)->exists(),
                'knowledge_contributor' => Post::query()
                    ->where('user_id', $user->id)
                    ->where('moderation_status', ModerationStatus::Approved->value)
                    ->whereRaw('LENGTH(content) >= 200')
                    ->count() >= 3,
                'early_member' => EarlyMember::query()->where('user_id', $user->id)->where('status', 'active')->exists(),
                'referral_ambassador' => Referral::query()
                    ->where('referrer_id', $user->id)
                    ->whereIn('status', [ReferralStatus::Qualified->value, ReferralStatus::Rewarded->value])
                    ->count() >= 5,
                'community_builder' => Community::query()
                    ->where('owner_id', $user->id)
                    ->where('status', 'active')
                    ->where('members_count', '>=', 5)
                    ->exists(),
                default => false,
            };

            if (! $qualified) {
                continue;
            }

            UserAchievement::query()->create([
                'user_id' => $user->id,
                'achievement_id' => $ach->id,
                'status' => 'unlocked',
                'unlocked_at' => now(),
            ]);

            if ($ach->grants_badge_type !== null) {
                $badgeEnum = MemberBadgeType::tryFrom($ach->grants_badge_type);
                if ($badgeEnum !== null) {
                    $this->grantBadge($user, $badgeEnum, "achievement:{$ach->slug}");
                }
            }

            $unlockedNow++;
        }

        return $unlockedNow;
    }
}
