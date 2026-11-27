<?php

namespace App\Models;

use App\Enums\FooterGroup;
use Database\Factories\FooterLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * @property int $id
 * @property int|null $policy_page_id
 * @property string $label_vi
 * @property string $label_en
 * @property array<string, string>|null $translations
 * @property string|null $route_name
 * @property string|null $url
 * @property FooterGroup $footer_group
 * @property string $placement
 * @property int $sort_order
 * @property bool $is_visible
 * @property bool $open_in_new_tab
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PolicyPage|null $policyPage
 */
#[Fillable([
    'policy_page_id',
    'label_vi',
    'label_en',
    'translations',
    'route_name',
    'url',
    'footer_group',
    'placement',
    'sort_order',
    'is_visible',
    'open_in_new_tab',
    'updated_by',
])]
class FooterLink extends Model
{
    /** @use HasFactory<FooterLinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'footer_group' => FooterGroup::class,
            'translations' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'open_in_new_tab' => 'boolean',
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

    public function labelForLocale(string $locale): string
    {
        if (is_array($this->translations) && isset($this->translations[$locale]) && trim((string) $this->translations[$locale]) !== '') {
            return trim((string) $this->translations[$locale]);
        }

        if ($locale === 'vi') {
            return $this->label_vi ?: $this->label_en;
        }

        return (string) Lang::get($this->label_en, [], $locale);
    }
}
