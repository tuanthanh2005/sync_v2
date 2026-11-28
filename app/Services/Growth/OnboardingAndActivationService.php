<?php

namespace App\Services\Growth;

use App\Enums\AccountType;
use App\Enums\ActivationMilestone;
use App\Enums\PostCategory;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Hashtag;
use App\Models\OnboardingProgress;
use App\Models\User;
use App\Models\UserActivationEvent;
use App\Services\Social\UserBadgeResolverService;
use App\Services\System\LanguageService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OnboardingAndActivationService
{
    public function __construct(
        private readonly GrowthConfigService $config,
        private readonly EarlyMemberAndBadgeService $badgeService,
        private readonly LanguageService $languages,
    ) {}

    public function ensureProgress(User $user, ?string $returnToUrl = null): OnboardingProgress
    {
        $safeReturn = $this->sanitizeInternalPath($returnToUrl);

        return OnboardingProgress::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'current_step' => 'interests',
                'completed_steps' => [],
                'selected_interests' => [],
                'selected_locale' => $user->preferred_locale ?: app()->getLocale(),
                'return_to_url' => $safeReturn,
            ],
        );
    }

    /**
     * Record a unique user activation milestone idempotently (never duplicates).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordMilestone(
        User $user,
        ActivationMilestone $milestone,
        ?string $source = null,
        array $metadata = [],
    ): void {
        if (! $user->isHumanMember() || ! Schema::hasTable('user_activation_events')) {
            return;
        }

        UserActivationEvent::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'milestone' => $milestone->value,
            ],
            [
                'source' => $source ?? $user->acquisition_source ?? 'direct',
                'metadata' => $metadata !== [] ? $metadata : null,
                'occurred_at' => now(),
            ],
        );
    }

    /**
     * Save member onboarding choices WITHOUT ever auto-following or auto-joining communities without consent.
     *
     * @param  array{
     *     selected_locale?: string|null,
     *     selected_interests?: list<string>|null,
     *     current_step?: string|null,
     *     complete?: bool,
     *     skip?: bool
     * }  $data
     */
    public function updateProgress(User $user, array $data): OnboardingProgress
    {
        $progress = $this->ensureProgress($user);
        $completedSteps = is_array($progress->completed_steps) ? $progress->completed_steps : [];

        if (isset($data['selected_locale']) && trim($data['selected_locale']) !== '') {
            $localeCode = trim($data['selected_locale']);
            if (in_array($localeCode, $this->languages->enabledCodes(), true)) {
                $progress->selected_locale = $localeCode;
                $user->forceFill(['preferred_locale' => $localeCode])->saveQuietly();
                $completedSteps[] = 'language';
            }
        }

        if (isset($data['selected_interests'])) {
            $validTopics = array_values(array_unique(array_filter(
                array_map(static fn (string $v): string => Str::limit(trim($v), 60, ''), $data['selected_interests']),
                static fn (string $v): bool => $v !== '',
            )));
            $progress->selected_interests = array_slice($validTopics, 0, 12);
            if ($validTopics !== []) {
                $completedSteps[] = 'interests';
                $this->recordMilestone($user, ActivationMilestone::InterestsSelected, null, ['topics' => $progress->selected_interests]);
            }
        }

        if (isset($data['current_step']) && trim($data['current_step']) !== '') {
            $progress->current_step = Str::limit(trim($data['current_step']), 36, '');
        }

        if (! empty($data['skip'])) {
            $progress->skipped_at = now();
            $progress->completed_at ??= now();
            $progress->current_step = 'done';
        } elseif (! empty($data['complete'])) {
            $progress->completed_at = now();
            $progress->current_step = 'done';
            $completedSteps[] = 'completed';
        }

        $progress->completed_steps = array_values(array_unique($completedSteps));
        $progress->save();

        if ($user->hasVerifiedEmail()) {
            $this->recordMilestone($user, ActivationMilestone::EmailVerified);
            $this->badgeService->enrollEarlyMemberIfEligible($user);
        }

        $this->badgeService->evaluateAchievements($user);

        return $progress;
    }

    /**
     * Build rule-based personalized Onboarding payload sourced from real Topics, Communities, and Accounts.
     *
     * @return array<string, mixed>
     */
    public function onboardingPayload(User $user): array
    {
        $progress = $this->ensureProgress($user);

        // Pull topics dynamically from real PostCategory + trending Hashtags (never hardcoded fake topics)
        $categories = PostCategory::options();
        $trendingTags = Hashtag::query()
            ->where('posts_count', '>', 0)
            ->orderByDesc('posts_count')
            ->limit(12)
            ->get(['name', 'slug', 'posts_count'])
            ->map(static fn (Hashtag $h): array => [
                'value' => $h->slug,
                'label' => '#'.$h->name,
                'posts_count' => $h->posts_count,
            ])
            ->values()
            ->all();

        $joinedIds = CommunityMember::query()
            ->where('user_id', $user->id)
            ->pluck('community_id')
            ->all();

        $interests = is_array($progress->selected_interests) ? $progress->selected_interests : [];

        $communitiesQuery = Community::query()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderByDesc('is_featured')
            ->orderByDesc('members_count');

        if ($interests !== []) {
            $catValues = array_map(static fn (array $c): string => $c['value'], $categories);
            $matchedCats = array_values(array_intersect($interests, $catValues));
            if ($matchedCats !== []) {
                $communitiesQuery->orderByRaw('CASE WHEN category IN ('.implode(',', array_fill(0, count($matchedCats), '?')).') THEN 0 ELSE 1 END', $matchedCats);
            }
        }

        $recommendedCommunities = $communitiesQuery
            ->limit(8)
            ->get()
            ->map(static fn (Community $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'category' => $c->category->value,
                'category_label' => $c->category->label(),
                'description' => $c->description,
                'members_count' => $c->members_count,
                'is_joined' => in_array($c->id, $joinedIds, true),
            ])
            ->values()
            ->all();

        $recommendedUserModels = User::query()
            ->where('id', '!=', $user->id)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->whereIn('account_type', [AccountType::Human->value, AccountType::AiAgent->value, AccountType::SystemOfficial->value])
            ->with(['profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge,followers_count', 'rbacRole:id,slug,name'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get(['id', 'name', 'username', 'avatar_url', 'role', 'rbac_role_id', 'status', 'account_type', 'ai_disclosure', 'premium_until', 'premium_suspended_at']);

        $onboardingBadges = app(UserBadgeResolverService::class)->resolveForUsers($recommendedUserModels);

        $recommendedAccounts = $recommendedUserModels
            ->map(static fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'headline' => $u->profile?->headline,
                'avatar' => $u->avatarUrl(),
                'is_ai_agent' => $u->isAiAgent(),
                'ai_disclosure' => $u->ai_disclosure,
                'is_premium' => $u->isPremium(),
                'compact_badges' => $onboardingBadges[$u->id]['compact'] ?? [],
                'badges' => $onboardingBadges[$u->id]['compact'] ?? [],
            ])
            ->values()
            ->all();

        $milestones = UserActivationEvent::query()
            ->where('user_id', $user->id)
            ->pluck('milestone')
            ->all();

        return [
            'enabled' => $this->config->bool('onboarding_enabled', true),
            'progress' => [
                'current_step' => $progress->current_step,
                'completed_steps' => $progress->completed_steps ?? [],
                'selected_interests' => $interests,
                'selected_locale' => $progress->selected_locale ?? app()->getLocale(),
                'return_to_url' => $progress->return_to_url,
                'completed_at' => $progress->completed_at?->toIso8601String(),
                'skipped_at' => $progress->skipped_at?->toIso8601String(),
            ],
            'emailVerified' => $user->hasVerifiedEmail(),
            'categories' => $categories,
            'trendingTopics' => $trendingTags,
            'recommendedCommunities' => $recommendedCommunities,
            'recommendedAccounts' => $recommendedAccounts,
            'languages' => $this->languages->publicEnabledLanguages(),
            'milestonesReached' => $milestones,
        ];
    }

    private function sanitizeInternalPath(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || ! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//') || str_contains($trimmed, '\\')) {
            return null;
        }

        return Str::limit($trimmed, 240, '');
    }
}
