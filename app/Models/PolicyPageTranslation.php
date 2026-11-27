<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $policy_page_id
 * @property string $locale
 * @property string $title
 * @property string|null $summary
 * @property string|null $content
 * @property string|null $seo_title
 * @property string|null $meta_description
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'policy_page_id',
    'locale',
    'title',
    'summary',
    'content',
    'seo_title',
    'meta_description',
    'status',
    'published_at',
    'updated_by',
])]
class PolicyPageTranslation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PolicyPage, $this>
     */
    public function policyPage(): BelongsTo
    {
        return $this->belongsTo(PolicyPage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && is_string($this->content) && trim(strip_tags($this->content)) !== '';
    }
}
