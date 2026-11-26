<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $color
 * @property string $criteria_type
 * @property array<string, mixed>|null $criteria_config
 * @property int $reward_premium_days
 * @property string|null $grants_badge_type
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'slug', 'name', 'description', 'icon', 'color', 'criteria_type',
    'criteria_config', 'reward_premium_days', 'grants_badge_type', 'is_active', 'sort_order',
])]
class Achievement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria_config' => 'array',
            'reward_premium_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
