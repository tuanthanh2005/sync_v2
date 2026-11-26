<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $referral_code_id
 * @property string $visitor_hash
 * @property string|null $ip_hash
 * @property string|null $landing_path
 * @property string|null $referer_host
 * @property string $locale
 * @property bool $is_bot
 * @property CarbonInterface $visited_at
 * @property CarbonInterface|null $expires_at
 */
#[Fillable([
    'referral_code_id', 'visitor_hash', 'ip_hash', 'landing_path',
    'referer_host', 'locale', 'is_bot', 'visited_at', 'expires_at',
])]
class ReferralVisit extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referral_code_id' => 'integer',
            'is_bot' => 'boolean',
            'visited_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }
}
