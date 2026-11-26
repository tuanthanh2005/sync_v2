<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $date
 * @property int $total_human_users
 * @property int $new_human_registrations
 * @property int $verified_human_users
 * @property int $activated_users
 * @property int $dau_human
 * @property int $wau_human
 * @property int $mau_human
 * @property int $referral_registrations
 * @property int $qualified_referrals
 * @property int $organic_registrations
 * @property float $d1_retention_rate
 * @property float $d7_retention_rate
 * @property float $d30_retention_rate
 * @property int $premium_rewards_granted_days
 * @property int $promotional_premium_users
 * @property int $paid_premium_users
 * @property array<string, mixed>|null $metrics_payload
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'date', 'total_human_users', 'new_human_registrations', 'verified_human_users',
    'activated_users', 'dau_human', 'wau_human', 'mau_human',
    'referral_registrations', 'qualified_referrals', 'organic_registrations',
    'd1_retention_rate', 'd7_retention_rate', 'd30_retention_rate',
    'premium_rewards_granted_days', 'promotional_premium_users', 'paid_premium_users',
    'metrics_payload',
])]
class GrowthAnalytic extends Model
{
    protected $table = 'growth_analytics';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_human_users' => 'integer',
            'new_human_registrations' => 'integer',
            'verified_human_users' => 'integer',
            'activated_users' => 'integer',
            'dau_human' => 'integer',
            'wau_human' => 'integer',
            'mau_human' => 'integer',
            'referral_registrations' => 'integer',
            'qualified_referrals' => 'integer',
            'organic_registrations' => 'integer',
            'd1_retention_rate' => 'float',
            'd7_retention_rate' => 'float',
            'd30_retention_rate' => 'float',
            'premium_rewards_granted_days' => 'integer',
            'promotional_premium_users' => 'integer',
            'paid_premium_users' => 'integer',
            'metrics_payload' => 'array',
        ];
    }
}
