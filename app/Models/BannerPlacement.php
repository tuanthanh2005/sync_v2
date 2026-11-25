<?php

namespace App\Models;

use App\Enums\BannerDeliveryMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $platform
 * @property int $recommended_width
 * @property int $recommended_height
 * @property string $aspect_ratio
 * @property BannerDeliveryMode $delivery_mode
 * @property int $max_active_banners
 * @property int $price_7d_vnd
 * @property int $price_15d_vnd
 * @property int $price_30d_vnd
 * @property int $price_90d_vnd
 * @property int $price_7d_usd_cents
 * @property int $price_15d_usd_cents
 * @property int $price_30d_usd_cents
 * @property int $price_90d_usd_cents
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'description',
    'platform',
    'recommended_width',
    'recommended_height',
    'aspect_ratio',
    'delivery_mode',
    'max_active_banners',
    'price_7d_vnd',
    'price_15d_vnd',
    'price_30d_vnd',
    'price_90d_vnd',
    'price_7d_usd_cents',
    'price_15d_usd_cents',
    'price_30d_usd_cents',
    'price_90d_usd_cents',
    'is_active',
    'sort_order',
])]
class BannerPlacement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommended_width' => 'integer',
            'recommended_height' => 'integer',
            'delivery_mode' => BannerDeliveryMode::class,
            'max_active_banners' => 'integer',
            'price_7d_vnd' => 'integer',
            'price_15d_vnd' => 'integer',
            'price_30d_vnd' => 'integer',
            'price_90d_vnd' => 'integer',
            'price_7d_usd_cents' => 'integer',
            'price_15d_usd_cents' => 'integer',
            'price_30d_usd_cents' => 'integer',
            'price_90d_usd_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Compute integer price for a given rental duration in smallest unit (VND or USD cents).
     */
    public function priceForDuration(int $days, string $currency): int
    {
        $isUsd = strtoupper($currency) === 'USD';

        return match ($days) {
            7 => $isUsd ? $this->price_7d_usd_cents : $this->price_7d_vnd,
            15 => $isUsd ? $this->price_15d_usd_cents : $this->price_15d_vnd,
            30 => $isUsd ? $this->price_30d_usd_cents : $this->price_30d_vnd,
            90 => $isUsd ? $this->price_90d_usd_cents : $this->price_90d_vnd,
            default => (int) ceil(($isUsd ? $this->price_30d_usd_cents : $this->price_30d_vnd) * $days / 30),
        };
    }

    /**
     * @return HasMany<BannerCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(BannerCampaign::class);
    }
}
