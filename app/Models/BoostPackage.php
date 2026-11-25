<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $duration_hours
 * @property int $price_usd_cents
 * @property int $price_vnd
 * @property bool $is_featured_slot
 * @property int $priority_weight
 * @property list<string>|null $benefits
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'duration_hours',
    'price_usd_cents',
    'price_vnd',
    'is_featured_slot',
    'priority_weight',
    'benefits',
    'is_active',
    'sort_order',
])]
class BoostPackage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_hours' => 'integer',
            'price_usd_cents' => 'integer',
            'price_vnd' => 'integer',
            'is_featured_slot' => 'boolean',
            'priority_weight' => 'integer',
            'benefits' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function priceForCurrency(string $currency): int
    {
        return strtoupper($currency) === 'USD' ? $this->price_usd_cents : $this->price_vnd;
    }

    /**
     * @return HasMany<AdCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }
}
