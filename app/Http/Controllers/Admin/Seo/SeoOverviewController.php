<?php

namespace App\Http\Controllers\Admin\Seo;

use App\Enums\SeoIndexEligibility;
use App\Enums\SeoUrlStatus;
use App\Http\Controllers\Controller;
use App\Models\SeoApiConnection;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditRun;
use App\Models\SeoContentTranslation;
use App\Models\SeoJobHistory;
use App\Models\SeoRecommendation;
use App\Models\SeoSitemap;
use App\Models\SeoUrlRegistry;
use App\Services\Seo\GoogleSearchConsoleService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\Seo\SeoConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SeoOverviewController extends Controller
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly GoogleSearchConsoleService $gsc,
        private readonly OrganicGrowthService $organicGrowth,
    ) {}

    public function index(Request $request): Response
    {
        $days = max(7, min(90, (int) $request->integer('days', 28)));
        $sinceDate = now()->subDays($days)->toDateString();

        $totalUrls = SeoUrlRegistry::query()->count();
        $eligibleUrls = SeoUrlRegistry::query()->where('index_eligibility', SeoIndexEligibility::Eligible->value)->count();
        $inSitemapUrls = SeoUrlRegistry::query()->where('is_in_sitemap', true)->count();
        $indexedUrls = SeoUrlRegistry::query()->where('status', SeoUrlStatus::Indexed->value)->count();
        $notIndexedUrls = SeoUrlRegistry::query()->where('status', SeoUrlStatus::NotIndexed->value)->count();
        $excludedUrls = SeoUrlRegistry::query()->where('index_eligibility', SeoIndexEligibility::Excluded->value)->count();

        $byLocale = DB::table('seo_url_registry')
            ->select(
                'locale',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN index_eligibility = 'eligible' THEN 1 ELSE 0 END) as eligible"),
                DB::raw('SUM(CASE WHEN is_in_sitemap = 1 THEN 1 ELSE 0 END) as in_sitemap'),
                DB::raw("SUM(CASE WHEN status = 'indexed' THEN 1 ELSE 0 END) as indexed_count"),
            )
            ->groupBy('locale')
            ->orderByDesc('total')
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'locale' => (string) $r->locale,
                'total' => (int) $r->total,
                'eligible' => (int) $r->eligible,
                'in_sitemap' => (int) $r->in_sitemap,
                'indexed' => (int) $r->indexed_count,
            ])
            ->values()
            ->all();

        $latestAudit = SeoAuditRun::query()->latest('id')->first();
        $openCriticalHigh = SeoAuditIssue::query()
            ->where('status', 'open')
            ->whereIn('severity', ['critical', 'high'])
            ->latest('id')
            ->limit(10)
            ->get();

        /** @var \stdClass|null $analyticsAgg */
        $analyticsAgg = DB::table('seo_search_analytics')
            ->where('date', '>=', $sinceDate)
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(impressions), 0) as impressions, COALESCE(AVG(position), 0) as avg_position')
            ->first();

        $analyticsTrend = DB::table('seo_search_analytics')
            ->where('date', '>=', $sinceDate)
            ->select(
                'date',
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(impressions) as impressions'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'date' => (string) $r->date,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
            ])
            ->values()
            ->all();

        $topQueries = DB::table('seo_search_analytics')
            ->where('date', '>=', $sinceDate)
            ->where('query', '!=', '')
            ->select(
                'query',
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('AVG(position) as avg_position'),
            )
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(15)
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'query' => (string) $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr' => (int) $r->impressions > 0 ? round(((int) $r->clicks / (int) $r->impressions) * 100, 2) : 0.0,
                'position' => round((float) $r->avg_position, 1),
            ])
            ->values()
            ->all();

        $connections = SeoApiConnection::query()
            ->get(['provider', 'auth_type', 'property_identifier', 'status', 'verified_at', 'last_synced_at', 'last_error'])
            ->keyBy('provider')
            ->map(static fn (SeoApiConnection $c): array => [
                'provider' => $c->provider,
                'auth_type' => $c->auth_type,
                'property_identifier' => $c->property_identifier,
                'status' => $c->status,
                'verified_at' => $c->verified_at?->toIso8601String(),
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'last_error' => $c->last_error,
            ])
            ->all();

        $sitemapsSummary = [
            'total_groups' => SeoSitemap::query()->count(),
            'dirty_groups' => SeoSitemap::query()->where('is_dirty', true)->count(),
            'total_urls' => (int) SeoSitemap::query()->sum('total_urls'),
            'last_generated_at' => SeoSitemap::query()->max('last_generated_at'),
        ];

        $recentJobs = SeoJobHistory::query()->latest('id')->limit(12)->get()->map(static fn (SeoJobHistory $j): array => [
            'id' => $j->id,
            'job_name' => $j->job_name,
            'status' => $j->status,
            'processed_count' => $j->processed_count,
            'error_count' => $j->error_count,
            'duration_ms' => $j->duration_ms,
            'started_at' => $j->started_at->toIso8601String(),
            'error_message' => $j->error_message,
        ])->values()->all();

        $clicks = (int) ($analyticsAgg->clicks ?? 0);
        $impressions = (int) ($analyticsAgg->impressions ?? 0);

        return Inertia::render('admin/Seo/Overview', [
            'days' => $days,
            'canonicalOrigin' => $this->config->canonicalBaseUrl(),
            'indexingAllowed' => $this->config->isIndexingAllowed(),
            'environment' => app()->environment(),
            'kpis' => [
                'total_urls' => $totalUrls,
                'eligible_urls' => $eligibleUrls,
                'in_sitemap_urls' => $inSitemapUrls,
                'indexed_urls' => $indexedUrls,
                'not_indexed_urls' => $notIndexedUrls,
                'excluded_urls' => $excludedUrls,
                'published_translations' => SeoContentTranslation::query()->publishedApproved()->count(),
                'open_recommendations' => SeoRecommendation::query()->where('status', 'open')->count(),
            ],
            'gsc' => $this->gsc->safeSummary(),
            'connections' => $connections,
            'searchAnalytics' => [
                'has_data' => $impressions > 0 || $clicks > 0,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'avg_position' => round((float) ($analyticsAgg->avg_position ?? 0), 1),
                'trend' => $analyticsTrend,
                'top_queries' => $topQueries,
            ],
            'latestAudit' => $latestAudit === null ? null : [
                'id' => $latestAudit->id,
                'technical_health_score' => $latestAudit->technical_health_score,
                'urls_scanned' => $latestAudit->urls_scanned,
                'critical_count' => $latestAudit->critical_count,
                'high_count' => $latestAudit->high_count,
                'medium_count' => $latestAudit->medium_count,
                'low_count' => $latestAudit->low_count,
                'finished_at' => $latestAudit->finished_at?->toIso8601String(),
                'disclaimer' => 'Internal SysNoxis Technical SEO Hygiene Score (NOT a Google Ranking Score).',
            ],
            'alerts' => $openCriticalHigh->map(static fn (SeoAuditIssue $i): array => [
                'id' => $i->id,
                'severity' => $i->severity->value,
                'check_code' => $i->check_code,
                'title' => $i->title,
                'url' => $i->url,
            ])->values()->all(),
            'byLocale' => $byLocale,
            'sitemapsSummary' => $sitemapsSummary,
            'organicGrowth' => $this->organicGrowth->funnelSummary($days),
            'recentJobs' => $recentJobs,
        ]);
    }
}
