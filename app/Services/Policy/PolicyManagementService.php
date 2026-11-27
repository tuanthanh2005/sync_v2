<?php

namespace App\Services\Policy;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Enums\SeoEntityType;
use App\Jobs\Seo\ProcessSeoEntityJob;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Models\PolicyPageRevision;
use App\Models\PolicyPageTranslation;
use App\Models\User;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use App\Services\System\LanguageService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PolicyManagementService
{
    /** @var list<string> */
    public const PAGE_TYPES = ['company', 'support', 'legal', 'business', 'general'];

    public function __construct(
        private readonly PolicySanitizer $sanitizer,
        private readonly FooterMenuService $footerMenuService,
        private readonly SafeImageUploadService $imageUploadService,
        private readonly SystemSettingService $systemSettingService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Standard CMS page templates kept strictly in draft status until authored & approved by Admin.
     *
     * @return list<array{
     *     slug: string,
     *     page_type: string,
     *     title_vi: string,
     *     title_en: string,
     *     footer_group: FooterGroup,
     *     placement: string,
     *     sort_order: int
     * }>
     */
    public static function defaultTemplates(): array
    {
        return [
            [
                'slug' => 'privacy-policy',
                'page_type' => 'legal',
                'title_vi' => 'Chính sách bảo mật',
                'title_en' => 'Privacy Policy',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'main',
                'sort_order' => 10,
            ],
            [
                'slug' => 'terms-of-service',
                'page_type' => 'legal',
                'title_vi' => 'Điều khoản dịch vụ',
                'title_en' => 'Terms of Service',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'main',
                'sort_order' => 20,
            ],
            [
                'slug' => 'advertising-policy',
                'page_type' => 'business',
                'title_vi' => 'Chính sách quảng cáo',
                'title_en' => 'Advertising Policy',
                'footer_group' => FooterGroup::Business,
                'placement' => 'main',
                'sort_order' => 30,
            ],
            [
                'slug' => 'cookie-policy',
                'page_type' => 'legal',
                'title_vi' => 'Chính sách Cookie',
                'title_en' => 'Cookie Policy',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'main',
                'sort_order' => 40,
            ],
            [
                'slug' => 'help-center',
                'page_type' => 'support',
                'title_vi' => 'Trung tâm trợ giúp',
                'title_en' => 'Help Center',
                'footer_group' => FooterGroup::Support,
                'placement' => 'main',
                'sort_order' => 50,
            ],
            [
                'slug' => 'about-us',
                'page_type' => 'company',
                'title_vi' => 'Về chúng tôi',
                'title_en' => 'About Us',
                'footer_group' => FooterGroup::Company,
                'placement' => 'more',
                'sort_order' => 60,
            ],
            [
                'slug' => 'contact-us',
                'page_type' => 'support',
                'title_vi' => 'Liên hệ',
                'title_en' => 'Contact Us',
                'footer_group' => FooterGroup::Support,
                'placement' => 'more',
                'sort_order' => 70,
            ],
            [
                'slug' => 'community-guidelines',
                'page_type' => 'legal',
                'title_vi' => 'Quy tắc cộng đồng',
                'title_en' => 'Community Guidelines',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'more',
                'sort_order' => 80,
            ],
            [
                'slug' => 'copyright-policy',
                'page_type' => 'legal',
                'title_vi' => 'Chính sách bản quyền',
                'title_en' => 'Copyright Policy',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'more',
                'sort_order' => 90,
            ],
            [
                'slug' => 'refund-policy',
                'page_type' => 'business',
                'title_vi' => 'Chính sách hoàn tiền',
                'title_en' => 'Refund Policy',
                'footer_group' => FooterGroup::Business,
                'placement' => 'more',
                'sort_order' => 100,
            ],
            [
                'slug' => 'premium-policy',
                'page_type' => 'business',
                'title_vi' => 'Chính sách thành viên Premium',
                'title_en' => 'Premium Membership Policy',
                'footer_group' => FooterGroup::Business,
                'placement' => 'more',
                'sort_order' => 110,
            ],
            [
                'slug' => 'payment-terms',
                'page_type' => 'business',
                'title_vi' => 'Điều khoản thanh toán & Số dư quảng cáo',
                'title_en' => 'Payment & Advertising Balance Terms',
                'footer_group' => FooterGroup::Business,
                'placement' => 'more',
                'sort_order' => 120,
            ],
            [
                'slug' => 'referral-terms',
                'page_type' => 'business',
                'title_vi' => 'Điều khoản chương trình giới thiệu',
                'title_en' => 'Referral Program Terms',
                'footer_group' => FooterGroup::Business,
                'placement' => 'more',
                'sort_order' => 130,
            ],
            [
                'slug' => 'ai-content-disclosure',
                'page_type' => 'legal',
                'title_vi' => 'Minh bạch nội dung AI',
                'title_en' => 'AI Content Disclosure',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'more',
                'sort_order' => 140,
            ],
            [
                'slug' => 'account-deletion',
                'page_type' => 'legal',
                'title_vi' => 'Hướng dẫn xóa tài khoản & dữ liệu',
                'title_en' => 'Account & Data Deletion',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'more',
                'sort_order' => 150,
            ],
            [
                'slug' => 'child-safety-standards',
                'page_type' => 'legal',
                'title_vi' => 'Tiêu chuẩn an toàn trẻ em',
                'title_en' => 'Child Safety Standards',
                'footer_group' => FooterGroup::Legal,
                'placement' => 'more',
                'sort_order' => 160,
            ],
            [
                'slug' => 'faq',
                'page_type' => 'support',
                'title_vi' => 'Câu hỏi thường gặp',
                'title_en' => 'FAQ',
                'footer_group' => FooterGroup::Support,
                'placement' => 'more',
                'sort_order' => 170,
            ],
            [
                'slug' => 'report-abuse',
                'page_type' => 'support',
                'title_vi' => 'Báo cáo vi phạm & Lạm dụng',
                'title_en' => 'Report Abuse',
                'footer_group' => FooterGroup::Support,
                'placement' => 'more',
                'sort_order' => 180,
            ],
            [
                'slug' => 'careers',
                'page_type' => 'company',
                'title_vi' => 'Tuyển dụng',
                'title_en' => 'Careers',
                'footer_group' => FooterGroup::Company,
                'placement' => 'more',
                'sort_order' => 190,
            ],
            [
                'slug' => 'developers',
                'page_type' => 'company',
                'title_vi' => 'Nhà phát triển',
                'title_en' => 'Developers',
                'footer_group' => FooterGroup::Company,
                'placement' => 'more',
                'sort_order' => 200,
            ],
        ];
    }

    /**
     * Idempotently ensure the standard CMS page templates exist in draft state.
     */
    public function ensureDefaultPoliciesExist(): void
    {
        DB::transaction(function (): void {
            foreach (self::defaultTemplates() as $template) {
                $policy = PolicyPage::query()->firstOrCreate(
                    ['slug' => $template['slug']],
                    [
                        'page_type' => $template['page_type'],
                        'title_vi' => $template['title_vi'],
                        'title_en' => $template['title_en'],
                        'summary_vi' => null,
                        'summary_en' => null,
                        'content_vi' => null,
                        'content_en' => null,
                        'status' => PolicyStatus::Draft,
                        'is_visible' => false,
                        'index_eligible' => true,
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
                        'placement' => $template['placement'],
                        'sort_order' => $policy->sort_order,
                        'is_visible' => false,
                        'open_in_new_tab' => false,
                    ],
                );
            }
        });
    }

    /**
     * Create a brand-new CMS page and its linked footer entry inside a transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPolicy(array $data, User $actor, ?UploadedFile $ogImage = null): PolicyPage
    {
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = Str::slug($rawSlug !== '' ? $rawSlug : (string) ($data['title_en'] ?? 'page'));

        if ($slug === '' || PolicyPage::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('This page URL slug is already in use or invalid.'),
            ]);
        }

        $ogAsset = $ogImage !== null
            ? $this->imageUploadService->storeImage($ogImage, 'cms/og', 'cms', 'og_image', false)
            : null;

        return DB::transaction(function () use ($slug, $data, $actor, $ogAsset): PolicyPage {
            $status = PolicyStatus::from((string) ($data['status'] ?? PolicyStatus::Draft->value));
            $group = FooterGroup::from((string) ($data['footer_group'] ?? FooterGroup::Legal->value));
            $pageType = in_array((string) ($data['page_type'] ?? 'general'), self::PAGE_TYPES, true)
                ? (string) $data['page_type']
                : 'general';
            $isVisible = $status === PolicyStatus::Archived ? false : (bool) ($data['is_visible'] ?? false);

            $sanitizedVi = $this->sanitizer->sanitize(isset($data['content_vi']) ? (string) $data['content_vi'] : null);
            $sanitizedEn = $this->sanitizer->sanitize(isset($data['content_en']) ? (string) $data['content_en'] : null);
            $effectiveAt = $this->parseDateOrNull($data['effective_at'] ?? null);

            $policy = PolicyPage::query()->create([
                'slug' => $slug,
                'page_type' => $pageType,
                'title_vi' => trim((string) ($data['title_vi'] ?? $data['title_en'])),
                'title_en' => trim((string) $data['title_en']),
                'summary_vi' => isset($data['summary_vi']) && trim((string) $data['summary_vi']) !== '' ? trim((string) $data['summary_vi']) : null,
                'summary_en' => isset($data['summary_en']) && trim((string) $data['summary_en']) !== '' ? trim((string) $data['summary_en']) : null,
                'content_vi' => $sanitizedVi,
                'content_en' => $sanitizedEn,
                'seo_title' => isset($data['seo_title']) && trim((string) $data['seo_title']) !== '' ? trim((string) $data['seo_title']) : null,
                'meta_description' => isset($data['meta_description']) && trim((string) $data['meta_description']) !== '' ? trim((string) $data['meta_description']) : null,
                'canonical_url' => isset($data['canonical_url']) && trim((string) $data['canonical_url']) !== '' ? trim((string) $data['canonical_url']) : null,
                'og_image_disk' => $ogAsset['disk'] ?? null,
                'og_image_path' => $ogAsset['path'] ?? null,
                'index_eligible' => array_key_exists('index_eligible', $data) ? (bool) $data['index_eligible'] : true,
                'status' => $status,
                'is_visible' => $isVisible,
                'footer_group' => $group,
                'sort_order' => (int) ($data['sort_order'] ?? 200),
                'published_at' => $status === PolicyStatus::Published ? now() : null,
                'effective_at' => $effectiveAt,
                'archived_at' => $status === PolicyStatus::Archived ? now() : null,
                'updated_by' => $actor->id,
            ]);

            $placement = in_array((string) ($data['placement'] ?? 'more'), ['main', 'more'], true)
                ? (string) $data['placement']
                : 'more';

            FooterLink::query()->create([
                'policy_page_id' => $policy->id,
                'label_vi' => $policy->title_vi,
                'label_en' => $policy->title_en,
                'footer_group' => $policy->footer_group,
                'placement' => $placement,
                'sort_order' => $policy->sort_order,
                'is_visible' => $policy->isPubliclyAccessible(),
                'open_in_new_tab' => false,
                'updated_by' => $actor->id,
            ]);

            if ($sanitizedEn !== null) {
                $this->recordRevision($policy, 'en', $policy->title_en, $policy->summary_en, $sanitizedEn, $actor, __('Initial version'), $effectiveAt);
            }
            if ($sanitizedVi !== null) {
                $this->recordRevision($policy, 'vi', $policy->title_vi, $policy->summary_vi, $sanitizedVi, $actor, __('Initial version'), $effectiveAt);
            }

            $this->footerMenuService->flushCache();
            DB::afterCommit(static function () use ($policy): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Policy->value, $policy->id);
            });

            $this->auditLogger->log('policy.created', $policy, [
                'slug' => $policy->slug,
                'page_type' => $policy->page_type,
                'status' => $policy->status->value,
            ], $actor);

            return $policy->fresh(['translations', 'revisions']) ?? $policy;
        });
    }

    /**
     * Update a policy/CMS page, record revision snapshots, sync footer link, and refresh SEO registry.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePolicy(PolicyPage $policy, array $data, User $actor, ?UploadedFile $ogImage = null): PolicyPage
    {
        $newOgAsset = $ogImage !== null
            ? $this->imageUploadService->storeImage($ogImage, 'cms/og', 'cms', 'og_image', false)
            : null;
        $removeOg = (bool) ($data['remove_og_image'] ?? false);
        $oldOgDisk = $policy->og_image_disk;
        $oldOgPath = $policy->og_image_path;

        $updated = DB::transaction(function () use ($policy, $data, $actor, $newOgAsset, $removeOg): PolicyPage {
            $status = PolicyStatus::from((string) $data['status']);
            $group = FooterGroup::from((string) $data['footer_group']);
            $sanitizedVi = $this->sanitizer->sanitize(isset($data['content_vi']) ? (string) $data['content_vi'] : null);
            $sanitizedEn = $this->sanitizer->sanitize(isset($data['content_en']) ? (string) $data['content_en'] : null);
            $isVisible = $status === PolicyStatus::Archived ? false : (bool) $data['is_visible'];

            $pageType = isset($data['page_type']) && in_array((string) $data['page_type'], self::PAGE_TYPES, true)
                ? (string) $data['page_type']
                : ($policy->page_type ?: 'legal');

            $effectiveAt = array_key_exists('effective_at', $data)
                ? $this->parseDateOrNull($data['effective_at'])
                : $policy->effective_at;

            $enChanged = $sanitizedEn !== $policy->content_en || trim((string) $data['title_en']) !== $policy->title_en;
            $viChanged = $sanitizedVi !== $policy->content_vi || trim((string) $data['title_vi']) !== $policy->title_vi;

            $attributes = [
                'page_type' => $pageType,
                'title_vi' => trim((string) $data['title_vi']),
                'title_en' => trim((string) $data['title_en']),
                'summary_vi' => isset($data['summary_vi']) && trim((string) $data['summary_vi']) !== '' ? trim((string) $data['summary_vi']) : null,
                'summary_en' => isset($data['summary_en']) && trim((string) $data['summary_en']) !== '' ? trim((string) $data['summary_en']) : null,
                'content_vi' => $sanitizedVi,
                'content_en' => $sanitizedEn,
                'status' => $status,
                'is_visible' => $isVisible,
                'footer_group' => $group,
                'sort_order' => (int) $data['sort_order'],
                'published_at' => $status === PolicyStatus::Published
                    ? ($policy->published_at ?? now())
                    : null,
                'effective_at' => $effectiveAt,
                'archived_at' => $status === PolicyStatus::Archived
                    ? ($policy->archived_at ?? now())
                    : null,
                'updated_by' => $actor->id,
            ];

            if (array_key_exists('seo_title', $data)) {
                $attributes['seo_title'] = is_string($data['seo_title']) && trim($data['seo_title']) !== '' ? trim($data['seo_title']) : null;
            }
            if (array_key_exists('meta_description', $data)) {
                $attributes['meta_description'] = is_string($data['meta_description']) && trim($data['meta_description']) !== '' ? trim($data['meta_description']) : null;
            }
            if (array_key_exists('canonical_url', $data)) {
                $attributes['canonical_url'] = is_string($data['canonical_url']) && trim($data['canonical_url']) !== '' ? trim($data['canonical_url']) : null;
            }
            if (array_key_exists('index_eligible', $data)) {
                $attributes['index_eligible'] = (bool) $data['index_eligible'];
            }
            if ($newOgAsset !== null) {
                $attributes['og_image_disk'] = $newOgAsset['disk'];
                $attributes['og_image_path'] = $newOgAsset['path'];
            } elseif ($removeOg) {
                $attributes['og_image_disk'] = null;
                $attributes['og_image_path'] = null;
            }

            $policy->fill($attributes);
            $policy->save();

            $changeNote = isset($data['change_note']) && is_string($data['change_note']) && trim($data['change_note']) !== ''
                ? mb_substr(trim($data['change_note']), 0, 255)
                : null;

            if ($enChanged && $sanitizedEn !== null) {
                $this->recordRevision($policy, 'en', $policy->title_en, $policy->summary_en, $sanitizedEn, $actor, $changeNote, $effectiveAt);
            }
            if ($viChanged && $sanitizedVi !== null) {
                $this->recordRevision($policy, 'vi', $policy->title_vi, $policy->summary_vi, $sanitizedVi, $actor, $changeNote, $effectiveAt);
            }

            $footerUpdate = [
                'label_vi' => $policy->title_vi,
                'label_en' => $policy->title_en,
                'footer_group' => $policy->footer_group->value,
                'sort_order' => $policy->sort_order,
                'is_visible' => $policy->isPubliclyAccessible(),
                'updated_by' => $actor->id,
            ];
            if (isset($data['placement']) && in_array((string) $data['placement'], ['main', 'more'], true)) {
                $footerUpdate['placement'] = (string) $data['placement'];
            }

            FooterLink::query()
                ->where('policy_page_id', $policy->id)
                ->update($footerUpdate);

            $this->footerMenuService->flushCache();
            DB::afterCommit(static function () use ($policy): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Policy->value, $policy->id);
            });

            $this->auditLogger->log(
                $status === PolicyStatus::Published ? 'policy.published_or_updated' : ($status === PolicyStatus::Archived ? 'policy.archived' : 'policy.updated_draft'),
                $policy,
                [
                    'slug' => $policy->slug,
                    'status' => $policy->status->value,
                    'is_visible' => $policy->is_visible,
                    'index_eligible' => $policy->index_eligible,
                    'sort_order' => $policy->sort_order,
                ],
                $actor,
            );

            return $policy->fresh(['translations', 'revisions']) ?? $policy;
        });

        if (($newOgAsset !== null || $removeOg) && is_string($oldOgPath) && $oldOgPath !== '') {
            $disk = is_string($oldOgDisk) && $oldOgDisk !== '' ? $oldOgDisk : (string) config('syncviet.media_disk', 'public');
            Storage::disk($disk)->delete($oldOgPath);
        }

        return $updated;
    }

    /**
     * Save or publish a localized translation for any of the 15 supported languages.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveTranslation(PolicyPage $policy, array $data, User $actor): PolicyPageTranslation
    {
        $locale = trim((string) ($data['locale'] ?? ''));

        if (! in_array($locale, LanguageService::allCodes(), true)) {
            throw ValidationException::withMessages([
                'locale' => __('Unsupported language code.'),
            ]);
        }

        return DB::transaction(function () use ($policy, $locale, $data, $actor): PolicyPageTranslation {
            $sanitizedContent = $this->sanitizer->sanitize(isset($data['content']) ? (string) $data['content'] : null);
            $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published'], true)
                ? (string) $data['status']
                : 'draft';

            $existing = PolicyPageTranslation::query()
                ->where('policy_page_id', $policy->id)
                ->where('locale', $locale)
                ->first();

            $translation = PolicyPageTranslation::query()->updateOrCreate(
                [
                    'policy_page_id' => $policy->id,
                    'locale' => $locale,
                ],
                [
                    'title' => trim((string) $data['title']),
                    'summary' => isset($data['summary']) && trim((string) $data['summary']) !== '' ? trim((string) $data['summary']) : null,
                    'content' => $sanitizedContent,
                    'seo_title' => isset($data['seo_title']) && trim((string) $data['seo_title']) !== '' ? trim((string) $data['seo_title']) : null,
                    'meta_description' => isset($data['meta_description']) && trim((string) $data['meta_description']) !== '' ? trim((string) $data['meta_description']) : null,
                    'status' => $status,
                    'published_at' => $status === 'published'
                        ? ($existing !== null && $existing->published_at !== null ? $existing->published_at : now())
                        : null,
                    'updated_by' => $actor->id,
                ],
            );

            // Sync en/vi columns cleanly when editing the primary English/Vietnamese translations.
            if ($locale === 'en' && $sanitizedContent !== null) {
                $policy->forceFill([
                    'title_en' => $translation->title,
                    'summary_en' => $translation->summary,
                    'content_en' => $sanitizedContent,
                    'updated_by' => $actor->id,
                ])->save();
            } elseif ($locale === 'vi' && $sanitizedContent !== null) {
                $policy->forceFill([
                    'title_vi' => $translation->title,
                    'summary_vi' => $translation->summary,
                    'content_vi' => $sanitizedContent,
                    'updated_by' => $actor->id,
                ])->save();
            }

            if ($sanitizedContent !== null) {
                $note = isset($data['change_note']) && is_string($data['change_note']) && trim($data['change_note']) !== ''
                    ? mb_substr(trim($data['change_note']), 0, 255)
                    : null;
                $this->recordRevision($policy, $locale, $translation->title, $translation->summary, $sanitizedContent, $actor, $note, $policy->effective_at);
            }

            $this->footerMenuService->flushCache();
            DB::afterCommit(static function () use ($policy): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Policy->value, $policy->id);
            });

            $this->auditLogger->log('policy.translation_saved', $policy, [
                'slug' => $policy->slug,
                'locale' => $locale,
                'status' => $status,
            ], $actor);

            return $translation;
        });
    }

    /**
     * Restore an earlier revision snapshot for a CMS page.
     */
    public function restoreRevision(PolicyPage $policy, PolicyPageRevision $revision, User $actor): PolicyPage
    {
        if ($revision->policy_page_id !== $policy->id) {
            abort(404);
        }

        return DB::transaction(function () use ($policy, $revision, $actor): PolicyPage {
            $locale = $revision->locale;
            $sanitized = $this->sanitizer->sanitize($revision->content);

            if ($locale === 'en') {
                $policy->forceFill([
                    'title_en' => $revision->title,
                    'summary_en' => $revision->summary,
                    'content_en' => $sanitized,
                    'updated_by' => $actor->id,
                ])->save();
            } elseif ($locale === 'vi') {
                $policy->forceFill([
                    'title_vi' => $revision->title,
                    'summary_vi' => $revision->summary,
                    'content_vi' => $sanitized,
                    'updated_by' => $actor->id,
                ])->save();
            }

            PolicyPageTranslation::query()->updateOrCreate(
                [
                    'policy_page_id' => $policy->id,
                    'locale' => $locale,
                ],
                [
                    'title' => $revision->title,
                    'summary' => $revision->summary,
                    'content' => $sanitized,
                    'status' => $policy->status === PolicyStatus::Published ? 'published' : 'draft',
                    'published_at' => $policy->status === PolicyStatus::Published ? now() : null,
                    'updated_by' => $actor->id,
                ],
            );

            $this->recordRevision(
                $policy,
                $locale,
                $revision->title,
                $revision->summary,
                $sanitized,
                $actor,
                __('Restored from version :version', ['version' => $revision->version_number]),
                $revision->effective_at ?? $policy->effective_at,
            );

            $this->footerMenuService->flushCache();
            DB::afterCommit(static function () use ($policy): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Policy->value, $policy->id);
            });

            $this->auditLogger->log('policy.revision_restored', $policy, [
                'slug' => $policy->slug,
                'locale' => $locale,
                'restored_version' => $revision->version_number,
            ], $actor);

            return $policy->fresh(['translations', 'revisions']) ?? $policy;
        });
    }

    /**
     * Device-only image upload for inline CMS content (zero external hotlinking).
     *
     * @return array{url: string, disk: string, path: string, width: int, height: int}
     */
    public function uploadCmsImage(UploadedFile $file, User $actor): array
    {
        $stored = $this->imageUploadService->storeImage($file, 'cms/content', 'cms', 'image', false);
        $url = $stored['disk'] === 'public'
            ? '/storage/'.ltrim($stored['path'], '/')
            : Storage::disk($stored['disk'])->url($stored['path']);

        $this->auditLogger->log('policy.image_uploaded', null, [
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'bytes' => $stored['size_bytes'],
        ], $actor);

        return [
            'url' => $url,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
        ];
    }

    /**
     * Update support email, contact info, and copyright line displayed in global footers.
     *
     * @param  array{contact_email?: string|null, support_contact_info?: string|null, footer_copyright_text?: string|null}  $data
     */
    public function updateFooterSettings(array $data, User $actor): void
    {
        $email = isset($data['contact_email']) ? trim((string) $data['contact_email']) : '';
        if ($email !== '' && $this->footerMenuService->sanitizeSupportEmail($email) === null) {
            throw ValidationException::withMessages([
                'contact_email' => __('Please enter a valid real support email address (placeholder domains such as example.com are not allowed).'),
            ]);
        }

        $this->systemSettingService->updateMany([
            'contact_email' => $email,
            'support_contact_info' => isset($data['support_contact_info']) ? trim((string) $data['support_contact_info']) : '',
            'footer_copyright_text' => isset($data['footer_copyright_text']) ? trim((string) $data['footer_copyright_text']) : '',
        ], $actor, $this->auditLogger);

        $this->footerMenuService->flushCache();
        $this->auditLogger->log('footer.settings_updated', null, [
            'has_contact_email' => $email !== '',
        ], $actor);
    }

    /**
     * Create a new footer link.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFooterLink(array $data, User $actor): FooterLink
    {
        return DB::transaction(function () use ($data, $actor): FooterLink {
            $placement = isset($data['placement']) && in_array((string) $data['placement'], ['main', 'more'], true)
                ? (string) $data['placement']
                : 'main';

            $link = FooterLink::query()->create([
                'policy_page_id' => $data['policy_page_id'] ?? null,
                'label_vi' => trim((string) $data['label_vi']),
                'label_en' => trim((string) $data['label_en']),
                'translations' => $this->normalizeLinkTranslations($data['translations'] ?? null),
                'route_name' => $data['route_name'] ?? null,
                'url' => $data['url'] ?? null,
                'footer_group' => FooterGroup::from((string) $data['footer_group']),
                'placement' => $placement,
                'sort_order' => (int) $data['sort_order'],
                'is_visible' => (bool) $data['is_visible'],
                'open_in_new_tab' => (bool) ($data['open_in_new_tab'] ?? false),
                'updated_by' => $actor->id,
            ]);

            $this->footerMenuService->flushCache();
            $this->auditLogger->log('footer_link.created', $link, ['label_en' => $link->label_en], $actor);

            return $link;
        });
    }

    /**
     * Update an existing footer link (order, placement, visibility, labels, translations, or destination).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFooterLink(FooterLink $link, array $data, User $actor): FooterLink
    {
        return DB::transaction(function () use ($link, $data, $actor): FooterLink {
            $placement = isset($data['placement']) && in_array((string) $data['placement'], ['main', 'more'], true)
                ? (string) $data['placement']
                : ($link->placement ?: 'main');

            $payload = [
                'label_vi' => trim((string) $data['label_vi']),
                'label_en' => trim((string) $data['label_en']),
                'url' => array_key_exists('url', $data) ? $data['url'] : $link->url,
                'footer_group' => FooterGroup::from((string) $data['footer_group']),
                'placement' => $placement,
                'sort_order' => (int) $data['sort_order'],
                'is_visible' => (bool) $data['is_visible'],
                'open_in_new_tab' => (bool) ($data['open_in_new_tab'] ?? $link->open_in_new_tab),
                'updated_by' => $actor->id,
            ];

            if (array_key_exists('translations', $data)) {
                $payload['translations'] = $this->normalizeLinkTranslations($data['translations']);
            }

            $link->fill($payload);
            $link->save();

            $this->footerMenuService->flushCache();
            $this->auditLogger->log('footer_link.updated', $link, [
                'is_visible' => $link->is_visible,
                'placement' => $link->placement,
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

    private function recordRevision(
        PolicyPage $policy,
        string $locale,
        string $title,
        ?string $summary,
        ?string $content,
        User $actor,
        ?string $changeNote,
        ?Carbon $effectiveAt,
    ): PolicyPageRevision {
        $nextVersion = (int) PolicyPageRevision::query()
            ->where('policy_page_id', $policy->id)
            ->where('locale', $locale)
            ->max('version_number') + 1;

        return PolicyPageRevision::query()->create([
            'policy_page_id' => $policy->id,
            'locale' => $locale,
            'version_number' => $nextVersion,
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
            'change_note' => $changeNote,
            'effective_at' => $effectiveAt,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function parseDateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeLinkTranslations(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $supported = LanguageService::allCodes();
        $clean = [];

        foreach ($raw as $code => $label) {
            if (! is_string($code) || ! in_array($code, $supported, true) || ! is_string($label)) {
                continue;
            }
            $trimmed = mb_substr(trim($label), 0, 120);
            if ($trimmed !== '') {
                $clean[$code] = $trimmed;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
