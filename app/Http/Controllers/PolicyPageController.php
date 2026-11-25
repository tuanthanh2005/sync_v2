<?php

namespace App\Http\Controllers;

use App\Models\PolicyPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyPageController extends Controller
{
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

        return Inertia::render('policies/Show', [
            'policy' => [
                'id' => $policyPage->id,
                'slug' => $policyPage->slug,
                'title' => $policyPage->titleForLocale($locale),
                'summary' => $policyPage->summaryForLocale($locale),
                'content' => $policyPage->contentForLocale($locale),
                'status' => $policyPage->status->value,
                'status_label' => $policyPage->status->label($locale),
                'is_visible' => $policyPage->is_visible,
                'published_at' => $policyPage->published_at?->toIso8601String(),
                'updated_at' => $policyPage->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
