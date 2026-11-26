<?php

namespace App\Services\Ai;

use App\Models\AiContentDraft;
use App\Models\Post;
use App\Services\Seo\LanguageDetectionService;
use App\Services\Seo\SeoQualityGateService;

class AiContentQualityService
{
    public function __construct(
        private readonly SeoQualityGateService $seoQualityGate,
        private readonly LanguageDetectionService $languageDetection,
    ) {}

    /**
     * Evaluate an AI draft for quality, originality, language fidelity, safety, and PII hygiene.
     * Never inflates score based on model tier.
     *
     * @return array{status: string, score: int, flags: list<string>, duplicate_of_post_id: int|null, duplicate_of_draft_id: int|null, content_hash: string}
     */
    public function evaluateDraft(AiContentDraft $draft): array
    {
        $cleanTitle = trim(strip_tags($draft->title));
        $cleanBody = trim(strip_tags($draft->body));
        $combined = trim($cleanTitle.' '.$cleanBody);
        $contentHash = $this->seoQualityGate->contentFingerprint($combined);

        $flags = [];
        $score = 80;

        if ($cleanTitle === '' || mb_strlen($cleanTitle) < 10) {
            $flags[] = 'weak_or_missing_title';
            $score -= 20;
        }

        if ($cleanBody === '' || mb_strlen($cleanBody) < 120) {
            $flags[] = 'thin_body_content';
            $score -= 35;
        }

        // Unsafe executable markup check
        if (preg_match('/<\s*(script|iframe|object|embed|form|on\w+\s*=)/i', $draft->body) === 1) {
            $flags[] = 'unsafe_html_markup_detected';
            $score -= 50;
        }

        // PII pattern guard (emails / potential secrets)
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $cleanBody) === 1) {
            $flags[] = 'possible_pii_email_detected';
            $score -= 15;
        }

        // Excessive URL stuffing check
        $urlMatches = preg_match_all('#https?://[^\s]+#i', $cleanBody);
        if (is_int($urlMatches) && $urlMatches > 6) {
            $flags[] = 'excessive_external_links';
            $score -= 20;
        }

        // Duplicate check against existing published posts (indexed fingerprint lookup — zero full-table scan)
        $dupPostId = Post::query()
            ->where('content_fingerprint', $contentHash)
            ->whereNull('deleted_at')
            ->value('id');

        if ($dupPostId !== null) {
            $flags[] = 'exact_duplicate_of_existing_post';
            $score -= 60;
        }

        // Duplicate check against prior AI drafts
        $dupDraftId = AiContentDraft::query()
            ->where('id', '!=', $draft->id)
            ->where('content_hash', $contentHash)
            ->whereNotIn('status', ['rejected', 'cancelled', 'archived'])
            ->value('id');

        if ($dupDraftId !== null) {
            $flags[] = 'exact_duplicate_of_existing_draft';
            $score -= 50;
        }

        // Near-duplicate title check (bounded recent window for Shared Hosting performance)
        if ($cleanTitle !== '') {
            $recentTitles = Post::query()
                ->whereNotNull('title')
                ->latest('id')
                ->limit(150)
                ->pluck('title');

            foreach ($recentTitles as $existingTitle) {
                similar_text(mb_strtolower($cleanTitle), mb_strtolower((string) $existingTitle), $percent);
                if ($percent >= 88.0) {
                    $flags[] = 'near_duplicate_title';
                    $score -= 25;
                    break;
                }
            }
        }

        // Language fidelity check
        $detected = $this->languageDetection->detect($cleanBody, $draft->language_code);
        if (mb_strlen($cleanBody) >= 120 && $detected !== $draft->language_code) {
            $flags[] = "language_mismatch_detected:{$detected}";
            $score -= 15;
        }

        // Sensitive topics / news without verified source require explicit manual verification
        if (
            in_array($draft->content_type, ['news_summary', 'news'], true)
            && $draft->source_id === null
            && (! is_array($draft->source_references) || $draft->source_references === [])
        ) {
            $flags[] = 'unverified_news_missing_source';
            $score -= 30;
        }

        if (preg_match('/\b(guaranteed profit|financial advice|medical cure|buy now|100x return)\b/i', $combined) === 1) {
            $flags[] = 'sensitive_or_promotional_claim';
            $score -= 35;
        }

        $finalScore = max(0, min(100, $score));
        $hardFailFlags = array_intersect($flags, [
            'thin_body_content',
            'unsafe_html_markup_detected',
            'exact_duplicate_of_existing_post',
            'exact_duplicate_of_existing_draft',
            'unverified_news_missing_source',
        ]);

        $status = $hardFailFlags !== []
            ? 'failed'
            : ($flags !== [] ? 'needs_review' : 'passed');

        return [
            'status' => $status,
            'score' => $finalScore,
            'flags' => array_values(array_unique($flags)),
            'duplicate_of_post_id' => is_numeric($dupPostId) ? (int) $dupPostId : null,
            'duplicate_of_draft_id' => is_numeric($dupDraftId) ? (int) $dupDraftId : null,
            'content_hash' => $contentHash,
        ];
    }
}
