<?php

namespace App\Services\Policy;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\DB;

class PolicyManagementService
{
    public function __construct(
        private readonly PolicySanitizer $sanitizer,
        private readonly FooterMenuService $footerMenuService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Standard policy page templates kept in draft status until approved by Admin.
     *
     * @return list<array{
     *     slug: string,
     *     title_vi: string,
     *     title_en: string,
     *     footer_group: FooterGroup,
     *     sort_order: int
     * }>
     */
    public static function defaultTemplates(): array
    {
        return [
            [
                'slug' => 'about-us',
                'title_vi' => 'Về chúng tôi',
                'title_en' => 'About Us',
                'footer_group' => FooterGroup::Platform,
                'sort_order' => 10,
            ],
            [
                'slug' => 'terms-of-service',
                'title_vi' => 'Điều khoản dịch vụ',
                'title_en' => 'Terms of Service',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 20,
            ],
            [
                'slug' => 'privacy-policy',
                'title_vi' => 'Chính sách bảo mật',
                'title_en' => 'Privacy Policy',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 30,
            ],
            [
                'slug' => 'cookie-policy',
                'title_vi' => 'Chính sách Cookie',
                'title_en' => 'Cookie Policy',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 40,
            ],
            [
                'slug' => 'community-guidelines',
                'title_vi' => 'Quy tắc cộng đồng',
                'title_en' => 'Community Guidelines',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 50,
            ],
            [
                'slug' => 'advertising-policy',
                'title_vi' => 'Chính sách quảng cáo',
                'title_en' => 'Advertising Policy',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 60,
            ],
            [
                'slug' => 'refund-policy',
                'title_vi' => 'Chính sách hoàn tiền',
                'title_en' => 'Refund Policy',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 70,
            ],
            [
                'slug' => 'copyright-policy',
                'title_vi' => 'Chính sách bản quyền',
                'title_en' => 'Copyright Policy',
                'footer_group' => FooterGroup::Legal,
                'sort_order' => 80,
            ],
            [
                'slug' => 'help-center',
                'title_vi' => 'Trung tâm trợ giúp',
                'title_en' => 'Help Center',
                'footer_group' => FooterGroup::Support,
                'sort_order' => 90,
            ],
            [
                'slug' => 'contact-us',
                'title_vi' => 'Liên hệ',
                'title_en' => 'Contact Us',
                'footer_group' => FooterGroup::Support,
                'sort_order' => 100,
            ],
        ];
    }

    /**
     * Idempotently ensure the 10 standard policy records exist in draft state.
     */
    public function ensureDefaultPoliciesExist(): void
    {
        DB::transaction(function (): void {
            foreach (self::defaultTemplates() as $template) {
                $policy = PolicyPage::query()->firstOrCreate(
                    ['slug' => $template['slug']],
                    [
                        'title_vi' => $template['title_vi'],
                        'title_en' => $template['title_en'],
                        'summary_vi' => null,
                        'summary_en' => null,
                        'content_vi' => null,
                        'content_en' => null,
                        'status' => PolicyStatus::Draft,
                        'is_visible' => false,
                        'footer_group' => $template['footer_group'],
                        'sort_order' => $template['sort_order'],
                    ],
                );

                FooterLink::query()->firstOrCreate(
                    ['policy_page_id' => $policy->id],
                    [
                        'label_vi' => $policy->title_vi,
                        'label_en' => $policy->title_en,
                        'footer_group' => $policy->footer_group,
                        'sort_order' => $policy->sort_order,
                        'is_visible' => false,
                        'open_in_new_tab' => false,
                    ],
                );
            }
        });
    }

    /**
     * Update a policy page and synchronize its linked footer item safely inside a transaction.
     *
     * @param  array{
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
     * }  $data
     */
    public function updatePolicy(PolicyPage $policy, array $data, User $actor): PolicyPage
    {
        return DB::transaction(function () use ($policy, $data, $actor): PolicyPage {
            $status = PolicyStatus::from($data['status']);
            $group = FooterGroup::from($data['footer_group']);
            $sanitizedVi = $this->sanitizer->sanitize($data['content_vi'] ?? null);
            $sanitizedEn = $this->sanitizer->sanitize($data['content_en'] ?? null);

            $policy->fill([
                'title_vi' => trim($data['title_vi']),
                'title_en' => trim($data['title_en']),
                'summary_vi' => isset($data['summary_vi']) ? trim($data['summary_vi']) : null,
                'summary_en' => isset($data['summary_en']) ? trim($data['summary_en']) : null,
                'content_vi' => $sanitizedVi,
                'content_en' => $sanitizedEn,
                'status' => $status,
                'is_visible' => $data['is_visible'],
                'footer_group' => $group,
                'sort_order' => $data['sort_order'],
                'published_at' => $status === PolicyStatus::Published
                    ? ($policy->published_at ?? now())
                    : null,
                'updated_by' => $actor->id,
            ]);
            $policy->save();

            FooterLink::query()
                ->where('policy_page_id', $policy->id)
                ->update([
                    'label_vi' => $policy->title_vi,
                    'label_en' => $policy->title_en,
                    'footer_group' => $policy->footer_group->value,
                    'sort_order' => $policy->sort_order,
                    'is_visible' => $policy->isPubliclyAccessible(),
                    'updated_by' => $actor->id,
                ]);

            $this->footerMenuService->flushCache();

            $this->auditLogger->log(
                $status === PolicyStatus::Published ? 'policy.published_or_updated' : 'policy.updated_draft',
                $policy,
                [
                    'slug' => $policy->slug,
                    'status' => $policy->status->value,
                    'is_visible' => $policy->is_visible,
                    'sort_order' => $policy->sort_order,
                ],
                $actor,
            );

            return $policy->fresh() ?? $policy;
        });
    }

    /**
     * Create a new footer link.
     *
     * @param  array{
     *     policy_page_id?: int|null,
     *     label_vi: string,
     *     label_en: string,
     *     route_name?: string|null,
     *     url?: string|null,
     *     footer_group: string,
     *     sort_order: int,
     *     is_visible: bool,
     *     open_in_new_tab?: bool
     * }  $data
     */
    public function createFooterLink(array $data, User $actor): FooterLink
    {
        return DB::transaction(function () use ($data, $actor): FooterLink {
            $link = FooterLink::query()->create([
                'policy_page_id' => $data['policy_page_id'] ?? null,
                'label_vi' => trim($data['label_vi']),
                'label_en' => trim($data['label_en']),
                'route_name' => $data['route_name'] ?? null,
                'url' => $data['url'] ?? null,
                'footer_group' => FooterGroup::from($data['footer_group']),
                'sort_order' => $data['sort_order'],
                'is_visible' => $data['is_visible'],
                'open_in_new_tab' => $data['open_in_new_tab'] ?? false,
                'updated_by' => $actor->id,
            ]);

            $this->footerMenuService->flushCache();
            $this->auditLogger->log('footer_link.created', $link, ['label_en' => $link->label_en], $actor);

            return $link;
        });
    }

    /**
     * Update an existing footer link (order, visibility, labels, or destination).
     *
     * @param  array{
     *     label_vi: string,
     *     label_en: string,
     *     url?: string|null,
     *     footer_group: string,
     *     sort_order: int,
     *     is_visible: bool,
     *     open_in_new_tab?: bool
     * }  $data
     */
    public function updateFooterLink(FooterLink $link, array $data, User $actor): FooterLink
    {
        return DB::transaction(function () use ($link, $data, $actor): FooterLink {
            $link->fill([
                'label_vi' => trim($data['label_vi']),
                'label_en' => trim($data['label_en']),
                'url' => array_key_exists('url', $data) ? $data['url'] : $link->url,
                'footer_group' => FooterGroup::from($data['footer_group']),
                'sort_order' => $data['sort_order'],
                'is_visible' => $data['is_visible'],
                'open_in_new_tab' => $data['open_in_new_tab'] ?? $link->open_in_new_tab,
                'updated_by' => $actor->id,
            ]);
            $link->save();

            $this->footerMenuService->flushCache();
            $this->auditLogger->log('footer_link.updated', $link, [
                'is_visible' => $link->is_visible,
                'sort_order' => $link->sort_order,
                'footer_group' => $link->footer_group->value,
            ], $actor);

            return $link->fresh() ?? $link;
        });
    }

    public function deleteFooterLink(FooterLink $link, User $actor): void
    {
        DB::transaction(function () use ($link, $actor): void {
            $label = $link->label_en;
            $id = $link->id;
            $link->delete();

            $this->footerMenuService->flushCache();
            $this->auditLogger->log('footer_link.deleted', null, ['deleted_id' => $id, 'label_en' => $label], $actor);
        });
    }
}
