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
 * @property int $version_number
 * @property string $title
 * @property string|null $summary
 * @property string|null $content
 * @property string|null $change_note
 * @property Carbon|null $effective_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'policy_page_id',
    'locale',
    'version_number',
    'title',
    'summary',
    'content',
    'change_note',
    'effective_at',
    'created_by',
    'created_at',
])]
class PolicyPageRevision extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
