<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FooterLinkStoreRequest;
use App\Http\Requests\Admin\FooterLinkUpdateRequest;
use App\Http\Requests\Admin\PolicyPageUpdateRequest;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Services\Policy\PolicyManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PolicyManagementController extends Controller
{
    public function __construct(
        private readonly PolicyManagementService $policyService,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', PolicyPage::class);

        $this->policyService->ensureDefaultPoliciesExist();

        $locale = app()->getLocale();

        $policies = array_values(
            PolicyPage::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (PolicyPage $policy): array => [
                    'id' => $policy->id,
                    'slug' => $policy->slug,
                    'title_vi' => $policy->title_vi,
                    'title_en' => $policy->title_en,
                    'summary_vi' => $policy->summary_vi,
                    'summary_en' => $policy->summary_en,
                    'content_vi' => $policy->content_vi,
                    'content_en' => $policy->content_en,
                    'status' => $policy->status->value,
                    'status_label' => $policy->status->label($locale),
                    'is_visible' => $policy->is_visible,
                    'footer_group' => $policy->footer_group->value,
                    'sort_order' => $policy->sort_order,
                    'published_at' => $policy->published_at?->toIso8601String(),
                    'updated_at' => $policy->updated_at?->toIso8601String(),
                ])
                ->all(),
        );

        $footerLinks = array_values(
            FooterLink::query()
                ->with(['policyPage:id,slug,status,is_visible'])
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
                    'url' => $link->url,
                    'footer_group' => $link->footer_group->value,
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
        ]);
    }

    public function updatePolicy(PolicyPageUpdateRequest $request, PolicyPage $policyPage): RedirectResponse
    {
        /** @var array{
         *     title_vi: string,
         *     title_en: string,
         *     summary_vi?: string|null,
         *     summary_en?: string|null,
         *     content_vi?: string|null,
         *     content_en?: string|null,
         *     status: string,
         *     is_visible: bool,
         *     footer_group: string,
         *     sort_order: int
         * } $validated */
        $validated = $request->validated();

        $this->policyService->updatePolicy($policyPage, $validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Đã lưu cấu hình trang chính sách.'),
        ]);

        return back();
    }

    public function storeLink(FooterLinkStoreRequest $request): RedirectResponse
    {
        /** @var array{
         *     policy_page_id?: int|null,
         *     label_vi: string,
         *     label_en: string,
         *     url?: string|null,
         *     footer_group: string,
         *     sort_order: int,
         *     is_visible: bool,
         *     open_in_new_tab?: bool
         * } $validated */
        $validated = $request->validated();

        $this->policyService->createFooterLink($validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Đã thêm liên kết Footer mới.'),
        ]);

        return back();
    }

    public function updateLink(FooterLinkUpdateRequest $request, FooterLink $footerLink): RedirectResponse
    {
        /** @var array{
         *     label_vi: string,
         *     label_en: string,
         *     url?: string|null,
         *     footer_group: string,
         *     sort_order: int,
         *     is_visible: bool,
         *     open_in_new_tab?: bool
         * } $validated */
        $validated = $request->validated();

        $this->policyService->updateFooterLink($footerLink, $validated, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Đã cập nhật liên kết Footer.'),
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
            'message' => __('Đã xóa liên kết Footer.'),
        ]);

        return back();
    }
}
