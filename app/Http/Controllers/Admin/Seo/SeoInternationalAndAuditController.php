<?php

namespace App\Http\Controllers\Admin\Seo;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\Language;
use App\Models\Post;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditRun;
use App\Models\SeoContentTranslation;
use App\Models\SeoJobHistory;
use App\Models\SeoRecommendation;
use App\Models\User;
use App\Services\Seo\CanonicalUrlService;
use App\Services\Seo\ContentTranslationService;
use App\Services\Seo\HreflangService;
use App\Services\Seo\KeywordIntelligenceService;
use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoMetadataService;
use App\Services\System\LanguageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private readonly SeoMetadataService $seoMetadata,
        private readonly CanonicalUrlService $canonicalUrls,
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
        $localizedHomeDefaults = SeoConfigService::localizedHomeDefaults();
        $siteName = (string) config('app.name', 'SysNoxis');

        $localeHomeSettings = $languages->map(function (Language $lang) use ($localizedHomeDefaults, $siteName): array {
            $fallback = $localizedHomeDefaults[$lang->code] ?? $localizedHomeDefaults['en'];
            $computed = $this->config->homeSeoCopy($lang->code);

            return [
                'code' => $lang->code,
                'native_name' => $lang->native_name,
                'og_locale' => $this->config->ogLocaleCode($lang->code),
                'custom_title' => $this->config->string('home_title_'.$lang->code, ''),
                'custom_description' => $this->config->string('home_description_'.$lang->code, ''),
                'default_title' => str_replace('{site}', $siteName, $fallback['title']),
                'default_description' => str_replace('{site}', $siteName, $fallback['description']),
                'resolved_title' => $computed['title'],
                'resolved_description' => $computed['description'],
            ];
        })->values()->all();

        $previewCommunities = Community::query()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderByDesc('members_count')
            ->limit(15)
            ->get(['id', 'name', 'slug'])
            ->map(static fn (Community $c): array => [
                'id' => $c->id,
                'label' => $c->name.' (/communities/'.$c->slug.')',
            ])
            ->values()
            ->all();

        $previewProfiles = User::query()
            ->whereNotNull('email_verified_at')
            ->orderByDesc('followers_count')
            ->limit(15)
            ->get(['id', 'name', 'username'])
            ->map(static fn (User $u): array => [
                'id' => $u->id,
                'label' => $u->name.' (@'.$u->username.')',
            ])
            ->values()
            ->all();

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
            'socialSharing' => [
                'twitter_site_handle' => $this->config->twitterSiteHandle() ?? '',
                'default_og_image' => $this->config->defaultOgImageMetadata(),
                'locales' => $localeHomeSettings,
                'preview_entities' => [
                    'posts' => array_map(static fn (array $p): array => ['id' => $p['id'], 'label' => $p['title']], array_slice($candidatePosts, 0, 15)),
                    'communities' => $previewCommunities,
                    'profiles' => $previewProfiles,
                ],
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

    public function updateSocialSharing(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'twitter_site_handle' => ['nullable', 'string', 'max:20', 'regex:/^(@?[A-Za-z0-9_]{1,15})?$/'],
            'home_overrides' => ['required', 'array'],
            'home_overrides.*.code' => ['required', 'string', Rule::in(LanguageService::allCodes())],
            'home_overrides.*.title' => ['nullable', 'string', 'max:140'],
            'home_overrides.*.description' => ['nullable', 'string', 'max:240'],
        ]);

        $rawHandle = trim((string) ($validated['twitter_site_handle'] ?? ''));
        $normalizedHandle = $rawHandle === '' ? '' : (str_starts_with($rawHandle, '@') ? $rawHandle : '@'.$rawHandle);
        $this->config->set('twitter_site_handle', $normalizedHandle, 'social_sharing', 'string', false, $user);

        /** @var array<int, array{code: string, title?: string|null, description?: string|null}> $overrides */
        $overrides = $validated['home_overrides'];

        foreach ($overrides as $entry) {
            $code = (string) $entry['code'];
            $cleanTitle = trim(strip_tags((string) ($entry['title'] ?? '')));
            $cleanDesc = trim(strip_tags((string) ($entry['description'] ?? '')));
            $this->config->set('home_title_'.$code, $cleanTitle, 'social_sharing', 'string', false, $user);
            $this->config->set('home_description_'.$code, $cleanDesc, 'social_sharing', 'string', false, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social sharing and Open Graph metadata updated.')]);

        return back();
    }

    /**
     * Internal real-data Open Graph & Twitter card tester (zero external HTTP requests).
     */
    public function previewSocialCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_type' => ['required', 'string', Rule::in(['home', 'post', 'community', 'profile', 'premium', 'register'])],
            'locale' => ['required', 'string', Rule::in(LanguageService::allCodes())],
            'entity_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $pageType = (string) $validated['page_type'];
        $locale = (string) $validated['locale'];
        $entityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;
        $base = $this->config->canonicalBaseUrl();

        $seo = null;
        $shareUrl = $locale === 'en' ? $base.'/' : $base.'/'.$locale;

        if ($pageType === 'home') {
            $requestPath = $locale === 'en' ? '/' : '/'.$locale;
            $seo = $this->seoMetadata->forHome($locale, $requestPath);
            $shareUrl = $seo['url'];
        } elseif ($pageType === 'premium') {
            $seo = $this->seoMetadata->forPremium($locale, $locale !== 'en');
            $shareUrl = $locale === 'en' ? $base.'/premium' : $base.'/'.$locale.'/premium';
        } elseif ($pageType === 'register') {
            $seo = $this->seoMetadata->forRegisterLanding($locale);
            $shareUrl = $locale === 'en' ? $base.'/r/SV-PREVIEW' : $base.'/'.$locale.'/r/SV-PREVIEW';
        } elseif ($pageType === 'post') {
            $post = $entityId !== null
                ? Post::withTrashed()->find($entityId)
                : Post::query()->publicVisible()->latest('id')->first();

            if ($post === null) {
                return response()->json(['error' => __('No matching post found for preview.')], 404);
            }

            $translation = $locale !== ($post->language_code ?: 'en')
                ? SeoContentTranslation::query()
                    ->where('entity_type', 'post')
                    ->where('entity_id', $post->id)
                    ->where('target_locale', $locale)
                    ->publishedApproved()
                    ->first()
                : null;

            $untranslated = $translation === null && $locale !== ($post->language_code ?: 'en');
            $seo = $this->seoMetadata->forPost($post, $locale, $translation, $untranslated);
            $shareUrl = $untranslated
                ? $base.'/posts/'.$post->id.'?lang='.rawurlencode($locale)
                : $this->canonicalUrls->postCanonicalUrl($post, $locale, $translation);
        } elseif ($pageType === 'community') {
            $community = $entityId !== null
                ? Community::query()->find($entityId)
                : Community::query()->where('visibility', 'public')->where('status', 'active')->first();

            if ($community === null) {
                return response()->json(['error' => __('No matching community found for preview.')], 404);
            }

            $seo = $this->seoMetadata->forCommunity($community, $locale, $locale !== 'en');
            $shareUrl = $locale === 'en'
                ? $base.'/communities/'.$community->slug
                : $base.'/'.$locale.'/communities/'.$community->slug;
        } elseif ($pageType === 'profile') {
            $profileUser = $entityId !== null
                ? User::query()->find($entityId)
                : User::query()->whereNotNull('email_verified_at')->first();

            if ($profileUser === null) {
                return response()->json(['error' => __('No matching profile found for preview.')], 404);
            }

            $seo = $this->seoMetadata->forProfile($profileUser, $locale, $locale !== 'en');
            $shareUrl = $locale === 'en'
                ? $base.'/u/'.rawurlencode($profileUser->username)
                : $base.'/'.$locale.'/u/'.rawurlencode($profileUser->username);
        }

        return response()->json([
            'share_url' => $shareUrl,
            'canonical' => $seo['canonical'] ?? $shareUrl,
            'robots' => $seo['robots'] ?? 'noindex, nofollow',
            'og_title' => $seo['og_title'] ?? '',
            'og_description' => $seo['og_description'] ?? '',
            'og_type' => $seo['og_type'] ?? 'website',
            'og_url' => $seo['url'] ?? $shareUrl,
            'og_site_name' => (string) config('app.name', 'SysNoxis'),
            'og_locale' => $seo['og_locale'] ?? 'en_US',
            'og_image' => $seo['og_image'] ?? $this->config->defaultOgImageUrl(),
            'og_image_type' => $seo['og_image_type'] ?? null,
            'og_image_width' => $seo['og_image_width'] ?? null,
            'og_image_height' => $seo['og_image_height'] ?? null,
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'twitter_site' => $seo['twitter_site'] ?? null,
            'privacy_redacted' => ($seo['exclusion_reason'] ?? null) === 'private_or_restricted_post',
        ]);
    }
}
