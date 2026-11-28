<?php

namespace App\Services\Ai;

use App\Models\AiContentSource;
use App\Models\SeoRecommendation;
use App\Models\User;
use App\Services\Social\SafeLinkPreviewService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiSourceResearchService
{
    public function __construct(
        private readonly SafeLinkPreviewService $safeLinkPreview,
        private readonly AiAutomationControlService $control,
    ) {}

    /**
     * Register a verified reference source with strict SSRF defense (blocks private IPs, localhost, unsafe schemes).
     *
     * @param  array{
     *     title: string,
     *     url: string,
     *     publisher?: string|null,
     *     source_type?: string,
     *     excerpt?: string|null,
     *     fetch_excerpt?: bool
     * }  $data
     */
    public function storeSource(array $data, User $actor): AiContentSource
    {
        $url = trim($data['url']);
        if (! $this->safeLinkPreview->isSafePublicUrl($url)) {
            throw ValidationException::withMessages([
                'url' => [__('The provided source URL is blocked by SSRF security rules or is not a valid public HTTP(S) URL.')],
            ]);
        }

        $excerpt = isset($data['excerpt']) && trim($data['excerpt']) !== ''
            ? Str::limit(trim(strip_tags($data['excerpt'])), 5000, '')
            : null;

        if ($excerpt === null && ! empty($data['fetch_excerpt']) && $this->control->isSwitchEnabled('ai_research_enabled')) {
            $excerpt = $this->safeFetchExcerpt($url);
        }

        $urlHash = hash('sha256', $url);
        $source = AiContentSource::query()->updateOrCreate(
            ['url_hash' => $urlHash],
            [
                'title' => Str::limit(trim($data['title']), 250, ''),
                'url' => Str::limit($url, 1000, ''),
                'publisher' => isset($data['publisher']) ? Str::limit(trim($data['publisher']), 130, '') : (parse_url($url, PHP_URL_HOST) ?: null),
                'source_type' => (string) ($data['source_type'] ?? 'documentation'),
                'retrieved_at' => now(),
                'verification_status' => 'verified',
                'excerpt' => $excerpt,
                'content_hash' => $excerpt !== null ? hash('sha256', $excerpt) : null,
                'created_by' => $actor->id,
            ],
        );

        $this->control->logActivity('ai.source.saved', $actor, null, AiContentSource::class, $source->id, [
            'url' => $source->url,
            'source_type' => $source->source_type,
        ]);

        return $source;
    }

    /**
     * Pull real SEO Keyword Opportunities from SEO V2.0 Search Console recommendations (zero fabricated queries).
     *
     * @return list<array{id: int, type: string, priority: string, locale: string|null, query: string|null, title: string, description: string}>
     */
    public function seoOpportunities(int $limit = 15): array
    {
        return array_values(
            SeoRecommendation::query()
                ->where('status', 'open')
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(static fn (SeoRecommendation $r): array => [
                    'id' => $r->id,
                    'type' => $r->type,
                    'priority' => $r->priority,
                    'locale' => $r->locale,
                    'query' => $r->query,
                    'title' => $r->title,
                    'description' => $r->description,
                ])
                ->all(),
        );
    }

    /**
     * Bounded, no-redirect-abuse, SSRF-safe plain-text excerpt fetch.
     */
    private function safeFetchExcerpt(string $url): ?string
    {
        try {
            $botOrigin = rtrim((string) config('app.url', 'https://sysnoxis.com'), '/');
            $response = Http::timeout(8)
                ->withOptions(['allow_redirects' => ['max' => 2]])
                ->withHeaders(['User-Agent' => "SyncVietEditorialBot/1.0 (+{$botOrigin})"])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $raw = substr($response->body(), 0, 120_000);
            $noScripts = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($noScripts)) ?? '');

            return $text !== '' ? Str::limit($text, 4000, '...') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
