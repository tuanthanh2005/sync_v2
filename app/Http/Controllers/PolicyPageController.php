<?php

namespace App\Http\Controllers;

use App\Models\PolicyPage;
use App\Services\Seo\CanonicalUrlService;
use App\Services\Seo\SeoMetadataService;
use App\Services\System\LanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyPageController extends Controller
{
    public function __construct(
        private readonly SeoMetadataService $seoMetadata,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Render a published CMS/policy page (or preview for authorized 2FA-verified Admin).
     */
    public function show(Request $request, PolicyPage $policyPage): Response
    {
        return $this->renderPage($request, $policyPage, app()->getLocale(), false);
    }

    /**
     * Render a localized CMS/policy page ONLY when a genuine published translation exists
     * for the requested locale; otherwise 301 redirect to canonical /policies/{slug}.
     */
    public function showLocalized(Request $request, string $locale, PolicyPage $policyPage): Response|RedirectResponse
    {
        $user = $request->user();
        $canPreview = $user?->can('view', $policyPage) ?? false;

        if (! $policyPage->isPubliclyAccessible() && ! $canPreview) {
            abort(404);
        }

        $defaultLocale = $this->languageService->defaultCode();

        if ($locale === $defaultLocale) {
            return redirect()->route('policies.show', ['policyPage' => $policyPage->slug], 301);
        }

        $policyPage->loadMissing('translations');

        if (! $policyPage->hasGenuinePublishedTranslation($locale) && ! $canPreview) {
            return redirect()->route('policies.show', ['policyPage' => $policyPage->slug], 301);
        }

        app()->setLocale($locale);

        return $this->renderPage($request, $policyPage, $locale, true);
    }

    private function renderPage(Request $request, PolicyPage $policyPage, string $locale, bool $isLocalizedRoute): Response
    {
        $user = $request->user();

        if (! $policyPage->isPubliclyAccessible() && ! ($user?->can('view', $policyPage) ?? false)) {
            abort(404);
        }

        $policyPage->loadMissing('translations');

        $seo = $this->seoMetadata->forPolicy($policyPage, $locale, $isLocalizedRoute);
        $title = $policyPage->titleForLocale($locale);
        $summary = $policyPage->summaryForLocale($locale);
        $content = (string) ($policyPage->contentForLocale($locale) ?? '');

        $enabledLanguages = collect($this->languageService->publicEnabledLanguages())->keyBy('code');
        $defaultLocale = $this->languageService->defaultCode();

        $availableLocales = [];
        foreach ($policyPage->availablePublishedLocales() as $code) {
            $meta = $enabledLanguages->get($code);
            if ($meta === null) {
                continue;
            }
            $availableLocales[] = [
                'code' => $code,
                'native_name' => $meta['native_name'],
                'href' => $this->canonicalUrls->policyPath($policyPage, $code === $defaultLocale ? null : $code),
            ];
        }

        return Inertia::render('policies/Show', [
            'policy' => [
                'id' => $policyPage->id,
                'slug' => $policyPage->slug,
                'page_type' => $policyPage->page_type ?: 'legal',
                'title' => $title,
                'summary' => $summary,
                'content' => $content,
                'status' => $policyPage->status->value,
                'status_label' => $policyPage->status->label($locale),
                'is_visible' => $policyPage->is_visible,
                'og_image' => $policyPage->ogImageUrl(),
                'effective_at' => $policyPage->effective_at?->toIso8601String(),
                'published_at' => $policyPage->published_at?->toIso8601String(),
                'updated_at' => $policyPage->updated_at?->toIso8601String(),
                'active_locale' => $locale,
                'available_locales' => $availableLocales,
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'policy',
                'heading' => $title,
                'description' => (string) ($summary ?? ''),
                'body' => strip_tags($content),
            ],
        ]);
    }
}
