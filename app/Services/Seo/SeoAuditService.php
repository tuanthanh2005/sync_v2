<?php

namespace App\Services\Seo;

use App\Enums\SeoAuditSeverity;
use App\Enums\SeoIndexEligibility;
use App\Models\Post;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditRun;
use App\Models\SeoContentTranslation;
use App\Models\SeoRedirect;
use App\Models\SeoSitemap;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use Illuminate\Support\Facades\DB;

class SeoAuditService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly HreflangService $hreflang,
    ) {}

    /**
     * Execute comprehensive automated technical SEO audit checks.
     * Note: technical_health_score measures internal SysNoxis technical hygiene ONLY — NOT Google Ranking Score.
     */
    public function runAudit(string $triggerType = 'manual', ?User $actor = null): SeoAuditRun
    {
        $run = SeoAuditRun::query()->create([
            'trigger_type' => $triggerType,
            'status' => 'running',
            'triggered_by' => $actor?->id,
            'started_at' => now(),
        ]);

        /** @var list<array{check_code: string, severity: SeoAuditSeverity, url_registry_id: int|null, url: string|null, locale: string|null, title: string, description: string, recommendation: string}> $findings */
        $findings = [];
        $urlsScanned = SeoUrlRegistry::query()->count();

        // 1. Indexing disabled check in production
        if (app()->isProduction() && ! $this->config->isIndexingAllowed()) {
            $findings[] = [
                'check_code' => 'production_indexing_disabled',
                'severity' => SeoAuditSeverity::Critical,
                'url_registry_id' => null,
                'url' => $this->config->canonicalBaseUrl().'/robots.txt',
                'locale' => null,
                'title' => 'Global indexing is disabled in production',
                'description' => 'seo_enabled is turned off on a production environment, causing robots.txt to block all search engines.',
                'recommendation' => 'Enable SEO Engine in Admin SEO Center → Settings immediately.',
            ];
        }

        // 2. Non-200 or excluded URLs still marked as in_sitemap
        $invalidInSitemap = SeoUrlRegistry::query()
            ->where('is_in_sitemap', true)
            ->where(static function ($q): void {
                $q->where('index_eligibility', '!=', SeoIndexEligibility::Eligible->value)
                    ->orWhere('http_status', '!=', 200)
                    ->orWhere('is_noindex_forced', true);
            })
            ->limit(100)
            ->get();

        foreach ($invalidInSitemap as $row) {
            $findings[] = [
                'check_code' => 'ineligible_url_in_sitemap',
                'severity' => SeoAuditSeverity::Critical,
                'url_registry_id' => $row->id,
                'url' => $row->url,
                'locale' => $row->locale,
                'title' => 'Non-eligible or non-200 URL still listed in sitemap',
                'description' => "URL {$row->url} has status HTTP {$row->http_status} / eligibility {$row->index_eligibility->value} but is still flagged in sitemap.",
                'recommendation' => 'Run Sitemap Rebuild to remove ineligible URLs from active shards.',
            ];
        }

        // 3. Canonical URL mismatches or relative canonicals
        $badCanonicals = SeoUrlRegistry::query()
            ->where('index_eligibility', SeoIndexEligibility::Eligible->value)
            ->where(static function ($q): void {
                $q->whereNull('canonical_url')
                    ->orWhere('canonical_url', '')
                    ->orWhere('canonical_url', 'not like', 'http%');
            })
            ->limit(100)
            ->get();

        foreach ($badCanonicals as $row) {
            $findings[] = [
                'check_code' => 'invalid_canonical_url',
                'severity' => SeoAuditSeverity::High,
                'url_registry_id' => $row->id,
                'url' => $row->url,
                'locale' => $row->locale,
                'title' => 'Missing or relative canonical URL',
                'description' => "Eligible URL {$row->url} lacks a valid absolute canonical URL.",
                'recommendation' => 'Re-sync URL Registry to regenerate normalized canonical URLs.',
            ];
        }

        // 4. Duplicate content fingerprints among eligible public posts
        $dupFingerprints = DB::table('posts')
            ->where('visibility', 'public')
            ->where('moderation_status', 'approved')
            ->whereNotNull('published_at')
            ->whereNull('deleted_at')
            ->whereNotNull('content_fingerprint')
            ->select('content_fingerprint', DB::raw('COUNT(*) as cnt'))
            ->groupBy('content_fingerprint')
            ->having('cnt', '>', 1)
            ->limit(50)
            ->get();

        foreach ($dupFingerprints as $dup) {
            $findings[] = [
                'check_code' => 'duplicate_post_content',
                'severity' => SeoAuditSeverity::Medium,
                'url_registry_id' => null,
                'url' => null,
                'locale' => null,
                'title' => 'Duplicate public post content detected',
                'description' => "Found {$dup->cnt} public posts sharing content fingerprint ".substr((string) $dup->content_fingerprint, 0, 12).'...',
                'recommendation' => 'Keep the earliest canonical post indexed and mark duplicates noindex or consolidate.',
            ];
        }

        // 5. Missing post titles on eligible posts
        $untitledPosts = Post::query()
            ->publicVisible()
            ->where('seo_noindex', false)
            ->where(static function ($q): void {
                $q->whereNull('title')->orWhere('title', '');
            })
            ->limit(50)
            ->get(['id', 'language_code', 'slug']);

        foreach ($untitledPosts as $post) {
            $findings[] = [
                'check_code' => 'missing_post_title',
                'severity' => SeoAuditSeverity::Low,
                'url_registry_id' => null,
                'url' => $this->canonicalUrls->postCanonicalUrl($post),
                'locale' => $post->language_code,
                'title' => 'Public post relies on auto-generated title snippet',
                'description' => "Post #{$post->id} has no explicit title; metadata falls back to body excerpt.",
                'recommendation' => 'Add descriptive titles or metadata overrides for high-value discussions.',
            ];
        }

        // 6. Stale published translations whose original post changed
        $staleTranslations = SeoContentTranslation::query()
            ->publishedApproved()
            ->where('is_stale', true)
            ->limit(100)
            ->get();

        foreach ($staleTranslations as $tr) {
            $findings[] = [
                'check_code' => 'stale_translation',
                'severity' => SeoAuditSeverity::Medium,
                'url_registry_id' => null,
                'url' => $this->canonicalUrls->absoluteUrl('/'.$tr->target_locale.'/posts/'.$tr->entity_id.'-'.$tr->localized_slug),
                'locale' => $tr->target_locale,
                'title' => "Outdated {$tr->target_locale} translation requires review",
                'description' => "Original {$tr->entity_type} #{$tr->entity_id} was edited after translation v{$tr->version} was published.",
                'recommendation' => 'Review and refresh translation in International SEO → Translations.',
            ];
        }

        // 7. Hreflang validation issues
        $hreflangValidation = $this->hreflang->validateAll();
        foreach (array_slice($hreflangValidation['issues'], 0, 100) as $hIssue) {
            $findings[] = [
                'check_code' => $hIssue['code'],
                'severity' => $hIssue['severity'] === 'high' ? SeoAuditSeverity::High : SeoAuditSeverity::Medium,
                'url_registry_id' => null,
                'url' => $hIssue['url'],
                'locale' => $hIssue['locale'],
                'title' => 'Hreflang validation error: '.$hIssue['code'],
                'description' => $hIssue['message'],
                'recommendation' => 'Fix reciprocal translation publication status or disable invalid locale alternates.',
            ];
        }

        // 8. Redirect chains
        $activeRedirects = SeoRedirect::query()->where('is_active', true)->get();
        $redirectMap = $activeRedirects->keyBy('source_path');
        foreach ($activeRedirects as $redir) {
            $targetPath = $this->canonicalUrls->normalizePath($redir->target_url);
            if ($redirectMap->has($targetPath)) {
                $findings[] = [
                    'check_code' => 'redirect_chain',
                    'severity' => SeoAuditSeverity::High,
                    'url_registry_id' => null,
                    'url' => $this->canonicalUrls->absoluteUrl($redir->source_path),
                    'locale' => null,
                    'title' => 'Multi-hop redirect chain detected',
                    'description' => "{$redir->source_path} redirects to {$targetPath}, which itself redirects further.",
                    'recommendation' => 'Point the source path directly to the final canonical destination.',
                ];
            }
        }

        // 9. Dirty or ungenerated sitemaps
        $dirtySitemaps = SeoSitemap::query()->where('is_dirty', true)->count();
        if ($dirtySitemaps > 0) {
            $findings[] = [
                'check_code' => 'dirty_sitemaps_pending',
                'severity' => SeoAuditSeverity::Info,
                'url_registry_id' => null,
                'url' => $this->config->canonicalBaseUrl().'/sitemap.xml',
                'locale' => null,
                'title' => "{$dirtySitemaps} sitemap shard group(s) pending regeneration",
                'description' => 'New or updated public entities are awaiting the next scheduled sitemap sync.',
                'recommendation' => 'Wait for the 5-minute scheduler or click Rebuild Dirty Sitemaps now.',
            ];
        }

        // 10. Thin content held in pending_quality_review
        $thinCount = SeoUrlRegistry::query()
            ->where('index_eligibility', SeoIndexEligibility::PendingQualityReview->value)
            ->count();
        if ($thinCount > 0) {
            $findings[] = [
                'check_code' => 'thin_content_held_noindex',
                'severity' => SeoAuditSeverity::Info,
                'url_registry_id' => null,
                'url' => null,
                'locale' => null,
                'title' => "{$thinCount} low-depth URL(s) safely excluded by Quality Gate",
                'description' => 'Thin posts, empty profiles, or low-volume topics are automatically kept noindex to protect crawl budget.',
                'recommendation' => 'No action needed unless you want to enrich or review these pages.',
            ];
        }

        // 11. Inspection canonical conflict (Google chose a different canonical than declared)
        $canonicalConflicts = SeoUrlRegistry::query()
            ->whereNotNull('last_inspected_at')
            ->whereHas('inspections', static function ($q): void {
                $q->whereNotNull('google_canonical')
                    ->whereNotNull('user_canonical')
                    ->whereColumn('google_canonical', '!=', 'user_canonical');
            })
            ->limit(50)
            ->get();

        foreach ($canonicalConflicts as $row) {
            $findings[] = [
                'check_code' => 'google_canonical_mismatch',
                'severity' => SeoAuditSeverity::High,
                'url_registry_id' => $row->id,
                'url' => $row->url,
                'locale' => $row->locale,
                'title' => 'Google selected a different canonical URL',
                'description' => "URL Inspection reported Google's canonical differs from SysNoxis's declared canonical for {$row->url}.",
                'recommendation' => 'Consolidate internal links and redirects toward a single preferred canonical URL.',
            ];
        }

        $counts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'info' => 0,
        ];

        foreach ($findings as $finding) {
            $sev = $finding['severity']->value;
            $counts[$sev]++;
            $fp = hash('sha256', implode('|', [$finding['check_code'], (string) ($finding['url'] ?? ''), (string) ($finding['locale'] ?? '')]));

            SeoAuditIssue::query()->create([
                'audit_run_id' => $run->id,
                'url_registry_id' => $finding['url_registry_id'],
                'check_code' => $finding['check_code'],
                'severity' => $finding['severity'],
                'url' => $finding['url'],
                'locale' => $finding['locale'],
                'title' => $finding['title'],
                'description' => $finding['description'],
                'recommendation' => $finding['recommendation'],
                'status' => 'open',
                'fingerprint' => $fp,
            ]);
        }

        // Technical Health Score (0-100): transparent internal hygiene formula, NOT Google Ranking Score
        $penalty = min(100, ($counts['critical'] * 25) + ($counts['high'] * 8) + ($counts['medium'] * 3) + ($counts['low'] * 1));
        $score = max(0, 100 - $penalty);

        $run->forceFill([
            'status' => 'completed',
            'technical_health_score' => $score,
            'urls_scanned' => $urlsScanned,
            'critical_count' => $counts['critical'],
            'high_count' => $counts['high'],
            'medium_count' => $counts['medium'],
            'low_count' => $counts['low'],
            'info_count' => $counts['info'],
            'summary' => [
                'disclaimer' => 'Internal SysNoxis Technical SEO Hygiene Score (NOT a Google Ranking Score).',
                'hreflang_groups_checked' => $hreflangValidation['checked_groups'],
                'hreflang_groups_valid' => $hreflangValidation['valid_groups'],
            ],
            'finished_at' => now(),
        ])->save();

        if ($counts['critical'] > 0) {
            $this->notifyAdminsOfCriticalAudit($run, $counts['critical']);
        }

        return $run;
    }

    private function notifyAdminsOfCriticalAudit(SeoAuditRun $run, int $criticalCount): void
    {
        $admins = User::query()->where('role', 'admin')->limit(10)->get();

        foreach ($admins as $admin) {
            $admin->notify(new SocialActivityNotification(
                eventKey: 'seo.audit.critical',
                titleVi: "SEO Audit #{$run->id} phát hiện {$criticalCount} lỗi nghiêm trọng cần kiểm tra.",
                titleEn: "SEO Audit #{$run->id} detected {$criticalCount} critical technical issue(s).",
                url: '/admin/seo/audit',
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'seo-engine',
            ));
        }
    }
}
