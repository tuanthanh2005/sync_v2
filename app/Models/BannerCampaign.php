<?php

namespace App\Models;

use App\Enums\BannerCampaignStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property int $banner_placement_id
 * @property int|null $payment_id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $logo_disk
 * @property string|null $logo_path
 * @property string|null $desktop_image_disk
 * @property string|null $desktop_image_path
 * @property string|null $mobile_image_disk
 * @property string|null $mobile_image_path
 * @property string|null $headline
 * @property string|null $cta_label
 * @property string $target_url
 * @property string $alt_text
 * @property int $duration_days
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $currency
 * @property int $price_amount
 * @property string $payment_status
 * @property BannerCampaignStatus $status
 * @property int $impressions_count
 * @property int $clicks_count
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $emergency_paused_at
 * @property int|null $created_by_admin_id
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'business_name',
    'headline',
    'cta_label',
    'target_url',
    'alt_text',
    'duration_days',
    'starts_at',
    'ends_at',
])]
class BannerCampaign extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price_amount' => 'integer',
            'status' => BannerCampaignStatus::class,
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
            'reviewed_at' => 'datetime',
            'emergency_paused_at' => 'datetime',
        ];
    }

    public function desktopImageUrl(): ?string
    {
        return $this->resolveStorageUrl($this->desktop_image_disk, $this->desktop_image_path);
    }

    public function mobileImageUrl(): ?string
    {
        return $this->resolveStorageUrl($this->mobile_image_disk, $this->mobile_image_path)
            ?? $this->desktopImageUrl();
    }

    public function logoUrl(): ?string
    {
        return $this->resolveStorageUrl($this->logo_disk, $this->logo_path);
    }

    public function isDeliverableNow(): bool
    {
        return $this->status === BannerCampaignStatus::Active
            && $this->emergency_paused_at === null
            && $this->starts_at->isPast()
            && $this->ends_at->isFuture();
    }

    private function resolveStorageUrl(?string $disk, ?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk($disk ?: (string) config('syncviet.media_disk', 'public'))->url($path);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<BannerPlacement, $this>
     */
    public function placement(): BelongsTo
    {
        return $this->belongsTo(BannerPlacement::class, 'banner_placement_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<BannerEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(BannerEvent::class);
    }
}
