<?php

namespace App\Services\Monetization;

use App\Models\PlatformComplianceRule;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlatformCapabilityService
{
    /**
     * @var list<string>
     */
    public const PLATFORMS = ['web', 'pwa', 'android_play', 'ios_appstore'];

    public function resolvePlatform(Request $request): string
    {
        $header = strtolower(trim((string) ($request->header('X-SyncViet-Platform') ?? $request->header('X-SyncViet-Client') ?? '')));
        if (in_array($header, self::PLATFORMS, true)) {
            return $header;
        }

        $query = strtolower(trim((string) $request->query('platform', '')));
        if (in_array($query, self::PLATFORMS, true)) {
            return $query;
        }

        return 'web';
    }

    public function ruleForPlatform(string $platform): PlatformComplianceRule
    {
        $clean = in_array($platform, self::PLATFORMS, true) ? $platform : 'web';

        if (Schema::hasTable('platform_compliance_rules')) {
            MonetizationSeeder::seedDefaultsIfEmpty();

            /** @var PlatformComplianceRule|null $rule */
            $rule = PlatformComplianceRule::query()->where('platform', $clean)->first();
            if ($rule !== null) {
                return $rule;
            }
        }

        $isNativeStore = in_array($clean, ['android_play', 'ios_appstore'], true);

        return new PlatformComplianceRule([
            'platform' => $clean,
            'allow_external_web_checkout' => ! $isNativeStore,
            'allow_premium_purchase' => ! $isNativeStore,
            'allow_banner_display' => true,
            'allow_boost_purchase' => ! $isNativeStore,
            'require_native_iap_for_digital' => $isNativeStore,
            'allowed_payment_methods' => $isNativeStore
                ? [$clean === 'android_play' ? 'google_play_billing' : 'apple_storekit']
                : ['vietqr', 'bank_transfer', 'paypal', 'sandbox'],
            'policy_note' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilitiesForRequest(Request $request): array
    {
        $platform = $this->resolvePlatform($request);
        $rule = $this->ruleForPlatform($platform);

        return [
            'platform' => $rule->platform,
            'allow_external_web_checkout' => $rule->allow_external_web_checkout,
            'allow_premium_purchase' => $rule->allow_premium_purchase,
            'allow_banner_display' => $rule->allow_banner_display,
            'allow_boost_purchase' => $rule->allow_boost_purchase,
            'require_native_iap_for_digital' => $rule->require_native_iap_for_digital,
            'allowed_payment_methods' => $rule->allowed_payment_methods,
            'policy_note' => $rule->policy_note,
        ];
    }

    public function assertPurchaseAllowed(string $platform, string $serviceType, string $provider): void
    {
        $rule = $this->ruleForPlatform($platform);

        if ($serviceType === 'premium' && ! $rule->allow_premium_purchase) {
            throw ValidationException::withMessages([
                'platform' => [__('Premium checkout is currently restricted on this platform due to app store billing compliance.')],
            ]);
        }

        if ($serviceType === 'boost' && ! $rule->allow_boost_purchase) {
            throw ValidationException::withMessages([
                'platform' => [__('Boost Post checkout is currently restricted on this platform due to app store billing compliance.')],
            ]);
        }

        if (in_array($platform, ['android_play', 'ios_appstore'], true) && ! $rule->allow_external_web_checkout) {
            throw ValidationException::withMessages([
                'platform' => [__('External web payment checkout is disabled on native app store builds.')],
            ]);
        }

        if (! in_array($provider, $rule->allowed_payment_methods, true)) {
            throw ValidationException::withMessages([
                'provider' => [__('Selected payment method is not permitted on this platform.')],
            ]);
        }
    }
}
