<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $platform
 * @property bool $allow_external_web_checkout
 * @property bool $allow_premium_purchase
 * @property bool $allow_banner_display
 * @property bool $allow_boost_purchase
 * @property bool $require_native_iap_for_digital
 * @property list<string> $allowed_payment_methods
 * @property string|null $policy_note
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'platform',
    'allow_external_web_checkout',
    'allow_premium_purchase',
    'allow_banner_display',
    'allow_boost_purchase',
    'require_native_iap_for_digital',
    'allowed_payment_methods',
    'policy_note',
])]
class PlatformComplianceRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_external_web_checkout' => 'boolean',
            'allow_premium_purchase' => 'boolean',
            'allow_banner_display' => 'boolean',
            'allow_boost_purchase' => 'boolean',
            'require_native_iap_for_digital' => 'boolean',
            'allowed_payment_methods' => 'array',
        ];
    }
}
