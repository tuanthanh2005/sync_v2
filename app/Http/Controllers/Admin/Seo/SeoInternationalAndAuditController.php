<?php

namespace App\Http\Controllers\Admin\Seo;

use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\Language;
use App\Models\Post;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditRun;
use App\Models\SeoContentTranslation;
use App\Models\SeoJobHistory;
use App\Models\SeoRecommendation;
use App\Services\Seo\ContentTranslationService;
use App\Services\Seo\HreflangService;
use App\Services\Seo\KeywordIntelligenceService;
use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeoInternationalAndAuditController extends Controller
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly ContentTranslationService $translations,
        private readonly HreflangService $hreflang,
        private readonly SeoAuditService $auditService,
        private readonly KeywordIntelligenceService $keywordIntelligence,
    ) {}

    public function international(): Response
    {
        $languages = Language::query()
            ->orderBy('sort_order')
            ->get(['code', 'name', 'native_name', 'direction', 'is_enabled', 'is_default']);

        $translationRows = SeoContentTranslation::query()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (SeoContentTranslation $t): array => [
                'id' => $t->id,
                'entity_type' => $t->entity_type,
                'entity_id' => $t->entity_id,
                'original_language' => $t->original_language,
                'target_locale' => $t->target_locale,
                'translated_title' => $t->translated_title,
                'localized_slug' => $t->localized_slug,
                'provider' => $t->provider,
                'version' => $t->version,
                'review_status' => $t->review_status->value,
                'publication_status' => $t->publication_status->value,
                'is_stale' => $t->is_stale,
                'is_ai_assisted' => $t->is_ai_assisted,
                'published_at' => $t->published_at?->toIso8601String(),
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $candidatePosts = Post::query()
            ->publicVisible()
            ->orderByDesc('reactions_count')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'title', 'language_code', 'slug'])
            ->map(static fn (Post $p): array => [
                'id' => $p->id,
                'title' => $p->title ?: 'Post #'.$p->id,
                'language_code' => $p->language_code ?: 'en',
            ])
            ->values()
            ->all();

        $hreflangCheck = $this->hreflang->validateAll();

        return Inertia::render('admin/Seo/International', [
            'languages' => $languages,
            'translations' => $translationRows,
            'candidatePosts' => $candidatePosts,
            'hreflangValidation' => $hreflangCheck,
            'templates' => [
                'title_template_home' => $this->config->string('title_template_home', '{site} — Global Community & Discussions'),
                'title_template_post' => $this->config->string('title_template_post', '{title} | {site}'),
                'title_template_community' => $this->config->string('title_template_community', '{name} Community | {site}'),
                'title_template_profile' => $this->config->string('title_template_profile', '{name} (@{username}) | {site}'),
                'title_template_topic' => $this->config->string('title_template_topic', '#{topic} Discussions & Insights | {site}'),
                'description_template_home' => $this->config->string('description_template_home', ''),
            ],
        ]);
    }

    public function storeTranslation(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'target_locale' => ['required', 'string', 'max:12'],
            'translated_title' => ['nullable', 'string', 'max:250'],
            'translated_body' => ['required', 'string', 'min:40', 'max:20000'],
            'translated_description' => ['nullable', 'string', 'max:480'],
            'localized_slug' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Post $post */
        $post = Post::query()->findOrFail((int) $validated['post_id']);

        $this->translations->upsertPostTranslation($post, [
            'target_locale' => (string) $validated['target_locale'],
            'translated_title' => is_string($validated['translated_title'] ?? null) ? $validated['translated_title'] : null,
            'translated_body' => (string) $validated['translated_body'],
            'translated_description' => is_string($validated['translated_description'] ?? null) ? $validated['translated_description'] : null,
            'localized_slug' => is_string($validated['localized_slug'] ?? null) ? $validated['localized_slug'] : null,
            'provider' => 'manual',
            'is_ai_assisted' => false,
        ], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content translation saved as draft for review.')]);

        return back();
    }

    public function publishTranslation(Request $request, SeoContentTranslation $translation): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->translations->approveAndPublish($translation, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Translation approved and published with reciprocal hreflang.')]);

        return back();
    }

    public function unpublishTranslation(Request $request, SeoContentTranslation $translation): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->translations->unpublish($translation, $user, $request->boolean('reject', false));

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Translation removed from public search indexing.')]);

        return back();
    }

    public function updateTemplates(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'title_template_home' => ['required', 'string', 'max:160'],
            'title_template_post' => ['required', 'string', 'max:160'],
            'title_template_community' => ['required', 'string', 'max:160'],
            'title_template_profile' => ['required', 'string', 'max:160'],
            'title_template_topic' => ['required', 'string', 'max:160'],
            'description_template_home' => ['required', 'string', 'max:300'],
        ]);

        foreach ($validated as $key => $value) {
            $this->config->set($key, trim((string) $value), 'templates', 'string', false, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Metadata templates updated.')]);

        return back();
    }

    public function audit(): Response
    {
        $runs = SeoAuditRun::query()
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(static fn (SeoAuditRun $r): array => [
                'id' => $r->id,
                'trigger_type' => $r->trigger_type,
                'status' => $r->status,
                'technical_health_score' => $r->technical_health_score,
                'urls_scanned' => $r->urls_scanned,
                'critical_count' => $r->critical_count,
                'high_count' => $r->high_count,
                'medium_count' => $r->medium_count,
                'low_count' => $r->low_count,
                'info_count' => $r->info_count,
                'finished_at' => $r->finished_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $issues = SeoAuditIssue::query()
            ->where('status', 'open')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->latest('id')
            ->limit(80)
            ->get()
            ->map(static fn (SeoAuditIssue $i): array => [
                'id' => $i->id,
                'check_code' => $i->check_code,
                'severity' => $i->severity->value,
                'url' => $i->url,
                'locale' => $i->locale,
                'title' => $i->title,
                'description' => $i->description,
                'recommendation' => $i->recommendation,
                'created_at' => $i->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $recommendations = SeoRecommendation::query()
            ->where('status', 'open')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (SeoRecommendation $rec): array => [
                'id' => $rec->id,
                'type' => $rec->type,
                'priority' => $rec->priority,
                'locale' => $rec->locale,
                'query' => $rec->query,
                'title' => $rec->title,
                'description' => $rec->description,
                'metrics' => $rec->metrics,
            ])
            ->values()
            ->all();

        $topTopics = Hashtag::query()
            ->where('is_restricted', false)
            ->where('is_spam', false)
            ->orderByDesc('posts_count')
            ->limit(15)
            ->get(['id', 'name', 'slug', 'posts_count', 'is_featured']);

        $jobHistory = SeoJobHistory::query()->latest('id')->limit(25)->get()->map(static fn (SeoJobHistory $j): array => [
            'id' => $j->id,
            'job_name' => $j->job_name,
            'status' => $j->status,
            'processed_count' => $j->processed_count,
            'error_count' => $j->error_count,
            'duration_ms' => $j->duration_ms,
            'started_at' => $j->started_at->toIso8601String(),
            'error_message' => $j->error_message,
        ])->values()->all();

        return Inertia::render('admin/Seo/AuditAndGrowth', [
            'runs' => $runs,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'topTopics' => $topTopics,
            'jobHistory' => $jobHistory,
            'settings' => [
                'seo_enabled' => $this->config->bool('seo_enabled', true),
                'allow_indexing_in_non_production' => $this->config->bool('allow_indexing_in_non_production', false),
                'min_post_words' => $this->config->int('min_post_words', 12),
                'min_post_chars' => $this->config->int('min_post_chars', 60),
                'min_topic_posts' => $this->config->int('min_topic_posts', 2),
                'index_user_profiles' => $this->config->bool('index_user_profiles', true),
                'gsc_daily_inspection_limit' => $this->config->int('gsc_daily_inspection_limit', 1800),
            ],
        ]);
    }

    public function runAuditNow(Request $request): RedirectResponse
    {
        $run = $this->auditService->runAudit('manual', $request->user());

        Inertia::flash('toast', [
            'type' => $run->critical_count > 0 ? 'warning' : 'success',
            'message' => __('SEO Audit #:id finished. Technical Health Score: :score/100 (:critical critical, :high high).', [
                'id' => $run->id,
                'score' => $run->technical_health_score,
                'critical' => $run->critical_count,
                'high' => $run->high_count,
            ]),
        ]);

        return back();
    }

    public function resolveIssue(SeoAuditIssue $issue): RedirectResponse
    {
        $issue->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Audit issue marked resolved.')]);

        return back();
    }

    public function refreshRecommendations(): RedirectResponse
    {
        $res = $this->keywordIntelligence->refreshRecommendations(28);

        Inertia::flash('toast', [
            'type' => $res['has_gsc_data'] ? 'success' : 'info',
            'message' => $res['has_gsc_data']
                ? __('Generated :count keyword & CTR recommendations from Search Console data.', ['count' => $res['generated']])
                : __('No Search Console data available yet. Connect Google Search Console and sync Search Analytics first.'),
        ]);

        return back();
    }

    public function dismissRecommendation(Request $request, SeoRecommendation $recommendation): RedirectResponse
    {
        $recommendation->forceFill([
            'status' => 'dismissed',
            'dismissed_by' => $request->user()?->id,
            'resolved_at' => now(),
        ])->save();

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Recommendation dismissed.')]);

        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'seo_enabled' => ['required', 'boolean'],
            'allow_indexing_in_non_production' => ['required', 'boolean'],
            'min_post_words' => ['required', 'integer', 'min:3', 'max:500'],
            'min_post_chars' => ['required', 'integer', 'min:20', 'max:2000'],
            'min_topic_posts' => ['required', 'integer', 'min:1', 'max:100'],
            'index_user_profiles' => ['required', 'boolean'],
            'gsc_daily_inspection_limit' => ['required', 'integer', 'min:50', 'max:2000'],
        ]);

        $this->config->set('seo_enabled', (bool) $validated['seo_enabled'], 'general', 'boolean', false, $user);
        $this->config->set('allow_indexing_in_non_production', (bool) $validated['allow_indexing_in_non_production'], 'general', 'boolean', false, $user);
        $this->config->set('min_post_words', (int) $validated['min_post_words'], 'quality', 'integer', false, $user);
        $this->config->set('min_post_chars', (int) $validated['min_post_chars'], 'quality', 'integer', false, $user);
        $this->config->set('min_topic_posts', (int) $validated['min_topic_posts'], 'quality', 'integer', false, $user);
        $this->config->set('index_user_profiles', (bool) $validated['index_user_profiles'], 'quality', 'boolean', false, $user);
        $this->config->set('gsc_daily_inspection_limit', (int) $validated['gsc_daily_inspection_limit'], 'gsc', 'integer', false, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SEO Engine settings saved.')]);

        return back();
    }
}
