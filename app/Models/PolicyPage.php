<?php

namespace App\Models;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use Database\Factories\PolicyPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $slug
 * @property string $page_type
 * @property string $title_vi
 * @property string $title_en
 * @property string|null $summary_vi
 * @property string|null $summary_en
 * @property string|null $content_vi
 * @property string|null $content_en
 * @property string|null $seo_title
 * @property string|null $meta_description
 * @property string|null $canonical_url
 * @property string|null $og_image_disk
 * @property string|null $og_image_path
 * @property bool $index_eligible
 * @property PolicyStatus $status
 * @property bool $is_visible
 * @property FooterGroup $footer_group
 * @property int $sort_order
 * @property Carbon|null $published_at
 * @property Carbon|null $effective_at
 * @property Carbon|null $archived_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PolicyPageTranslation> $translations
 * @property-read Collection<int, PolicyPageRevision> $revisions
 */
#[Fillable([
    'slug',
    'page_type',
    'title_vi',
    'title_en',
    'summary_vi',
    'summary_en',
    'content_vi',
    'content_en',
    'seo_title',
    'meta_description',
    'canonical_url',
    'og_image_disk',
    'og_image_path',
    'index_eligible',
    'status',
    'is_visible',
    'footer_group',
    'sort_order',
    'published_at',
    'effective_at',
    'archived_at',
    'updated_by',
])]
class PolicyPage extends Model
{
    /** @use HasFactory<PolicyPageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PolicyStatus::class,
            'footer_group' => FooterGroup::class,
            'is_visible' => 'boolean',
            'index_eligible' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'effective_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FooterLink, $this>
     */
    public function footerLinks(): HasMany
    {
        return $this->hasMany(FooterLink::class);
    }

    /**
     * @return HasMany<PolicyPageTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(PolicyPageTranslation::class);
    }

    /**
     * @return HasMany<PolicyPageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PolicyPageRevision::class)->orderByDesc('version_number')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPubliclyAccessible(): bool
    {
        return $this->status === PolicyStatus::Published && $this->is_visible && $this->archived_at === null;
    }

    public function ogImageUrl(): ?string
    {
        if (! is_string($this->og_image_path) || trim($this->og_image_path) === '') {
            return null;
        }

        $disk = is_string($this->og_image_disk) && trim($this->og_image_disk) !== ''
            ? $this->og_image_disk
            : (string) config('syncviet.media_disk', 'public');

        if ($disk === 'public') {
            return '/storage/'.ltrim($this->og_image_path, '/');
        }

        return Storage::disk($disk)->url($this->og_image_path);
    }

    public function publishedTranslationForLocale(string $locale): ?PolicyPageTranslation
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $match = $translations->first(
            static fn (PolicyPageTranslation $translation): bool => $translation->locale === $locale && $translation->isPublished(),
        );

        return $match instanceof PolicyPageTranslation ? $match : null;
    }

    /**
     * True only when this page has genuine published copy for the requested locale
     * (prevents creating thin untranslated duplicate locale URLs).
     */
    public function hasGenuinePublishedTranslation(string $locale): bool
    {
        if (! $this->isPubliclyAccessible()) {
            return false;
        }

        if ($this->publishedTranslationForLocale($locale) !== null) {
            return true;
        }

        if ($locale === 'en') {
            return is_string($this->content_en) && trim(strip_tags($this->content_en)) !== '';
        }

        if ($locale === 'vi') {
            return is_string($this->content_vi) && trim(strip_tags($this->content_vi)) !== '';
        }

        return false;
    }

    /**
     * List of locale codes that have real published content for this CMS page.
     *
     * @return list<string>
     */
    public function availablePublishedLocales(): array
    {
        if (! $this->isPubliclyAccessible()) {
            return [];
        }

        $locales = [];

        if (is_string($this->content_en) && trim(strip_tags($this->content_en)) !== '') {
            $locales[] = 'en';
        }

        if (is_string($this->content_vi) && trim(strip_tags($this->content_vi)) !== '') {
            $locales[] = 'vi';
        }

        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        foreach ($translations as $translation) {
            if ($translation->isPublished()) {
                $locales[] = $translation->locale;
            }
        }

        return array_values(array_unique($locales));
    }

    public function titleForLocale(string $locale): string
    {
        $translation = $this->publishedTranslationForLocale($locale);
        if ($translation !== null && trim($translation->title) !== '') {
            return $translation->title;
        }

        if ($locale === 'vi') {
            return $this->title_vi ?: $this->title_en;
        }

        return (string) Lang::get($this->title_en, [], $locale);
    }

    public function summaryForLocale(string $locale): ?string
    {
        $translation = $this->publishedTranslationForLocale($locale);
        if ($translation !== null && is_string($translation->summary) && trim($translation->summary) !== '') {
            return $translation->summary;
        }

        if ($locale === 'vi') {
            return $this->summary_vi ?: $this->summary_en;
        }

        return $this->summary_en ?: $this->summary_vi;
    }

    public function contentForLocale(string $locale): ?string
    {
        $translation = $this->publishedTranslationForLocale($locale);
        if ($translation !== null && is_string($translation->content) && trim($translation->content) !== '') {
            return $translation->content;
        }

        if ($locale === 'vi') {
            return $this->content_vi ?: $this->content_en;
        }

        return $this->content_en ?: $this->content_vi;
    }

    public function seoTitleForLocale(string $locale): ?string
    {
        $translation = $this->publishedTranslationForLocale($locale);
        if ($translation !== null && is_string($translation->seo_title) && trim($translation->seo_title) !== '') {
            return trim($translation->seo_title);
        }

        return is_string($this->seo_title) && trim($this->seo_title) !== ''
            ? trim($this->seo_title)
            : null;
    }

    public function metaDescriptionForLocale(string $locale): ?string
    {
        $translation = $this->publishedTranslationForLocale($locale);
        if ($translation !== null && is_string($translation->meta_description) && trim($translation->meta_description) !== '') {
            return trim($translation->meta_description);
        }

        return is_string($this->meta_description) && trim($this->meta_description) !== ''
            ? trim($this->meta_description)
            : null;
    }
}
