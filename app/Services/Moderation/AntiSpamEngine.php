<?php

namespace App\Services\Moderation;

use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Models\Post;
use App\Models\SpamEvent;
use App\Models\User;
use App\Services\Social\PostingPolicyConfigService;
use App\Services\Social\TrustLevelResolver;
use Illuminate\Validation\ValidationException;

class AntiSpamEngine
{
    private const SUSPICIOUS_HOST_PATTERNS = [
        'bit.ly',
        'tinyurl.com',
        't.co',
        'goo.gl',
        'ow.ly',
        'is.gd',
        'rb.gy',
        'cutt.ly',
        'shorturl.at',
    ];

    public function __construct(
        private readonly PostingPolicyConfigService $policyConfig,
        private readonly TrustLevelResolver $trustResolver,
        private readonly DuplicateDetectionService $duplicateDetector,
        private readonly ContentFilterService $contentFilter,
    ) {}

    /**
     * Evaluate multi-signal spam & moderation risk on a post create/edit submission.
     * Returns classification and metadata without making network or AI calls on every request.
     *
     * @param  array{
     *     title?: string|null,
     *     content: string,
     *     community_id?: int|null,
     *     link_url?: string|null,
     *     ip_address?: string|null
     * }  $payload
     * @return array{
     *     risk_score: int,
     *     risk_level: 'low'|'medium'|'high'|'violation',
     *     decision: 'allow'|'throttle'|'pending_review'|'reject',
     *     moderation_status: ModerationStatus,
     *     lifecycle_status: PostLifecycleStatus,
     *     moderation_reason: string|null,
     *     signals: array<string, mixed>,
     *     content_fingerprint: string,
     *     link_fingerprint: string|null,
     *     filtered_content: string,
     *     filtered_title: string|null
     * }
     */
    public function evaluatePost(User $author, array $payload, string $actionContext = 'post.create', ?int $ignorePostId = null): array
    {
        $rawContent = $this->duplicateDetector->stripInvisibleOnly((string) $payload['content']);
        $rawTitle = isset($payload['title']) && $this->duplicateDetector->stripInvisibleOnly((string) $payload['title']) !== ''
            ? $this->duplicateDetector->stripInvisibleOnly((string) $payload['title'])
            : null;

        if ($rawContent === '') {
            throw ValidationException::withMessages([
                'content' => [__('Post content cannot be empty.')],
            ]);
        }

        $combinedRaw = trim($rawContent.' '.(string) ($rawTitle ?? ''));
        $filterCheck = $this->contentFilter->evaluate($combinedRaw);

        if ($filterCheck['blocked']) {
            $this->recordSpamEvent(
                user: $author,
                postId: $ignorePostId,
                eventType: 'keyword_blocked',
                actionContext: $actionContext,
                riskScore: 100,
                riskLevel: 'violation',
                decision: 'reject',
                signals: ['blocked_keywords' => $filterCheck['matched']],
                contentHash: $this->duplicateDetector->computeContentFingerprint($rawTitle, $rawContent),
                ipAddress: $payload['ip_address'] ?? null,
            );

            throw ValidationException::withMessages([
                'content' => [__('Your submission contains restricted content or blocked domains.')],
            ]);
        }

        $filteredContent = $this->contentFilter->assertAllowedOrMask($rawContent, 'content');
        $filteredTitle = $rawTitle !== null
            ? $this->contentFilter->assertAllowedOrMask($rawTitle, 'title')
            : null;

        $dup = $this->duplicateDetector->analyzePost(
            author: $author,
            title: $filteredTitle,
            content: $filteredContent,
            linkUrl: $payload['link_url'] ?? null,
            ignorePostId: $ignorePostId,
        );

        $tier = $this->trustResolver->resolve($author);
        $isNew = $this->trustResolver->isNewAccount($author);

        $score = 0;
        /** @var array<string, mixed> $signals */
        $signals = [];

        if ($filterCheck['flagged']) {
            $score += 65;
            $signals['flagged_keywords'] = $filterCheck['matched'];
        }

        if ($this->policyConfig->antiSpamEnabled()) {
            // 1. Exact duplicate by same author within duplicate window
            if ($dup['is_exact_duplicate']) {
                $score += 90;
                $signals['exact_duplicate_post_id'] = $dup['matched_post_id'];
            } elseif ($dup['is_near_duplicate']) {
                $score += 65;
                $signals['near_duplicate'] = [
                    'similarity_percent' => $dup['similarity_percent'],
                    'matched_post_id' => $dup['matched_post_id'],
                ];
            }

            // 2. Repeated identical link burst by same author
            if ($dup['same_link_recent_count'] >= 2) {
                $linkBoost = min(40, $dup['same_link_recent_count'] * 15);
                $score += $linkBoost;
                $signals['repeated_link_count'] = $dup['same_link_recent_count'];
            }

            // 3. Coordinated multi-account identical blast (>= 3 accounts in 15m)
            if ($dup['coordinated_accounts_count'] >= 2) {
                $score += 60;
                $signals['coordinated_accounts'] = $dup['coordinated_accounts_count'] + 1;
            }

            // 4. Excessive links & suspicious URL shorteners
            $urls = $this->duplicateDetector->extractNormalizedUrls($filteredContent, $payload['link_url'] ?? null);
            $maxLinks = $this->policyConfig->maxLinksPerPost();
            if ($maxLinks > 0 && count($urls) > $maxLinks) {
                $score += 25;
                $signals['excessive_links'] = count($urls);
            }

            $suspiciousHosts = $this->detectSuspiciousHosts($urls);
            if ($suspiciousHosts !== []) {
                $suspiciousAction = $this->policyConfig->suspiciousLinkAction();
                $signals['suspicious_links'] = $suspiciousHosts;
                if ($suspiciousAction === 'reject') {
                    $score += 90;
                } elseif ($suspiciousAction === 'flag') {
                    $score += $isNew ? 65 : 35;
                }
            }

            // 5. Excessive hashtags & mass @mentions
            $hashtagsCount = $this->countHashtags($filteredContent);
            $maxHashtags = $this->policyConfig->maxHashtagsPerPost();
            if ($maxHashtags > 0 && $hashtagsCount > $maxHashtags) {
                $score += 25;
                $signals['excessive_hashtags'] = $hashtagsCount;
            }

            $mentionsCount = $this->countMentions($filteredContent);
            $maxMentions = $this->policyConfig->maxMentionsPerPost();
            if ($maxMentions > 0 && $mentionsCount > $maxMentions) {
                $score += 30;
                $signals['mass_mentions'] = $mentionsCount;
            }

            // 6. Cross-community blast (posting across >= 3 distinct communities in 30m)
            if (isset($payload['community_id']) && (int) $payload['community_id'] > 0) {
                $distinctCommunities = (int) Post::query()
                    ->where('user_id', $author->id)
                    ->whereNotNull('community_id')
                    ->where('community_id', '!=', (int) $payload['community_id'])
                    ->where('created_at', '>=', now()->subMinutes(30))
                    ->distinct('community_id')
                    ->count('community_id');

                if ($distinctCommunities >= 2) {
                    $score += 30;
                    $signals['cross_community_blast'] = $distinctCommunities + 1;
                }
            }

            // 7. Fast cadence burst (multiple posts inside 5 minutes)
            $burstCount = Post::query()
                ->where('user_id', $author->id)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->when($ignorePostId !== null, static fn ($q) => $q->where('id', '!=', $ignorePostId))
                ->count();

            if ($burstCount >= 3) {
                $score += 20;
                $signals['rapid_posting_burst'] = $burstCount;
            }

            // 8. Prior violation / warning history
            if ($author->violations_count > 0 || $author->warnings_count >= 2) {
                $historyPenalty = min(25, ($author->violations_count * 10) + ($author->warnings_count * 5));
                $score += $historyPenalty;
                $signals['violation_history'] = [
                    'violations' => $author->violations_count,
                    'warnings' => $author->warnings_count,
                ];
            }

            // 9. New account multiplier ONLY when combined with another substantive spam signal (never standalone)
            if ($isNew && $score >= 25 && $urls !== []) {
                $score += 10;
                $signals['new_account_with_risk_signals'] = true;
            }
        }

        // Clamp score cleanly [0, 100]
        $riskScore = max(0, min(100, $score));

        // Classify risk level (Premium accounts are NEVER exempted from spam & moderation scoring)
        $mediumThreshold = $this->policyConfig->mediumRiskThreshold();
        $highThreshold = $this->policyConfig->highRiskThreshold();
        $violationThreshold = $this->policyConfig->violationRiskThreshold();

        if ($this->policyConfig->requireManualReviewAll() && ! $author->isStaff()) {
            $riskLevel = $riskScore >= $violationThreshold ? 'violation' : 'high';
            $decision = $riskScore >= $violationThreshold ? 'reject' : 'pending_review';
            $signals['manual_review_policy'] = true;
        } elseif ($riskScore >= $violationThreshold) {
            $riskLevel = 'violation';
            $decision = 'reject';
        } elseif ($riskScore >= $highThreshold && ($this->policyConfig->riskModerationEnabled() || $filterCheck['flagged'])) {
            $riskLevel = 'high';
            $decision = 'pending_review';
        } elseif ($riskScore >= $mediumThreshold) {
            $riskLevel = 'medium';
            $decision = 'throttle';
        } else {
            $riskLevel = 'low';
            $decision = 'allow';
        }

        if ($decision === 'reject') {
            $eventType = $dup['is_exact_duplicate'] ? 'duplicate_blocked' : ($dup['coordinated_accounts_count'] >= 2 ? 'coordinated_burst' : 'spam_scored');
            $this->recordSpamEvent(
                user: $author,
                postId: $ignorePostId,
                eventType: $eventType,
                actionContext: $actionContext,
                riskScore: $riskScore,
                riskLevel: $riskLevel,
                decision: 'reject',
                signals: $signals,
                contentHash: $dup['content_fingerprint'],
                ipAddress: $payload['ip_address'] ?? null,
            );

            $errorMessage = $dup['is_exact_duplicate']
                ? __('Duplicate post detected. Please avoid submitting identical content within a short period.')
                : __('This post triggered our automated safety and anti-spam checks and could not be published.');

            throw ValidationException::withMessages([
                'content' => [$errorMessage],
            ]);
        }

        $moderationStatus = $decision === 'pending_review'
            ? ModerationStatus::Pending
            : ModerationStatus::Approved;

        $lifecycleStatus = $decision === 'pending_review'
            ? PostLifecycleStatus::PendingReview
            : PostLifecycleStatus::Published;

        $reviewText = __('This post is under moderation review before public distribution.');
        $reason = $decision === 'pending_review' && is_string($reviewText)
            ? $reviewText
            : null;

        return [
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'decision' => $decision,
            'moderation_status' => $moderationStatus,
            'lifecycle_status' => $lifecycleStatus,
            'moderation_reason' => $reason,
            'signals' => $signals,
            'content_fingerprint' => $dup['content_fingerprint'],
            'link_fingerprint' => $dup['link_fingerprint'],
            'filtered_content' => $filteredContent,
            'filtered_title' => $filteredTitle,
        ];
    }

    /**
     * Evaluate comment content for duplicate/keyword/spam signals.
     *
     * @return array{filtered_content: string, flagged: bool, risk_score: int}
     */
    public function evaluateComment(User $author, Post $post, string $rawContent, ?string $ipAddress = null): array
    {
        $clean = $this->duplicateDetector->stripInvisibleOnly($rawContent);
        if ($clean === '') {
            throw ValidationException::withMessages([
                'content' => [__('Comment cannot be empty.')],
            ]);
        }

        $filterCheck = $this->contentFilter->evaluate($clean);
        if ($filterCheck['blocked']) {
            $this->recordSpamEvent(
                user: $author,
                postId: $post->id,
                eventType: 'keyword_blocked',
                actionContext: 'comment.create',
                riskScore: 100,
                riskLevel: 'violation',
                decision: 'reject',
                signals: ['blocked_keywords' => $filterCheck['matched']],
                contentHash: hash('sha256', $this->duplicateDetector->normalizeText($clean)),
                ipAddress: $ipAddress,
            );

            throw ValidationException::withMessages([
                'content' => [__('Your submission contains restricted content or blocked domains.')],
            ]);
        }

        if ($this->policyConfig->antiSpamEnabled() && $this->duplicateDetector->isDuplicateComment($author, $post, $clean)) {
            $this->recordSpamEvent(
                user: $author,
                postId: $post->id,
                eventType: 'duplicate_blocked',
                actionContext: 'comment.create',
                riskScore: 80,
                riskLevel: 'violation',
                decision: 'reject',
                signals: ['repeated_comment' => true],
                contentHash: hash('sha256', $this->duplicateDetector->normalizeText($clean)),
                ipAddress: $ipAddress,
            );

            throw ValidationException::withMessages([
                'content' => [__('Repeated or duplicate comment detected. Please write a meaningful reply.')],
            ]);
        }

        $masked = $this->contentFilter->assertAllowedOrMask($clean, 'content');
        $urls = $this->duplicateDetector->extractNormalizedUrls($masked);
        $flagged = $filterCheck['flagged'] || (count($urls) > 2 && $this->trustResolver->isNewAccount($author));

        return [
            'filtered_content' => $masked,
            'flagged' => $flagged,
            'risk_score' => $flagged ? 65 : 0,
        ];
    }

    /**
     * Record any non-low-risk event associated with a created post (e.g. Pending Review or Medium Throttle).
     *
     * @param  array<string, mixed>  $evaluation
     */
    public function logPostEvaluationIfNotable(
        User $author,
        Post $post,
        array $evaluation,
        string $actionContext = 'post.create',
        ?string $ipAddress = null,
    ): void {
        $riskLevel = is_string($evaluation['risk_level'] ?? null) ? $evaluation['risk_level'] : 'low';
        if ($riskLevel === 'low') {
            return;
        }

        $signals = is_array($evaluation['signals'] ?? null) ? $evaluation['signals'] : [];
        $eventType = isset($signals['coordinated_accounts'])
            ? 'coordinated_burst'
            : (isset($signals['near_duplicate']) ? 'duplicate_blocked' : 'spam_scored');

        $this->recordSpamEvent(
            user: $author,
            postId: $post->id,
            eventType: $eventType,
            actionContext: $actionContext,
            riskScore: (int) ($evaluation['risk_score'] ?? 0),
            riskLevel: $riskLevel,
            decision: (string) ($evaluation['decision'] ?? 'allow'),
            signals: $signals,
            contentHash: (string) ($evaluation['content_fingerprint'] ?? ''),
            ipAddress: $ipAddress,
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function recordSpamEvent(
        User $user,
        ?int $postId,
        string $eventType,
        string $actionContext,
        int $riskScore,
        string $riskLevel,
        string $decision,
        array $signals,
        ?string $contentHash,
        ?string $ipAddress,
    ): void {
        try {
            SpamEvent::query()->create([
                'user_id' => $user->id,
                'post_id' => $postId,
                'event_type' => $eventType,
                'action_context' => $actionContext,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'decision' => $decision,
                'signals' => $signals,
                'trust_tier' => $this->trustResolver->resolve($user)->value,
                'content_hash' => $contentHash,
                'ip_hash' => $ipAddress !== null && $ipAddress !== '' ? hash('sha256', $ipAddress) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never crash post workflow if event logging fails.
        }
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function detectSuspiciousHosts(array $urls): array
    {
        $hits = [];
        foreach ($urls as $url) {
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            foreach (self::SUSPICIOUS_HOST_PATTERNS as $pattern) {
                if ($host === $pattern || str_ends_with($host, '.'.$pattern)) {
                    $hits[] = $host;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    private function countHashtags(string $content): int
    {
        $count = preg_match_all('/#[\p{L}\p{N}_]{2,40}/u', $content);

        return is_int($count) ? $count : 0;
    }

    private function countMentions(string $content): int
    {
        $count = preg_match_all('/@[A-Za-z0-9_.-]{2,40}/u', $content);

        return is_int($count) ? $count : 0;
    }
}
