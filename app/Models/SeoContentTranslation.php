<?php

namespace App\Models;

use App\Enums\SeoTranslationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $original_language
 * @property string $target_locale
 * @property string|null $translated_title
 * @property string $translated_body
 * @property string|null $translated_description
 * @property string $localized_slug
 * @property string $provider
 * @property string $source_content_hash
 * @property int $version
 * @property SeoTranslationStatus $review_status
 * @property SeoTranslationStatus $publication_status
 * @property bool $is_ai_assisted
 * @property bool $is_stale
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'entity_type', 'entity_id', 'original_language', 'target_locale',
    'translated_title', 'translated_body', 'translated_description',
    'localized_slug', 'provider', 'source_content_hash', 'version',
    'review_status', 'publication_status', 'is_ai_assisted', 'is_stale',
    'created_by', 'reviewed_by', 'reviewed_at', 'published_at',
])]
class SeoContentTranslation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'version' => 'integer',
            'review_status' => SeoTranslationStatus::class,
            'publication_status' => SeoTranslationStatus::class,
            'is_ai_assisted' => 'boolean',
            'is_stale' => 'boolean',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<SeoContentTranslation>  $query
     * @return Builder<SeoContentTranslation>
     */
    public function scopePublishedApproved(Builder $query): Builder
    {
        return $query
            ->where('publication_status', SeoTranslationStatus::Published->value)
            ->where('review_status', SeoTranslationStatus::Approved->value)
            ->whereNotNull('published_at');
    }

    public function isPublishedAndApproved(): bool
    {
        return $this->publication_status === SeoTranslationStatus::Published
            && $this->review_status === SeoTranslationStatus::Approved
            && $this->published_at !== null;
    }
}
