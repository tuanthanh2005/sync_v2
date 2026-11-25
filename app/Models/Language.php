<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $native_name
 * @property string $english_name
 * @property string $flag_code
 * @property bool $is_rtl
 * @property bool $is_enabled
 * @property bool $is_default
 * @property int $sort_order
 * @property int $completion_percent
 * @property int $missing_keys_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code',
    'native_name',
    'english_name',
    'flag_code',
    'is_rtl',
    'is_enabled',
    'is_default',
    'sort_order',
    'completion_percent',
    'missing_keys_count',
])]
class Language extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_rtl' => 'boolean',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'completion_percent' => 'integer',
            'missing_keys_count' => 'integer',
        ];
    }
}
