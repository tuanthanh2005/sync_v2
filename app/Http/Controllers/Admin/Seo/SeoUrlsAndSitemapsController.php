<?php

namespace App\Http\Controllers\Admin\Seo;

use App\Enums\SeoIndexEligibility;
use App\Enums\SeoUrlStatus;
use App\Http\Controllers\Controller;
use App\Models\SeoRedirect;
use App\Models\SeoSitemap;
use App\Models\SeoUrlInspection;
use App\Models\SeoUrlRegistry;
use App\Services\Seo\GoogleSearchConsoleService;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\RobotsAndRedirectService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoRegistryService;
use App\Services\Seo\SitemapEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoUrlsAndSitemapsController extends Controller
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly SeoRegistryService $registry,
        private readonly SitemapEngineService $sitemapEngine,
        private readonly RobotsAndRedirectService $robotsAndRedirects,
        private readonly GoogleSearchConsoleService $gsc,
        private readonly IndexNowService $indexNow,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());
        $entityType = trim($request->string('entity_type')->toString());
        $locale = trim($request->string('locale')->toString());
        $eligibility = trim($request->string('eligibility')->toString());
        $status = trim($request->string('status')->toString());

        $query = SeoUrlRegistry::query()
            ->when($search !== '', static function ($q) use ($search): void {
                $q->where(static function ($inner) use ($search): void {
                    $inner->where('url', 'like', "%{$search}%")
                        ->orWhere('path', 'like', "%{$search}%");
                });
            })
            ->when($entityType !== '', static fn ($q) => $q->where('entity_type', $entityType))
            ->when($locale !== '', static fn ($q) => $q->where('locale', $locale))
            ->when($eligibility !== '', static fn ($q) => $q->where('index_eligibility', $eligibility))
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderByDesc('updated_at');

        $urls = $query->paginate(25)->withQueryString()->through(static fn (SeoUrlRegistry $r): array => [
            'id' => $r->id,
            'url' => $r->url,
            'path' => $r->path,
            'entity_type' => $r->entity_type->value,
            'entity_id' => $r->entity_id,
            'locale' => $r->locale,
            'canonical_url' => $r->canonical_url,
            'index_eligibility' => $r->index_eligibility->value,
            'status' => $r->status->value,
            'exclusion_reason' => $r->exclusion_reason,
            'http_status' => $r->http_status,
            'quality_score' => $r->quality_score,
            'is_in_sitemap' => $r->is_in_sitemap,
            'sitemap_shard_key' => $r->sitemap_shard_key,
            'is_noindex_forced' => $r->is_noindex_forced,
            'inspection_verdict' => $r->inspection_verdict,
            'inspection_coverage_state' => $r->inspection_coverage_state,
            'last_inspected_at' => $r->last_inspected_at?->toIso8601String(),
            'lastmod_at' => $r->lastmod_at?->toIso8601String(),
        ]);

        $sitemaps = SeoSitemap::query()
            ->with('shards')
            ->orderBy('name')
            ->get()
            ->map(static fn (SeoSitemap $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'locale' => $s->locale,
                'total_urls' => $s->total_urls,
                'total_shards' => $s->total_shards,
                'status' => $s->status,
                'is_dirty' => $s->is_dirty,
                'last_generated_at' => $s->last_generated_at?->toIso8601String(),
                'gsc_submitted_at' => $s->gsc_submitted_at?->toIso8601String(),
                'gsc_status' => $s->gsc_status,
                'shards' => $s->shards->map(static fn ($sh): array => [
                    'shard_key' => $sh->shard_key,
                    'url_count' => $sh->url_count,
                    'byte_size' => $sh->byte_size,
                    'generated_at' => $sh->generated_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $recentInspections = SeoUrlInspection::query()
            ->with('urlRegistry:id,url,locale')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(static fn (SeoUrlInspection $i): array => [
                'id' => $i->id,
                'url' => $i->urlRegistry?->url,
                'locale' => $i->urlRegistry?->locale,
                'inspection_status' => $i->inspection_status,
                'verdict' => $i->verdict,
                'coverage_state' => $i->coverage_state,
                'google_canonical' => $i->google_canonical,
                'user_canonical' => $i->user_canonical,
                'inspected_at' => $i->inspected_at?->toIso8601String(),
                'error_message' => $i->error_message,
            ])
            ->values()
            ->all();

        $redirects = SeoRedirect::query()
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(static fn (SeoRedirect $r): array => [
                'id' => $r->id,
                'source_path' => $r->source_path,
                'target_url' => $r->target_url,
                'status_code' => $r->status_code,
                'is_active' => $r->is_active,
                'hit_count' => $r->hit_count,
                'last_hit_at' => $r->last_hit_at?->toIso8601String(),
                'reason' => $r->reason,
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Seo/UrlsAndSitemaps', [
            'filters' => [
                'search' => $search,
                'entity_type' => $entityType,
                'locale' => $locale,
                'eligibility' => $eligibility,
                'status' => $status,
            ],
            'enabledLocales' => $this->config->enabledLocales(),
            'urls' => $urls,
            'sitemaps' => $sitemaps,
            'sitemapIndexUrl' => $this->config->canonicalBaseUrl().'/sitemap.xml',
            'recentInspections' => $recentInspections,
            'gscSummary' => $this->gsc->safeSummary(),
            'redirects' => $redirects,
            'robotsPreview' => $this->robotsAndRedirects->renderRobotsTxt(),
            'robotsExtraDisallow' => $this->config->string('robots_extra_disallow', ''),
        ]);
    }

    public function backfillRegistry(): RedirectResponse
    {
        $res = $this->registry->backfillAll();
        $sitemapRes = $this->sitemapEngine->rebuildAll();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Synced :processed URLs (:eligible eligible) and rebuilt :shards sitemap shard(s).', [
                'processed' => $res['processed'],
                'eligible' => $res['eligible'],
                'shards' => $sitemapRes['shards'],
            ]),
        ]);

        return back();
    }

    public function rebuildSitemaps(Request $request): RedirectResponse
    {
        $forceAll = $request->boolean('all', false);
        $res = $forceAll ? $this->sitemapEngine->rebuildAll() : $this->sitemapEngine->rebuildDirty();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Rebuilt :sitemaps sitemap group(s), :shards shard(s), :urls eligible URL(s).', $res),
        ]);

        return back();
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['force_noindex', 'allow_index', 'queue_inspection', 'queue_indexnow'])],
        ]);

        $entries = SeoUrlRegistry::query()->whereIn('id', $validated['ids'])->get();
        $affected = 0;

        foreach ($entries as $entry) {
            if ($validated['action'] === 'force_noindex') {
                $entry->forceFill([
                    'is_noindex_forced' => true,
                    'index_eligibility' => SeoIndexEligibility::Excluded,
                    'status' => SeoUrlStatus::Excluded,
                    'exclusion_reason' => 'admin_forced_noindex',
                    'is_in_sitemap' => false,
                ])->save();
                $affected++;
            } elseif ($validated['action'] === 'allow_index') {
                $entry->forceFill([
                    'is_noindex_forced' => false,
                    'index_eligibility' => SeoIndexEligibility::Eligible,
                    'status' => SeoUrlStatus::PendingSitemapUpdate,
                    'exclusion_reason' => null,
                ])->save();
                $affected++;
            } elseif ($validated['action'] === 'queue_inspection') {
                $entry->forceFill(['status' => SeoUrlStatus::PendingInspection])->save();
                $affected++;
            } elseif ($validated['action'] === 'queue_indexnow') {
                if ($this->indexNow->queueUrl($entry) !== null) {
                    $affected++;
                }
            }
        }

        if (in_array($validated['action'], ['force_noindex', 'allow_index'], true)) {
            $this->sitemapEngine->rebuildAll();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Bulk SEO action applied to :count URL(s).', ['count' => $affected]),
        ]);

        return back();
    }

    public function runInspectionBatch(): RedirectResponse
    {
        $res = $this->gsc->inspectPriorityBatch();

        Inertia::flash('toast', [
            'type' => $res['ok'] ? 'success' : 'info',
            'message' => $res['message'],
        ]);

        return back();
    }

    public function exportRegistryCsv(Request $request): StreamedResponse
    {
        $safeCell = static function (mixed $value): string {
            $str = (string) $value;
            if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'".$str;
            }

            return $str;
        };

        return response()->streamDownload(static function () use ($safeCell): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['ID', 'URL', 'Entity Type', 'Locale', 'Eligibility', 'Status', 'HTTP', 'Quality Score', 'In Sitemap', 'Verdict', 'Last Modified']);

            SeoUrlRegistry::query()->orderBy('id')->chunkById(500, static function ($rows) use ($out, $safeCell): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $safeCell($row->url),
                        $safeCell($row->entity_type->value),
                        $safeCell($row->locale),
                        $safeCell($row->index_eligibility->value),
                        $safeCell($row->status->value),
                        $row->http_status,
                        $row->quality_score,
                        $row->is_in_sitemap ? 'yes' : 'no',
                        $safeCell($row->inspection_verdict ?? ''),
                        $row->lastmod_at?->toIso8601String() ?? '',
                    ]);
                }
            });

            fclose($out);
        }, 'sysnoxis-seo-url-registry-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function storeRedirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:500'],
            'target_url' => ['required', 'string', 'max:1000'],
            'status_code' => ['required', 'integer', Rule::in([301, 302, 307, 308])],
            'reason' => ['nullable', 'string', 'max:250'],
        ]);

        $this->robotsAndRedirects->createOrUpdateRedirect(
            (string) $validated['source_path'],
            (string) $validated['target_url'],
            (int) $validated['status_code'],
            is_string($validated['reason'] ?? null) ? $validated['reason'] : null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SEO redirect rule saved.')]);

        return back();
    }

    public function toggleRedirect(SeoRedirect $seoRedirect): RedirectResponse
    {
        $seoRedirect->forceFill(['is_active' => ! $seoRedirect->is_active])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Redirect rule updated.')]);

        return back();
    }

    public function destroyRedirect(SeoRedirect $seoRedirect): RedirectResponse
    {
        $seoRedirect->delete();

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Redirect rule deleted.')]);

        return back();
    }

    public function updateRobots(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'robots_extra_disallow' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->config->set('robots_extra_disallow', trim((string) ($validated['robots_extra_disallow'] ?? '')), 'robots', 'string', false, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dynamic robots.txt rules updated.')]);

        return back();
    }
}
