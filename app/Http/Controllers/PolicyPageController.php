<?php

namespace App\Http\Controllers;

use App\Models\PolicyPage;
use App\Services\Seo\SeoMetadataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyPageController extends Controller
{
    public function __construct(
        private readonly SeoMetadataService $seoMetadata,
    ) {}

    /**
     * Render a published policy page (or preview for authorized 2FA-verified Admin).
     */
    public function show(Request $request, PolicyPage $policyPage): Response
    {
        $user = $request->user();

        if (! $policyPage->isPubliclyAccessible() && ! ($user?->can('view', $policyPage) ?? false)) {
            abort(404);
        }

        $locale = app()->getLocale();
        $seo = $this->seoMetadata->forPolicy($policyPage, $locale);
        $title = $policyPage->titleForLocale($locale);
        $content = (string) ($policyPage->contentForLocale($locale) ?? '');

        return Inertia::render('policies/Show', [
            'policy' => [
                'id' => $policyPage->id,
                'slug' => $policyPage->slug,
                'title' => $title,
                'summary' => $policyPage->summaryForLocale($locale),
                'content' => $content,
                'status' => $policyPage->status->value,
                'status_label' => $policyPage->status->label($locale),
                'is_visible' => $policyPage->is_visible,
                'published_at' => $policyPage->published_at?->toIso8601String(),
                'updated_at' => $policyPage->updated_at?->toIso8601String(),
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'policy',
                'heading' => $title,
                'description' => (string) ($policyPage->summaryForLocale($locale) ?? ''),
                'body' => strip_tags($content),
            ],
        ]);
    }
}
