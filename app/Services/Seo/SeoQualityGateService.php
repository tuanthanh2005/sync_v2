<?php

namespace App\Services\Seo;

use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Enums\SeoIndexEligibility;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;

class SeoQualityGateService
{
    public function __construct(
        private readonly SeoConfigService $config,
    ) {}

    public function contentFingerprint(string $text): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''));

        return hash('sha256', $normalized);
    }

    /**
     * Evaluate whether a Post is eligible for public Search Indexing.
     * Note: Premium membership or Boost Post NEVER bypasses this quality gate.
     *
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluatePost(Post $post): array
    {
        if (! $this->config->isIndexingAllowed()) {
            return $this->excluded('indexing_disabled_in_environment', 0);
        }

        if ($post->deleted_at !== null) {
            return $this->excluded('deleted', 0);
        }

        if ($post->visibility !== PostVisibility::Public) {
            return $this->excluded('non_public_visibility', 0);
        }

        if ($post->moderation_status !== ModerationStatus::Approved) {
            return $this->excluded('not_approved_by_moderation', 0);
        }

        if ($post->is_spam) {
            return $this->excluded('marked_as_spam', 0);
        }

        if ($post->seo_noindex) {
            return $this->excluded('admin_forced_noindex', 0);
        }

        if ($post->published_at === null) {
            return $this->excluded('unpublished', 0);
        }

        $post->loadMissing(['author', 'community']);

        if ($post->author === null || ! $post->author->isActive()) {
            return $this->excluded('author_inactive_or_suspended', 0);
        }

        if ($post->community !== null && ($post->community->visibility !== 'public' || $post->community->status !== 'active')) {
            return $this->excluded('private_or_inactive_community', 0);
        }

        $plainText = trim(strip_tags(trim((string) ($post->title ?? '')).' '.$post->content));
        $charCount = mb_strlen($plainText);
        $words = preg_split('/\s+/u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;

        $minWords = max(3, $this->config->int('min_post_words', (int) config('syncviet.seo.min_post_words_for_index', 12)));
        $minChars = max(20, $this->config->int('min_post_chars', (int) config('syncviet.seo.min_post_chars_for_index', 60)));

        $mediaCount = $post->relationLoaded('media') ? $post->media->count() : $post->media()->count();
        $hasSubstantialMedia = $mediaCount > 0 && $charCount >= 25;

        if ($charCount < $minChars && $wordCount < $minWords && ! $hasSubstantialMedia) {
            return [
                'eligibility' => SeoIndexEligibility::PendingQualityReview,
                'reason' => 'thin_content',
                'quality_score' => min(35, (int) round(($charCount / max(1, $minChars)) * 35)),
                'robots' => 'noindex, follow',
            ];
        }

        // Detect spammy link stuffing (excessive URLs relative to body text)
        $urlCount = preg_match_all('#https?://[^\s]+#i', $post->content);
        if (is_int($urlCount) && $urlCount >= 4 && $wordCount < 40) {
            return $this->excluded('excessive_external_links', 15);
        }

        // Duplicate content detection (earlier approved public post holds canonical priority)
        $fingerprint = $post->content_fingerprint ?: $this->contentFingerprint($plainText);
        $duplicateExists = Post::query()
            ->where('id', '<', $post->id)
            ->where('content_fingerprint', $fingerprint)
            ->where('visibility', PostVisibility::Public->value)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicateExists) {
            return $this->excluded('duplicate_content', 20);
        }

        // Compute factual technical quality score (0-100)
        $score = 45;
        if (is_string($post->title) && mb_strlen(trim($post->title)) >= 12) {
            $score += 15;
        }
        if ($wordCount >= 40) {
            $score += 15;
        } elseif ($wordCount >= $minWords) {
            $score += 8;
        }
        if ($mediaCount > 0) {
            $score += 10;
        }
        if ($post->comments_count > 0 || $post->reactions_count > 0) {
            $score += 10;
        }
        if ($post->author->trust_level >= 2 || $post->author->email_verified_at !== null) {
            $score += 5;
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => min(100, $score),
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluateTranslation(SeoContentTranslation $translation, ?Post $sourcePost = null): array
    {
        if (! $this->config->isIndexingAllowed()) {
            return $this->excluded('indexing_disabled_in_environment', 0);
        }

        if (! $translation->isPublishedAndApproved()) {
            return $this->excluded('translation_not_published_or_unapproved', 0);
        }

        if ($sourcePost !== null) {
            $sourceEval = $this->evaluatePost($sourcePost);
            if ($sourceEval['eligibility'] !== SeoIndexEligibility::Eligible) {
                return $this->excluded('source_entity_not_eligible', 0);
            }
        }

        $bodyLen = mb_strlen(trim(strip_tags($translation->translated_body)));
        if ($bodyLen < 40) {
            return $this->excluded('translation_thin_content', 20);
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => $translation->is_stale ? 65 : 85,
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluateCommunity(Community $community): array
    {
        if (! $this->config->isIndexingAllowed()) {
            return $this->excluded('indexing_disabled_in_environment', 0);
        }

        if ($community->deleted_at !== null || $community->status !== 'active' || $community->visibility !== 'public') {
            return $this->excluded('private_or_inactive_community', 0);
        }

        if ($community->seo_noindex) {
            return $this->excluded('admin_forced_noindex', 0);
        }

        $score = 60;
        if (is_string($community->description) && mb_strlen(trim($community->description)) >= 30) {
            $score += 20;
        }
        if ($community->posts_count > 0) {
            $score += 10;
        }
        if ($community->members_count >= 2) {
            $score += 10;
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => min(100, $score),
            'robots' => 'index, follow, max-image-preview:large',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluateProfile(User $user): array
    {
        if (! $this->config->isIndexingAllowed() || ! $this->config->bool('index_user_profiles', true)) {
            return $this->excluded('profile_indexing_disabled', 0);
        }

        if (! $user->isActive()) {
            return $this->excluded('user_inactive_or_banned', 0);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->excluded('unverified_user', 0);
        }

        $user->loadMissing('profile');

        $hasBio = $user->profile !== null && (
            (is_string($user->profile->bio) && mb_strlen(trim($user->profile->bio)) >= 15)
            || (is_string($user->profile->headline) && mb_strlen(trim($user->profile->headline)) >= 10)
        );
        $publicPostsCount = $user->posts()->publicVisible()->count();

        if (! $hasBio && $publicPostsCount === 0) {
            return [
                'eligibility' => SeoIndexEligibility::PendingQualityReview,
                'reason' => 'empty_profile',
                'quality_score' => 25,
                'robots' => 'noindex, follow',
            ];
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => min(100, 60 + ($hasBio ? 20 : 0) + min(20, $publicPostsCount * 5)),
            'robots' => 'index, follow',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluateTopic(Hashtag $hashtag): array
    {
        if (! $this->config->isIndexingAllowed()) {
            return $this->excluded('indexing_disabled_in_environment', 0);
        }

        if ($hashtag->is_restricted || $hashtag->is_spam) {
            return $this->excluded('restricted_or_spam_topic', 0);
        }

        $minPosts = max(1, $this->config->int('min_topic_posts', (int) config('syncviet.seo.min_topic_posts_for_index', 2)));
        $eligiblePosts = $hashtag->posts()->publicVisible()->count();

        if ($eligiblePosts < $minPosts) {
            return [
                'eligibility' => SeoIndexEligibility::PendingQualityReview,
                'reason' => 'insufficient_topic_posts',
                'quality_score' => 25,
                'robots' => 'noindex, follow',
            ];
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => min(100, 60 + min(40, $eligiblePosts * 4)),
            'robots' => 'index, follow',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string|null, quality_score: int, robots: string}
     */
    public function evaluatePolicy(PolicyPage $policy): array
    {
        if (! $this->config->isIndexingAllowed()) {
            return $this->excluded('indexing_disabled_in_environment', 0);
        }

        if (! $policy->isPubliclyAccessible()) {
            return $this->excluded('draft_policy', 0);
        }

        if (! $policy->index_eligible) {
            return $this->excluded('admin_forced_noindex', 0);
        }

        return [
            'eligibility' => SeoIndexEligibility::Eligible,
            'reason' => null,
            'quality_score' => 90,
            'robots' => 'index, follow, max-image-preview:large',
        ];
    }

    /**
     * @return array{eligibility: SeoIndexEligibility, reason: string, quality_score: int, robots: string}
     */
    private function excluded(string $reason, int $score): array
    {
        return [
            'eligibility' => SeoIndexEligibility::Excluded,
            'reason' => $reason,
            'quality_score' => $score,
            'robots' => 'noindex, nofollow',
        ];
    }
}
