<?php

namespace App\Services\Growth;

use App\Enums\AccountType;
use App\Enums\ModerationStatus;
use App\Enums\UserStatus;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use Illuminate\Support\Facades\URL;

class RetentionAndDigestService
{
    public function __construct(
        private readonly GrowthConfigService $config,
    ) {}

    /**
     * @return array{weekly_digest: bool, reengagement: bool, referral_updates: bool, community_updates: bool, marketing_emails: bool}
     */
    public function preferencesFor(User $user): array
    {
        $raw = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        return [
            'weekly_digest' => array_key_exists('weekly_digest', $raw) ? (bool) $raw['weekly_digest'] : true,
            'reengagement' => array_key_exists('reengagement', $raw) ? (bool) $raw['reengagement'] : true,
            'referral_updates' => array_key_exists('referral_updates', $raw) ? (bool) $raw['referral_updates'] : true,
            'community_updates' => array_key_exists('community_updates', $raw) ? (bool) $raw['community_updates'] : true,
            // Marketing emails strictly opt-in (false by default)
            'marketing_emails' => array_key_exists('marketing_emails', $raw) ? (bool) $raw['marketing_emails'] : false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{weekly_digest: bool, reengagement: bool, referral_updates: bool, community_updates: bool, marketing_emails: bool}
     */
    public function updatePreferences(User $user, array $input): array
    {
        $current = $this->preferencesFor($user);
        foreach (array_keys($current) as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = (bool) $input[$key];
            }
        }

        $user->forceFill(['notification_preferences' => $current])->save();

        $this->config->logActivity('growth.retention.preferences_updated', $user, $user->id, User::class, $user->id, $current);

        return $current;
    }

    public function unsubscribeUrl(User $user, string $channel = 'all'): string
    {
        return URL::temporarySignedRoute(
            'growth.unsubscribe',
            now()->addDays(60),
            ['user' => $user->id, 'channel' => $channel],
        );
    }

    /**
     * Send gentle, frequency-capped re-engagement notifications for inactive verified human users
     * (default stages: 3d, 7d, 30d; strictly respects opt-out preferences & never uses fake urgency).
     *
     * @return array{sent: int, skipped: int}
     */
    public function processInactiveReengagement(int $batchLimit = 50): array
    {
        if (! $this->config->bool('retention_reengagement_enabled', false)) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $stagesRaw = array_values(array_filter(
            array_map('intval', explode(',', $this->config->string('retention_reengagement_days', '3,7,30'))),
            static fn (int $d): bool => $d > 0,
        ));
        if ($stagesRaw === []) {
            $stagesRaw = [3, 7, 30];
        }
        sort($stagesRaw);

        $freqCapHours = max(24, $this->config->int('retention_frequency_cap_hours', 72));
        $now = now();
        $sent = 0;
        $skipped = 0;

        $candidates = User::query()
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $now->copy()->subDays($stagesRaw[0]))
            ->where('reengagement_stage', '<', count($stagesRaw))
            ->limit($batchLimit)
            ->get();

        foreach ($candidates as $user) {
            $prefs = $this->preferencesFor($user);
            if (! $prefs['reengagement']) {
                $skipped++;

                continue;
            }

            if ($user->last_reengagement_sent_at !== null && $user->last_reengagement_sent_at->gt($now->copy()->subHours($freqCapHours))) {
                $skipped++;

                continue;
            }

            $nextStageIndex = $user->reengagement_stage;
            $requiredDays = $stagesRaw[$nextStageIndex] ?? null;
            if ($requiredDays === null || $user->last_activity_at === null || $user->last_activity_at->gt($now->copy()->subDays($requiredDays))) {
                $skipped++;

                continue;
            }

            $user->notify(new SocialActivityNotification(
                eventKey: "growth.reengagement.stage_{$requiredDays}d",
                titleVi: 'Có nhiều thảo luận mới phù hợp với chủ đề bạn quan tâm trên SysNoxis.',
                titleEn: 'New discussions matching your topics and communities are waiting on SysNoxis.',
                url: route('home', [], false),
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'system',
                titleKey: 'New discussions matching your topics and communities are waiting on SysNoxis.',
            ));

            $user->forceFill([
                'last_reengagement_sent_at' => $now,
                'reengagement_stage' => $nextStageIndex + 1,
            ])->saveQuietly();

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Send rule-based weekly community digest notifications respecting user opt-out & frequency cap.
     *
     * @return array{sent: int, skipped: int}
     */
    public function processWeeklyDigests(int $batchLimit = 50): array
    {
        if (! $this->config->bool('retention_digest_enabled', false)) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $recentPostsCount = Post::query()
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($recentPostsCount === 0) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $freqCapHours = max(24, $this->config->int('retention_frequency_cap_hours', 72));
        $sent = 0;
        $skipped = 0;

        $users = User::query()
            ->where('account_type', AccountType::Human->value)
            ->where('is_test_account', false)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->limit($batchLimit)
            ->get();

        foreach ($users as $user) {
            $prefs = $this->preferencesFor($user);
            if (! $prefs['weekly_digest']) {
                $skipped++;

                continue;
            }

            if ($user->last_reengagement_sent_at !== null && $user->last_reengagement_sent_at->gt(now()->subHours($freqCapHours))) {
                $skipped++;

                continue;
            }

            $joinedCount = CommunityMember::query()->where('user_id', $user->id)->count();

            $digestKey = $joinedCount > 0
                ? 'Weekly digest: :count new discussions across SysNoxis communities.'
                : 'Explore :count trending discussions this week on SysNoxis.';

            $user->notify(new SocialActivityNotification(
                eventKey: 'growth.weekly_digest',
                titleVi: $joinedCount > 0
                    ? "Tóm tắt tuần: {$recentPostsCount} bài viết mới từ cộng đồng SysNoxis."
                    : "Khám phá {$recentPostsCount} thảo luận nổi bật tuần này trên SysNoxis.",
                titleEn: $joinedCount > 0
                    ? "Weekly digest: {$recentPostsCount} new discussions across SysNoxis communities."
                    : "Explore {$recentPostsCount} trending discussions this week on SysNoxis.",
                url: route('home', [], false),
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'system',
                titleKey: $digestKey,
                titleParams: ['count' => $recentPostsCount],
            ));

            $user->forceFill(['last_reengagement_sent_at' => now()])->saveQuietly();
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
