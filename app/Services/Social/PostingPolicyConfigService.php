<?php

namespace App\Services\Social;

use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;

class PostingPolicyConfigService
{
    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    public function newMemberAgeDays(): int
    {
        return max(1, min(90, (int) $this->settings->get('posting_new_member_age_days', 7)));
    }

    public function maxPostLength(): int
    {
        return max(100, min(10_000, (int) $this->settings->get('content_post_max_length', 3000)));
    }

    public function maxCommentLength(): int
    {
        return max(100, min(5_000, (int) $this->settings->get('content_comment_max_length', 1500)));
    }

    public function maxEditsPerDay(): int
    {
        return max(1, min(200, (int) $this->settings->get('post_edits_per_day_limit', 15)));
    }

    public function editWindowMinutes(): int
    {
        return max(0, min(525_600, (int) $this->settings->get('post_edit_window_minutes', 0)));
    }

    public function antiSpamEnabled(): bool
    {
        return (bool) $this->settings->get('antispam_enabled', true);
    }

    public function duplicateDetectionEnabled(): bool
    {
        return (bool) $this->settings->get('duplicate_detection_enabled', true);
    }

    public function duplicateWindowHours(): int
    {
        return max(1, min(720, (int) $this->settings->get('duplicate_detection_window_hours', 24)));
    }

    public function duplicateSimilarityThreshold(): int
    {
        return max(50, min(100, (int) $this->settings->get('duplicate_similarity_threshold', 85)));
    }

    public function mediumRiskThreshold(): int
    {
        return max(10, min(90, (int) $this->settings->get('antispam_medium_threshold', 30)));
    }

    public function highRiskThreshold(): int
    {
        $review = max(20, min(95, (int) $this->settings->get('moderation_review_threshold', 60)));
        $antiSpam = max(20, min(95, (int) $this->settings->get('antispam_high_threshold', $review)));

        return min($antiSpam, $review);
    }

    public function violationRiskThreshold(): int
    {
        return max(30, min(100, (int) $this->settings->get('antispam_violation_threshold', 85)));
    }

    public function maxLinksPerPost(): int
    {
        return max(0, min(50, (int) $this->settings->get('antispam_max_links_per_post', 5)));
    }

    public function maxMentionsPerPost(): int
    {
        return max(0, min(50, (int) $this->settings->get('antispam_max_mentions_per_post', 10)));
    }

    public function maxHashtagsPerPost(): int
    {
        return max(0, min(50, (int) $this->settings->get('antispam_max_hashtags_per_post', 10)));
    }

    public function riskModerationEnabled(): bool
    {
        return (bool) $this->settings->get('moderation_risk_based_enabled', true);
    }

    public function requireManualReviewAll(): bool
    {
        return (bool) $this->settings->get('moderation_require_manual_review', false);
    }

    public function suspiciousLinkAction(): string
    {
        $val = (string) $this->settings->get('moderation_suspicious_link_action', 'flag');

        return in_array($val, ['allow', 'flag', 'reject'], true) ? $val : 'flag';
    }

    public function trendingFeedEnabled(): bool
    {
        return (bool) $this->settings->get('feed_enable_trending', true);
    }

    public function recommendedFeedEnabled(): bool
    {
        return (bool) $this->settings->get('feed_enable_recommended', true);
    }

    public function freshDiscoveryEnabled(): bool
    {
        return (bool) $this->settings->get('feed_enable_fresh_discovery', true);
    }

    public function candidateWindowHours(): int
    {
        return max(12, min(720, (int) $this->settings->get('feed_candidate_window_hours', 168)));
    }

    public function refreshIntervalMinutes(): int
    {
        return max(1, min(60, (int) $this->settings->get('feed_refresh_interval_minutes', 5)));
    }

    public function feedPageSize(): int
    {
        return max(5, min(30, (int) $this->settings->get('feed_page_size', 10)));
    }

    public function authorDiversityMax(): int
    {
        return max(1, min(10, (int) $this->settings->get('feed_author_diversity_max', 2)));
    }

    public function categoryDiversityEnabled(): bool
    {
        return (bool) $this->settings->get('feed_category_diversity_enabled', true);
    }

    /**
     * @return array{ranking: int, fresh: int, discovery: int}
     */
    public function recommendationAllocation(): array
    {
        $ranking = max(10, min(100, (int) $this->settings->get('feed_allocation_ranking_pct', 70)));
        $fresh = max(0, min(80, (int) $this->settings->get('feed_allocation_fresh_pct', 20)));
        $discovery = max(0, min(50, (int) $this->settings->get('feed_allocation_discovery_pct', 10)));

        return [
            'ranking' => $ranking,
            'fresh' => $fresh,
            'discovery' => $discovery,
        ];
    }

    /**
     * @return array{
     *     human_view: float,
     *     reaction: float,
     *     comment: float,
     *     bookmark: float,
     *     share: float,
     *     decay_base_hours: float,
     *     decay_exponent: float,
     *     per_user_cap: int,
     *     view_engagement_ratio: int
     * }
     */
    public function trendingWeights(): array
    {
        return [
            'human_view' => max(0.0, min(5.0, (float) $this->settings->get('trending_weight_human_view', 0.1))),
            'reaction' => max(0.0, min(20.0, (float) $this->settings->get('trending_weight_reaction', 2.0))),
            'comment' => max(0.0, min(30.0, (float) $this->settings->get('trending_weight_comment', 4.0))),
            'bookmark' => max(0.0, min(30.0, (float) $this->settings->get('trending_weight_bookmark', 5.0))),
            'share' => max(0.0, min(30.0, (float) $this->settings->get('trending_weight_share', 6.0))),
            'decay_base_hours' => max(1.0, min(168.0, (float) $this->settings->get('trending_decay_base_hours', 24.0))),
            'decay_exponent' => max(0.1, min(4.0, (float) $this->settings->get('trending_decay_exponent', 1.5))),
            'per_user_cap' => max(1, min(100, (int) $this->settings->get('trending_per_user_contribution_cap', 20))),
            'view_engagement_ratio' => max(0, min(5_000, (int) $this->settings->get('trending_view_engagement_ratio', 200))),
        ];
    }

    /**
     * @return list<string>
     */
    public static function managedGroups(): array
    {
        return ['publishing', 'antispam', 'moderation', 'feed', 'trending'];
    }

    /**
     * @return array<string, mixed>
     */
    public function allManagedValues(): array
    {
        $all = $this->settings->all();
        $groups = self::managedGroups();
        $defaults = SystemSettingService::defaults();

        $subset = [];
        foreach ($defaults as $key => $meta) {
            if (in_array($meta['group'], $groups, true) || in_array($key, [
                'feature_posting',
                'content_post_max_length',
                'content_comment_max_length',
                'upload_max_mb_post',
            ], true)) {
                $subset[$key] = $all[$key] ?? $meta['value'];
            }
        }

        return $subset;
    }

    public function resetSectionToDefaults(string $section, User $actor, AuditLogger $auditLogger): void
    {
        $targetGroups = $section === 'all' ? self::managedGroups() : [$section];
        $defaults = SystemSettingService::defaults();
        $resetPayload = [];

        foreach ($defaults as $key => $meta) {
            if (in_array($meta['group'], $targetGroups, true)) {
                $resetPayload[$key] = $meta['value'];
            }
        }

        if ($section === 'publishing' || $section === 'all') {
            $resetPayload['feature_posting'] = true;
            $resetPayload['content_post_max_length'] = $defaults['content_post_max_length']['value'];
            $resetPayload['content_comment_max_length'] = $defaults['content_comment_max_length']['value'];
        }

        if ($resetPayload !== []) {
            $this->settings->updateMany($resetPayload, $actor, $auditLogger);
        }
    }
}
