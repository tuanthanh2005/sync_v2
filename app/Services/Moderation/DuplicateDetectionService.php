<?php

namespace App\Services\Moderation;

use App\Enums\ModerationStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\Social\PostingPolicyConfigService;

class DuplicateDetectionService
{
    private const TRACKING_QUERY_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref',
    ];

    public function __construct(
        private readonly PostingPolicyConfigService $policyConfig,
    ) {}

    public function normalizeText(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        // Remove zero-width & invisible control characters before collapsing whitespace.
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\p{Cc}]+/u', ' ', $text) ?? $text;
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $cleaned));

        return mb_strtolower($collapsed);
    }

    public function stripInvisibleOnly(string $text): string
    {
        $stripped = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]+/u', '', $text) ?? $text;

        return trim($stripped);
    }

    public function computeContentFingerprint(?string $title, string $content): string
    {
        $combined = $this->normalizeText(trim((string) ($title ?? '')).' '.trim($content));

        return hash('sha256', $combined);
    }

    /**
     * Extract normalized unique URLs from post text + optional explicit link_url.
     *
     * @return list<string>
     */
    public function extractNormalizedUrls(string $content, ?string $explicitLink = null): array
    {
        $rawUrls = [];
        if ($explicitLink !== null && trim($explicitLink) !== '') {
            $rawUrls[] = trim($explicitLink);
        }

        if (preg_match_all('/https?:\/\/[^\s<>"\'\)\]]+/iu', $content, $matches) > 0) {
            foreach ($matches[0] as $found) {
                $rawUrls[] = rtrim($found, '.,;:!?');
            }
        }

        $normalized = [];
        foreach ($rawUrls as $url) {
            $norm = $this->normalizeUrl($url);
            if ($norm !== null) {
                $normalized[] = $norm;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $queryStr = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            foreach (self::TRACKING_QUERY_PARAMS as $trackingKey) {
                unset($params[$trackingKey]);
            }
            if ($params !== []) {
                ksort($params);
                $queryStr = '?'.http_build_query($params);
            }
        }

        return "{$scheme}://{$host}{$path}{$queryStr}";
    }

    public function primaryLinkFingerprint(string $content, ?string $explicitLink = null): ?string
    {
        $urls = $this->extractNormalizedUrls($content, $explicitLink);
        if ($urls === []) {
            return null;
        }

        return hash('sha256', $urls[0]);
    }

    /**
     * Evaluate whether a submission duplicates the author's recent content or represents a cross-account flood.
     *
     * @return array{
     *     is_exact_duplicate: bool,
     *     is_near_duplicate: bool,
     *     similarity_percent: int,
     *     matched_post_id: int|null,
     *     coordinated_accounts_count: int,
     *     same_link_recent_count: int,
     *     content_fingerprint: string,
     *     link_fingerprint: string|null
     * }
     */
    public function analyzePost(
        User $author,
        ?string $title,
        string $content,
        ?string $linkUrl = null,
        ?int $ignorePostId = null,
    ): array {
        $contentFingerprint = $this->computeContentFingerprint($title, $content);
        $linkFingerprint = $this->primaryLinkFingerprint($content, $linkUrl);
        $normalizedText = $this->normalizeText(trim((string) ($title ?? '')).' '.trim($content));

        if (! $this->policyConfig->duplicateDetectionEnabled()) {
            return [
                'is_exact_duplicate' => false,
                'is_near_duplicate' => false,
                'similarity_percent' => 0,
                'matched_post_id' => null,
                'coordinated_accounts_count' => 0,
                'same_link_recent_count' => 0,
                'content_fingerprint' => $contentFingerprint,
                'link_fingerprint' => $linkFingerprint,
            ];
        }

        $windowStart = now()->subHours($this->policyConfig->duplicateWindowHours());
        $threshold = $this->policyConfig->duplicateSimilarityThreshold();

        // Check exact duplicate by the same author within the configured window (fast indexed lookup).
        $exactMatch = Post::query()
            ->where('user_id', $author->id)
            ->where('content_fingerprint', $contentFingerprint)
            ->where('created_at', '>=', $windowStart)
            ->when($ignorePostId !== null, static fn ($q) => $q->where('id', '!=', $ignorePostId))
            ->first(['id']);

        if ($exactMatch !== null) {
            return [
                'is_exact_duplicate' => true,
                'is_near_duplicate' => true,
                'similarity_percent' => 100,
                'matched_post_id' => $exactMatch->id,
                'coordinated_accounts_count' => 1,
                'same_link_recent_count' => 0,
                'content_fingerprint' => $contentFingerprint,
                'link_fingerprint' => $linkFingerprint,
            ];
        }

        // Near-duplicate check across the author's last 10 recent posts inside the window (lightweight for shared hosting).
        $maxSimilarity = 0;
        $matchedId = null;

        if (mb_strlen($normalizedText) >= 24) {
            $recentPosts = Post::query()
                ->where('user_id', $author->id)
                ->where('created_at', '>=', $windowStart)
                ->when($ignorePostId !== null, static fn ($q) => $q->where('id', '!=', $ignorePostId))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'title', 'content']);

            foreach ($recentPosts as $candidate) {
                $candidateText = $this->normalizeText(trim((string) ($candidate->title ?? '')).' '.trim($candidate->content));
                $sim = $this->computeTextSimilarity($normalizedText, $candidateText);
                if ($sim > $maxSimilarity) {
                    $maxSimilarity = $sim;
                    $matchedId = $candidate->id;
                }
                if ($maxSimilarity >= $threshold) {
                    break;
                }
            }
        }

        $sameLinkRecentCount = 0;
        if ($linkFingerprint !== null) {
            $sameLinkRecentCount = Post::query()
                ->where('user_id', $author->id)
                ->where('link_fingerprint', $linkFingerprint)
                ->where('created_at', '>=', now()->subHours(6))
                ->when($ignorePostId !== null, static fn ($q) => $q->where('id', '!=', $ignorePostId))
                ->count();
        }

        // Detect coordinated multi-account exact flooding in short 15m window (never blocks on two random users alone).
        $coordinatedCount = 0;
        if (mb_strlen($normalizedText) >= 20) {
            $coordinatedCount = (int) Post::query()
                ->where('content_fingerprint', $contentFingerprint)
                ->where('user_id', '!=', $author->id)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->distinct('user_id')
                ->count('user_id');
        }

        return [
            'is_exact_duplicate' => false,
            'is_near_duplicate' => $maxSimilarity >= $threshold,
            'similarity_percent' => $maxSimilarity,
            'matched_post_id' => $matchedId,
            'coordinated_accounts_count' => $coordinatedCount,
            'same_link_recent_count' => $sameLinkRecentCount,
            'content_fingerprint' => $contentFingerprint,
            'link_fingerprint' => $linkFingerprint,
        ];
    }

    public function isDuplicateComment(User $author, Post $post, string $content): bool
    {
        if (! $this->policyConfig->duplicateDetectionEnabled()) {
            return false;
        }

        $normalized = $this->normalizeText($content);
        if ($normalized === '') {
            return false;
        }

        $recent = Comment::query()
            ->where('user_id', $author->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->where('moderation_status', '!=', ModerationStatus::Removed->value)
            ->latest('id')
            ->limit(8)
            ->get(['post_id', 'content']);

        $sameTextCount = 0;
        foreach ($recent as $item) {
            if ($this->normalizeText($item->content) === $normalized) {
                $sameTextCount++;
                if ($item->post_id === $post->id) {
                    return true;
                }
            }
        }

        return $sameTextCount >= 2;
    }

    /**
     * Fast token Jaccard + short character similarity bounded to 600 chars for Hostinger CPU safety.
     */
    private function computeTextSimilarity(string $a, string $b): int
    {
        if ($a === $b) {
            return 100;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0;
        }

        $ratio = min($lenA, $lenB) / max($lenA, $lenB);
        if ($ratio < 0.55) {
            return 0;
        }

        $tokensA = array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $a) ?: []));
        $tokensB = array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $b) ?: []));

        $jaccard = 0.0;
        if ($tokensA !== [] && $tokensB !== []) {
            $intersection = count(array_intersect($tokensA, $tokensB));
            $union = count(array_unique(array_merge($tokensA, $tokensB)));
            $jaccard = ($intersection / $union) * 100.0;
        }

        $sliceA = mb_substr($a, 0, 500);
        $sliceB = mb_substr($b, 0, 500);
        $pct = 0.0;
        similar_text($sliceA, $sliceB, $pct);

        return (int) round(max($jaccard, $pct));
    }
}
