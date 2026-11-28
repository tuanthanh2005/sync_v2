<?php

namespace App\Services\Social;

use App\Enums\TrustTier;
use App\Models\User;
use App\Services\System\SystemSettingService;

class TrustLevelResolver
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PostingPolicyConfigService $policyConfig,
    ) {}

    public function resolve(User $user): TrustTier
    {
        if ($user->isAiAgent()) {
            return TrustTier::AiAccount;
        }

        if ($user->isRestrictedFrom('post')) {
            return TrustTier::RestrictedMember;
        }

        if ($user->isStaff()) {
            return TrustTier::Staff;
        }

        $isNewAccount = $this->isNewAccount($user);

        // New accounts (under configured age days) stay within New Member safety guardrails
        // even if they recently purchased Premium (Section 5 mandatory safety rule).
        if ($isNewAccount) {
            return TrustTier::NewMember;
        }

        if ($user->isPremium()) {
            return TrustTier::PremiumMember;
        }

        return TrustTier::StandardMember;
    }

    public function isNewAccount(User $user): bool
    {
        if ($user->isAiAgent() || $user->isStaff()) {
            return false;
        }

        $createdAt = $user->created_at ?? now();
        $requiredDays = $this->policyConfig->newMemberAgeDays();

        return $createdAt->diffInHours(now()) < ($requiredDays * 24);
    }

    /**
     * Resolve effective posting, comment, and media limits for a user.
     *
     * @return array{
     *     tier: string,
     *     tier_label: string,
     *     is_new_account: bool,
     *     posts_per_24h: int,
     *     posts_per_60m: int,
     *     min_interval_seconds: int,
     *     comments_per_24h: int,
     *     max_images_per_post: int,
     *     edits_per_24h: int,
     *     max_post_length: int,
     *     max_comment_length: int
     * }
     */
    public function limitsFor(User $user): array
    {
        $tier = $this->resolve($user);
        $isNew = $this->isNewAccount($user);

        $tierSuffix = match ($tier) {
            TrustTier::PremiumMember, TrustTier::Staff => 'premium',
            TrustTier::StandardMember => 'standard',
            TrustTier::NewMember, TrustTier::RestrictedMember, TrustTier::AiAccount => 'new',
        };

        $posts24h = (int) $this->settings->get("posting_limit_24h_{$tierSuffix}", match ($tierSuffix) {
            'premium' => 100,
            'standard' => 30,
            default => 10,
        });

        $posts60m = (int) $this->settings->get("posting_limit_60m_{$tierSuffix}", match ($tierSuffix) {
            'premium' => 20,
            'standard' => 10,
            default => 3,
        });

        $minInterval = (int) $this->settings->get("posting_min_interval_{$tierSuffix}", match ($tierSuffix) {
            'premium' => 15,
            'standard' => 30,
            default => 60,
        });

        $comments24h = (int) $this->settings->get("comment_limit_24h_{$tierSuffix}", match ($tierSuffix) {
            'premium' => 500,
            'standard' => 200,
            default => 50,
        });

        $maxImages = (int) $this->settings->get("post_max_images_{$tierSuffix}", match ($tierSuffix) {
            'premium' => 8,
            default => 4,
        });

        if ($tier === TrustTier::RestrictedMember) {
            $posts24h = 0;
            $posts60m = 0;
            $comments24h = $user->isRestrictedFrom('comment') ? 0 : min(20, $comments24h);
        }

        return [
            'tier' => $tier->value,
            'tier_label' => $tier->label(),
            'is_new_account' => $isNew,
            'posts_per_24h' => max(0, $posts24h),
            'posts_per_60m' => max(0, $posts60m),
            'min_interval_seconds' => max(0, $minInterval),
            'comments_per_24h' => max(0, $comments24h),
            'max_images_per_post' => max(1, min(12, $maxImages)),
            'edits_per_24h' => $this->policyConfig->maxEditsPerDay(),
            'max_post_length' => $this->policyConfig->maxPostLength(),
            'max_comment_length' => $this->policyConfig->maxCommentLength(),
        ];
    }
}
