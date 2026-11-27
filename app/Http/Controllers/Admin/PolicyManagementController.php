<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FooterLinkStoreRequest;
use App\Http\Requests\Admin\FooterLinkUpdateRequest;
use App\Http\Requests\Admin\PolicyPageStoreRequest;
use App\Http\Requests\Admin\PolicyPageUpdateRequest;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Models\PolicyPageRevision;
use App\Models\PolicyPageTranslation;
use App\Rules\ValidUploadedImage;
use App\Services\Policy\PolicyManagementService;
use App\Services\System\LanguageService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PolicyManagementController extends Controller
{
    public function __construct(
        private readonly PolicyManagementService $policyService,
        private readonly LanguageService $languageService,
        private readonly SystemSettingService $systemSettingService,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', PolicyPage::class);

        $this->policyService->ensureDefaultPoliciesExist();

        $locale = app()->getLocale();

        $policies = array_values(
            PolicyPage::query()
                ->with([
                    'translations',
                    'revisions' => static fn ($q) => $q->with('author:id,name,username')->limit(20),
                    'footerLinks:id,policy_page_id,placement',
                ])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (PolicyPage $policy): array => [
                    'id' => $policy->id,
                    'slug' => $policy->slug,
                    'page_type' => $policy->page_type ?: 'legal',
                    'title_vi' => $policy->title_vi,
                    'title_en' => $policy->title_en,
                    'summary_vi' => $policy->summary_vi,
                    'summary_en' => $policy->summary_en,
                    'content_vi' => $policy->content_vi,
                    'content_en' => $policy->content_en,
                    'seo_title' => $policy->seo_title,
                    'meta_description' => $policy->meta_description,
                    'canonical_url' => $policy->canonical_url,
                    'og_image' => $policy->ogImageUrl(),
                    'index_eligible' => (bool) $policy->index_eligible,
                    'status' => $policy->status->value,
                    'status_label' => $policy->status->label($locale),
                    'is_visible' => $policy->is_visible,
                    'footer_group' => $policy->footer_group->value,
                    'placement' => $policy->footerLinks->first()?->placement ?: 'more',
                    'sort_order' => $policy->sort_order,
                    'published_at' => $policy->published_at?->toIso8601String(),
                    'effective_at' => $policy->effective_at?->toDateString(),
                    'archived_at' => $policy->archived_at?->toIso8601String(),
                    'updated_at' => $policy->updated_at?->toIso8601String(),
                    'translations' => $policy->translations
                        ->mapWithKeys(static fn (PolicyPageTranslation $tr): array => [
                            $tr->locale => [
                                'locale' => $tr->locale,
                                'title' => $tr->title,
                                'summary' => $tr->summary,
                                'content' => $tr->content,
                                'seo_title' => $tr->seo_title,
                                'meta_description' => $tr->meta_description,
                                'status' => $tr->status,
                                'published_at' => $tr->published_at?->toIso8601String(),
                                'updated_at' => $tr->updated_at?->toIso8601String(),
                            ],
                        ])
                        ->all(),
                    'revisions' => $policy->revisions
                        ->map(static fn (PolicyPageRevision $rev): array => [
                            'id' => $rev->id,
                            'locale' => $rev->locale,
                            'version_number' => $rev->version_number,
                            'title' => $rev->title,
                            'change_note' => $rev->change_note,
                            'author_name' => $rev->author?->name,
                            'created_at' => $rev->created_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->all(),
        );

        $footerLinks = array_values(
            FooterLink::query()
                ->with(['policyPage:id,slug,status,is_visible,archived_at'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (FooterLink $link): array => [
                    'id' => $link->id,
                    'policy_page_id' => $link->policy_page_id,
                    'policy_slug' => $link->policyPage?->slug,
                    'policy_status' => $link->policyPage?->status->value,
                    'label_vi' => $link->label_vi,
                    'label_en' => $link->label_en,
                    'translations' => is_array($link->translations) ? $link->translations : [],
                    'url' => $link->url,
                    'footer_group' => $link->footer_group->value,
                    'placement' => in_array($link->placement, ['main', 'more'], true) ? $link->placement : 'main',
                    'sort_order' => $link->sort_order,
                    'is_visible' => $link->is_visible,
                    'open_in_new_tab' => $link->open_in_new_tab,
                ])
                ->all(),
        );

        return Inertia::render('admin/Policies', [
            'policies' => $policies,
            'footerLinks' => $footerLinks,
            'footerGroups' => array_map(
                static fn (FooterGroup $group): array => [
                    'value' => $group->value,
                    'label' => $group->label($locale),
                ],
                FooterGroup::cases(),
            ),
            'policyStatuses' => array_map(
                static fn (PolicyStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label($locale),
                ],
                PolicyStatus::cases(),
            ),
            'pageTypes' => PolicyManagementService::PAGE_TYPES,
            'availableLanguages' => $this->languageService->publicEnabledLanguages(),
            'footerSettings' => [
                'contact_email' => $this->systemSettingService->get('contact_email', ''),
                'support_contact_info' => $this->systemSettingService->get('support_contact_info', ''),
                'footer_copyright_text' => $this->systemSettingService->get('footer_copyright_text', ''),
            ],
        ]);
    }

    public function storePolicy(PolicyPageStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $ogImage = $request->file('og_image');

        $this->policyService->createPolicy(
            $validated,
            $request->user(),
            $ogImage instanceof UploadedFile ? $ogImage : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('CMS page created successfully.'),
        ]);

        return back();
    }

    public function updatePolicy(PolicyPageUpdateRequest $request, PolicyPage $policyPage): RedirectResponse
    {
        $validated = $request->validated();
        $ogImage = $request->file('og_image');

        $this->policyService->updatePolicy(
            $policyPage,
            $validated,
            $request->user(),
            $ogImage instanceof UploadedFile ? $ogImage : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Page settings saved.'),
        ]);

        return back();
    }

    public function saveTranslation(Request $request, PolicyPage $policyPage): RedirectResponse
    {
        Gate::authorize('update', $policyPage);

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(LanguageService::allCodes())],
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:120000'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'status' => ['required', 'string', Rule::in(['draft', 'published'])],
            'change_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->policyService->saveTranslation($policyPage, $validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Page translation saved.'),
        ]);

        return back();
    }

    public function restoreRevision(Request $request, PolicyPage $policyPage, PolicyPageRevision $revision): RedirectResponse
    {
        Gate::authorize('update', $policyPage);

        $this->policyService->restoreRevision($policyPage, $revision, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Version restored successfully.'),
        ]);

        return back();
    }

    public function uploadImage(Request $request): JsonResponse
    {
        Gate::authorize('create', PolicyPage::class);

        $request->validate([
            'image' => ['required', 'file', new ValidUploadedImage('cms')],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $result = $this->policyService->uploadCmsImage($file, $request->user());

        return response()->json($result, 201);
    }

    public function updateFooterSettings(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', PolicyPage::class);

        $validated = $request->validate([
            'contact_email' => ['nullable', 'string', 'max:180'],
            'support_contact_info' => ['nullable', 'string', 'max:300'],
            'footer_copyright_text' => ['nullable', 'string', 'max:200'],
        ]);

        $this->policyService->updateFooterSettings($validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Footer contact and branding settings saved.'),
        ]);

        return back();
    }

    public function storeLink(FooterLinkStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->policyService->createFooterLink($validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('New footer link added.'),
        ]);

        return back();
    }

    public function updateLink(FooterLinkUpdateRequest $request, FooterLink $footerLink): RedirectResponse
    {
        $validated = $request->validated();

        $this->policyService->updateFooterLink($footerLink, $validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Footer link updated.'),
        ]);

        return back();
    }

    public function destroyLink(FooterLink $footerLink): RedirectResponse
    {
        Gate::authorize('viewAny', PolicyPage::class);

        $user = request()->user();

        if ($user !== null) {
            $this->policyService->deleteFooterLink($footerLink, $user);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Footer link removed.'),
        ]);

        return back();
    }
}
