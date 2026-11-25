<?php

namespace App\Models;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use Database\Factories\PolicyPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title_vi
 * @property string $title_en
 * @property string|null $summary_vi
 * @property string|null $summary_en
 * @property string|null $content_vi
 * @property string|null $content_en
 * @property PolicyStatus $status
 * @property bool $is_visible
 * @property FooterGroup $footer_group
 * @property int $sort_order
 * @property Carbon|null $published_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'slug',
    'title_vi',
    'title_en',
    'summary_vi',
    'summary_en',
    'content_vi',
    'content_en',
    'status',
    'is_visible',
    'footer_group',
    'sort_order',
    'published_at',
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
            'sort_order' => 'integer',
            'published_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPubliclyAccessible(): bool
    {
        return $this->status === PolicyStatus::Published && $this->is_visible;
    }

    public function titleForLocale(string $locale): string
    {
        return $locale === 'en' ? $this->title_en : $this->title_vi;
    }

    public function summaryForLocale(string $locale): ?string
    {
        return $locale === 'en' ? $this->summary_en : $this->summary_vi;
    }

    public function contentForLocale(string $locale): ?string
    {
        return $locale === 'en' ? $this->content_en : $this->content_vi;
    }
}
