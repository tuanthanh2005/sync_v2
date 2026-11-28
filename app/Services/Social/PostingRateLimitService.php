<?php

namespace App\Services\Social;

use App\Exceptions\RateLimitExceededException;
use App\Models\PublishingQuotaEvent;
use App\Models\SpamEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PostingRateLimitService
{
    private const IP_HOURLY_SAFETY_CEILING = 120;

    public function __construct(
        private readonly TrustLevelResolver $trustResolver,
    ) {}

    /**
     * Assert rolling-window post quotas and minimum interval under a row-locked transaction.
     *
     * @throws RateLimitExceededException
     */
    public function assertCanCreatePost(User $user, ?string $ipAddress = null): void
    {
        if ($user->isAiAgent()) {
            // AI Agent rate limits are enforced separately by AiAutomationControlService & agent daily caps.
            return;
        }

        $limits = $this->trustResolver->limitsFor($user);

        if ($limits['posts_per_24h'] <= 0 || $limits['posts_per_60m'] <= 0) {
            throw new RateLimitExceededException(
                __('Your account is currently restricted from publishing new posts.'),
                3600,
                'account_restricted',
            );
        }

        $now = now();
        $ipHash = $ipAddress !== null && $ipAddress !== '' ? hash('sha256', $ipAddress) : null;

        DB::transaction(function () use ($user, $limits, $now, $ipHash): void {
            // Lock user row to prevent concurrent burst bypass across simultaneous requests.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $windowStart24h = $now->copy()->subHours(24);
            $windowStart60m = $now->copy()->subMinutes(60);

            $recentEvents = PublishingQuotaEvent::query()
                ->where('user_id', $user->id)
                ->where('action_type', 'post')
                ->where('created_at', '>=', $windowStart24h)
                ->orderBy('created_at')
                ->get(['created_at']);

            $count24h = $recentEvents->count();
            $lastEvent = $recentEvents->last();

            if ($limits['min_interval_seconds'] > 0 && $lastEvent !== null) {
                $elapsed = max(0, (int) $lastEvent->created_at->diffInSeconds($now));
                if ($elapsed < $limits['min_interval_seconds']) {
                    $retryAfter = max(1, $limits['min_interval_seconds'] - $elapsed);
                    $this->throwRateLimited(
                        $user,
                        'post.create',
                        'min_interval',
                        $retryAfter,
                        $limits['tier'],
                        $ipHash,
                    );
                }
            }

            $events60m = $recentEvents->filter(
                static fn (PublishingQuotaEvent $ev): bool => $ev->created_at->greaterThanOrEqualTo($windowStart60m),
            )->values();

            if ($events60m->count() >= $limits['posts_per_60m']) {
                /** @var PublishingQuotaEvent $oldest60m */
                $oldest60m = $events60m->first();
                $retryAfter = $this->secondsUntilExpiry($oldest60m->created_at, 3600, $now);
                $this->throwRateLimited(
                    $user,
                    'post.create',
                    'hourly_limit',
                    $retryAfter,
                    $limits['tier'],
                    $ipHash,
                );
            }

            if ($count24h >= $limits['posts_per_24h']) {
                /** @var PublishingQuotaEvent $oldest24h */
                $oldest24h = $recentEvents->first();
                $retryAfter = $this->secondsUntilExpiry($oldest24h->created_at, 86_400, $now);
                $this->throwRateLimited(
                    $user,
                    'post.create',
                    'daily_limit',
                    $retryAfter,
                    $limits['tier'],
                    $ipHash,
                );
            }

            // Soft secondary server-protection guard per IP (never suspends account for shared Wi-Fi/VPN).
            if ($ipHash !== null) {
                $ipCount = PublishingQuotaEvent::query()
                    ->where('ip_hash', $ipHash)
                    ->where('action_type', 'post')
                    ->where('created_at', '>=', $windowStart60m)
                    ->count();

                if ($ipCount >= self::IP_HOURLY_SAFETY_CEILING) {
                    $this->throwRateLimited(
                        $user,
                        'post.create',
                        'ip_burst_guard',
                        600,
                        $limits['tier'],
                        $ipHash,
                    );
                }
            }
        });
    }

    /**
     * Assert rolling-window daily comment quota.
     *
     * @throws RateLimitExceededException
     */
    public function assertCanCreateComment(User $user, ?string $ipAddress = null): void
    {
        if ($user->isAiAgent()) {
            return;
        }

        $limits = $this->trustResolver->limitsFor($user);
        $maxComments = $limits['comments_per_24h'];

        if ($maxComments <= 0) {
            throw new RateLimitExceededException(
                __('Your account is currently restricted from commenting.'),
                3600,
                'account_restricted',
                'content',
            );
        }

        $now = now();
        $ipHash = $ipAddress !== null && $ipAddress !== '' ? hash('sha256', $ipAddress) : null;

        DB::transaction(function () use ($user, $maxComments, $limits, $now, $ipHash): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $windowStart24h = $now->copy()->subHours(24);

            $oldest = PublishingQuotaEvent::query()
                ->where('user_id', $user->id)
                ->where('action_type', 'comment')
                ->where('created_at', '>=', $windowStart24h)
                ->orderBy('created_at')
                ->get(['created_at']);

            if ($oldest->count() >= $maxComments) {
                /** @var PublishingQuotaEvent $firstEvent */
                $firstEvent = $oldest->first();
                $retryAfter = $this->secondsUntilExpiry($firstEvent->created_at, 86_400, $now);
                $this->throwRateLimited(
                    $user,
                    'comment.create',
                    'comment_daily_limit',
                    $retryAfter,
                    $limits['tier'],
                    $ipHash,
                );
            }
        });
    }

    /**
     * Assert rolling 24h edit limit to prevent unlimited edit spam.
     *
     * @throws RateLimitExceededException
     */
    public function assertCanEditPost(User $user, ?string $ipAddress = null): void
    {
        if ($user->isStaff() || $user->isAiAgent()) {
            return;
        }

        $limits = $this->trustResolver->limitsFor($user);
        $maxEdits = max(1, $limits['edits_per_24h']);
        $now = now();
        $ipHash = $ipAddress !== null && $ipAddress !== '' ? hash('sha256', $ipAddress) : null;

        $events = PublishingQuotaEvent::query()
            ->where('user_id', $user->id)
            ->where('action_type', 'edit')
            ->where('created_at', '>=', $now->copy()->subHours(24))
            ->orderBy('created_at')
            ->get(['created_at']);

        if ($events->count() >= $maxEdits) {
            /** @var PublishingQuotaEvent $firstEvent */
            $firstEvent = $events->first();
            $retryAfter = $this->secondsUntilExpiry($firstEvent->created_at, 86_400, $now);
            $this->throwRateLimited(
                $user,
                'post.edit',
                'edit_daily_limit',
                $retryAfter,
                $limits['tier'],
                $ipHash,
            );
        }
    }

    public function recordUsage(
        User $user,
        string $actionType,
        ?int $subjectId,
        ?string $ipAddress = null,
    ): void {
        $tier = $this->trustResolver->resolve($user)->value;

        PublishingQuotaEvent::query()->create([
            'user_id' => $user->id,
            'action_type' => $actionType,
            'subject_id' => $subjectId,
            'trust_tier' => $tier,
            'ip_hash' => $ipAddress !== null && $ipAddress !== '' ? hash('sha256', $ipAddress) : null,
            'created_at' => now(),
        ]);
    }

    public function formatWaitDuration(int $seconds): string
    {
        $safeSeconds = max(1, $seconds);

        if ($safeSeconds < 60) {
            return trans_choice('{1} :count second|[2,*] :count seconds', $safeSeconds, ['count' => $safeSeconds]);
        }

        if ($safeSeconds < 3600) {
            $minutes = (int) ceil($safeSeconds / 60);

            return trans_choice('{1} :count minute|[2,*] :count minutes', $minutes, ['count' => $minutes]);
        }

        $hours = (int) ceil($safeSeconds / 3600);

        return trans_choice('{1} :count hour|[2,*] :count hours', $hours, ['count' => $hours]);
    }

    private function secondsUntilExpiry(CarbonInterface $createdAt, int $windowSeconds, CarbonInterface $now): int
    {
        $expiresAt = $createdAt->copy()->addSeconds($windowSeconds);

        return max(1, (int) $now->diffInSeconds($expiresAt, false));
    }

    /**
     * @throws RateLimitExceededException
     */
    private function throwRateLimited(
        User $user,
        string $actionContext,
        string $limitType,
        int $retryAfter,
        string $tier,
        ?string $ipHash,
    ): never {
        $waitText = $this->formatWaitDuration($retryAfter);

        $message = match ($actionContext) {
            'comment.create' => __('You have reached your commenting limit. Please try again in :duration.', ['duration' => $waitText]),
            'post.edit' => __('You have reached your editing limit. Please try again in :duration.', ['duration' => $waitText]),
            default => __('You have reached your posting limit. Please try again in :duration.', ['duration' => $waitText]),
        };

        try {
            SpamEvent::query()->create([
                'user_id' => $user->id,
                'event_type' => 'rate_limited',
                'action_context' => $actionContext,
                'risk_score' => 25,
                'risk_level' => 'medium',
                'decision' => 'throttle',
                'signals' => ['limit_type' => $limitType, 'retry_after_seconds' => $retryAfter],
                'trust_tier' => $tier,
                'ip_hash' => $ipHash,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let telemetry persistence block HTTP 429 response.
        }

        throw new RateLimitExceededException($message, $retryAfter, $limitType);
    }
}
