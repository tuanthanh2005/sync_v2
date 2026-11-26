<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Models\SeoContentTranslation;
use App\Models\SeoRecommendation;
use App\Models\SeoSearchAnalytic;
use App\Models\SeoUrlRegistry;
use Illuminate\Support\Facades\DB;

class KeywordIntelligenceService
{
    /**
     * Generate actionable recommendations purely from real Google Search Console analytics data.
     * Produces ZERO fabricated keywords when GSC has no data yet.
     *
     * @return array{generated: int, has_gsc_data: bool}
     */
    public function refreshRecommendations(int $days = 28): array
    {
        $since = now()->subDays($days)->toDateString();
        $hasData = SeoSearchAnalytic::query()->where('date', '>=', $since)->exists();

        if (! $hasData) {
            return ['generated' => 0, 'has_gsc_data' => false];
        }

        $generated = 0;

        // 1. High impressions + Low CTR opportunities (Title / Meta Description optimization)
        $lowCtrPages = DB::table('seo_search_analytics')
            ->where('date', '>=', $since)
            ->select(
                'page_url',
                'page_url_hash',
                'locale',
                DB::raw('SUM(clicks) as total_clicks'),
                DB::raw('SUM(impressions) as total_impressions'),
                DB::raw('AVG(position) as avg_position'),
            )
            ->groupBy('page_url', 'page_url_hash', 'locale')
            ->having('total_impressions', '>=', 50)
            ->orderByDesc('total_impressions')
            ->limit(25)
            ->get();

        foreach ($lowCtrPages as $row) {
            $impressions = (int) $row->total_impressions;
            $clicks = (int) $row->total_clicks;
            $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

            if ($ctr >= 0.025) {
                continue;
            }

            $reg = SeoUrlRegistry::query()->where('url_hash', $row->page_url_hash)->first();
            $fp = hash('sha256', 'low_ctr|'.$row->page_url_hash);

            SeoRecommendation::query()->updateOrCreate(
                ['fingerprint' => $fp],
                [
                    'type' => 'improve_ctr',
                    'priority' => $impressions >= 300 ? 'high' : 'medium',
                    'locale' => $row->locale,
                    'url_registry_id' => $reg?->id,
                    'title' => 'Improve Title & Meta Description for high-impression page',
                    'description' => sprintf(
                        'Page received %d impressions with %.2f%% CTR (avg position %.1f). Refine title clarity and snippet value.',
                        $impressions,
                        $ctr * 100,
                        (float) $row->avg_position,
                    ),
                    'metrics' => [
                        'page_url' => $row->page_url,
                        'clicks' => $clicks,
                        'impressions' => $impressions,
                        'ctr' => round($ctr * 100, 2),
                        'avg_position' => round((float) $row->avg_position, 1),
                    ],
                    'status' => 'open',
                ],
            );
            $generated++;
        }

        // 2. Striking-distance keywords (Positions 4–20)
        $strikingQueries = DB::table('seo_search_analytics')
            ->where('date', '>=', $since)
            ->where('query', '!=', '')
            ->select(
                'query',
                'page_url',
                'page_url_hash',
                'locale',
                DB::raw('SUM(clicks) as total_clicks'),
                DB::raw('SUM(impressions) as total_impressions'),
                DB::raw('AVG(position) as avg_position'),
            )
            ->groupBy('query', 'page_url', 'page_url_hash', 'locale')
            ->having('total_impressions', '>=', 20)
            ->having('avg_position', '>=', 4)
            ->having('avg_position', '<=', 20)
            ->orderByDesc('total_impressions')
            ->limit(25)
            ->get();

        foreach ($strikingQueries as $row) {
            $reg = SeoUrlRegistry::query()->where('url_hash', $row->page_url_hash)->first();
            $fp = hash('sha256', 'striking_distance|'.$row->query.'|'.$row->page_url_hash);

            SeoRecommendation::query()->updateOrCreate(
                ['fingerprint' => $fp],
                [
                    'type' => 'striking_distance',
                    'priority' => 'medium',
                    'locale' => $row->locale,
                    'url_registry_id' => $reg?->id,
                    'query' => $row->query,
                    'title' => "Striking-distance query: \"{$row->query}\"",
                    'description' => sprintf(
                        'Ranking at average position %.1f (%d impressions). Strengthen internal links and topic depth for this discussion.',
                        (float) $row->avg_position,
                        (int) $row->total_impressions,
                    ),
                    'metrics' => [
                        'page_url' => $row->page_url,
                        'clicks' => (int) $row->total_clicks,
                        'impressions' => (int) $row->total_impressions,
                        'avg_position' => round((float) $row->avg_position, 1),
                    ],
                    'status' => 'open',
                ],
            );
            $generated++;
        }

        // 3. International translation opportunities (high-performing posts with zero translations)
        $topPosts = DB::table('seo_search_analytics')
            ->where('date', '>=', $since)
            ->where('entity_type', SeoEntityType::Post->value)
            ->whereNotNull('entity_id')
            ->select(
                'entity_id',
                'page_url',
                'page_url_hash',
                'locale',
                DB::raw('SUM(clicks) as total_clicks'),
                DB::raw('SUM(impressions) as total_impressions'),
            )
            ->groupBy('entity_id', 'page_url', 'page_url_hash', 'locale')
            ->having('total_impressions', '>=', 100)
            ->orderByDesc('total_impressions')
            ->limit(15)
            ->get();

        foreach ($topPosts as $row) {
            $hasTranslation = SeoContentTranslation::query()
                ->where('entity_type', SeoEntityType::Post->value)
                ->where('entity_id', $row->entity_id)
                ->publishedApproved()
                ->exists();

            if ($hasTranslation) {
                continue;
            }

            $reg = SeoUrlRegistry::query()->where('url_hash', $row->page_url_hash)->first();
            $fp = hash('sha256', 'translate_opportunity|post|'.$row->entity_id);

            SeoRecommendation::query()->updateOrCreate(
                ['fingerprint' => $fp],
                [
                    'type' => 'translation_opportunity',
                    'priority' => 'high',
                    'locale' => $row->locale,
                    'url_registry_id' => $reg?->id,
                    'title' => "Translate high-performing post #{$row->entity_id} for international reach",
                    'description' => sprintf(
                        'Post generated %d impressions and %d clicks in %s without localized equivalents.',
                        (int) $row->total_impressions,
                        (int) $row->total_clicks,
                        (string) ($row->locale ?: 'en'),
                    ),
                    'metrics' => [
                        'post_id' => (int) $row->entity_id,
                        'page_url' => $row->page_url,
                        'clicks' => (int) $row->total_clicks,
                        'impressions' => (int) $row->total_impressions,
                    ],
                    'status' => 'open',
                ],
            );
            $generated++;
        }

        return ['generated' => $generated, 'has_gsc_data' => true];
    }
}
