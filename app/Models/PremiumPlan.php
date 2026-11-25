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
 * @property int $duration_days
 * @property int $price_usd_cents
 * @property int $price_vnd
 * @property int|null $promo_price_usd_cents
 * @property int|null $promo_price_vnd
 * @property list<string>|null $benefits
 * @property string $ad_reduction_mode
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_featured
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'duration_days',
    'price_usd_cents',
    'price_vnd',
    'promo_price_usd_cents',
    'promo_price_vnd',
    'benefits',
    'ad_reduction_mode',
    'sort_order',
    'is_active',
    'is_featured',
])]
class PremiumPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'price_usd_cents' => 'integer',
            'price_vnd' => 'integer',
            'promo_price_usd_cents' => 'integer',
            'promo_price_vnd' => 'integer',
            'benefits' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Resolve effective backend integer price in smallest currency unit (VND or USD cents).
     */
    public function effectivePrice(string $currency): int
    {
        if (strtoupper($currency) === 'USD') {
            if ($this->promo_price_usd_cents !== null && $this->promo_price_usd_cents > 0 && $this->promo_price_usd_cents < $this->price_usd_cents) {
                return $this->promo_price_usd_cents;
            }

            return $this->price_usd_cents;
        }

        if ($this->promo_price_vnd !== null && $this->promo_price_vnd > 0 && $this->promo_price_vnd < $this->price_vnd) {
            return $this->promo_price_vnd;
        }

        return $this->price_vnd;
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
