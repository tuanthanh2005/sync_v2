<?php

namespace Database\Seeders;

use App\Enums\BannerDeliveryMode;
use App\Models\BannerPlacement;
use App\Models\BoostPackage;
use App\Models\PaymentMethod;
use App\Models\PlatformComplianceRule;
use App\Models\PremiumPlan;
use Illuminate\Database\Seeder;

class MonetizationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPremiumPlans();
        $this->seedBannerPlacements();
        $this->seedBoostPackages();
        $this->seedPaymentMethods();
        $this->seedPlatformComplianceRules();
    }

    public static function seedDefaultsIfEmpty(): void
    {
        $seeder = new self;

        if (! PremiumPlan::query()->exists()) {
            $seeder->seedPremiumPlans();
        }

        if (! BannerPlacement::query()->exists()) {
            $seeder->seedBannerPlacements();
        }

        if (! BoostPackage::query()->exists()) {
            $seeder->seedBoostPackages();
        }

        if (! PaymentMethod::query()->exists()) {
            $seeder->seedPaymentMethods();
        }

        if (! PlatformComplianceRule::query()->exists()) {
            $seeder->seedPlatformComplianceRules();
        }
    }

    public function seedPremiumPlans(): void
    {
        $plans = [
            [
                'slug' => 'premium-1m',
                'name' => 'Premium 1 Month',
                'description' => 'Monthly SyncViet Premium membership with blue verified member badge and reduced feed ads.',
                'duration_days' => 30,
                'price_usd_cents' => 299,
                'price_vnd' => 79_000,
                'promo_price_usd_cents' => null,
                'promo_price_vnd' => null,
                'benefits' => [
                    'blue_badge',
                    'post_analytics',
                    'reduced_ads',
                    'priority_support',
                    'profile_highlights',
                ],
                'ad_reduction_mode' => 'reduced',
                'sort_order' => 10,
                'is_active' => true,
                'is_featured' => false,
            ],
            [
                'slug' => 'premium-3m',
                'name' => 'Premium 3 Months',
                'description' => 'Quarterly SyncViet Premium membership with savings over monthly billing.',
                'duration_days' => 90,
                'price_usd_cents' => 799,
                'price_vnd' => 199_000,
                'promo_price_usd_cents' => null,
                'promo_price_vnd' => null,
                'benefits' => [
                    'blue_badge',
                    'post_analytics',
                    'reduced_ads',
                    'priority_support',
                    'profile_highlights',
                ],
                'ad_reduction_mode' => 'reduced',
                'sort_order' => 20,
                'is_active' => true,
                'is_featured' => false,
            ],
            [
                'slug' => 'premium-6m',
                'name' => 'Premium 6 Months',
                'description' => 'Semi-annual SyncViet Premium membership for active creators and community builders.',
                'duration_days' => 180,
                'price_usd_cents' => 1499,
                'price_vnd' => 389_000,
                'promo_price_usd_cents' => null,
                'promo_price_vnd' => null,
                'benefits' => [
                    'blue_badge',
                    'post_analytics',
                    'reduced_ads',
                    'priority_support',
                    'profile_highlights',
                ],
                'ad_reduction_mode' => 'reduced',
                'sort_order' => 30,
                'is_active' => true,
                'is_featured' => true,
            ],
            [
                'slug' => 'premium-12m',
                'name' => 'Premium 12 Months',
                'description' => 'Best value annual SyncViet Premium membership with ad-free browsing experience.',
                'duration_days' => 365,
                'price_usd_cents' => 2499,
                'price_vnd' => 649_000,
                'promo_price_usd_cents' => null,
                'promo_price_vnd' => null,
                'benefits' => [
                    'blue_badge',
                    'post_analytics',
                    'ad_free',
                    'priority_support',
                    'profile_highlights',
                ],
                'ad_reduction_mode' => 'ad_free',
                'sort_order' => 40,
                'is_active' => true,
                'is_featured' => false,
            ],
        ];

        foreach ($plans as $plan) {
            PremiumPlan::query()->firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }

    public function seedBannerPlacements(): void
    {
        $placements = [
            [
                'code' => 'desktop_feed_top',
                'name' => 'Desktop Feed Top Banner',
                'description' => 'High-visibility leaderboard above the main News Feed on desktop screens.',
                'platform' => 'desktop',
                'recommended_width' => 728,
                'recommended_height' => 90,
                'aspect_ratio' => '728x90',
                'delivery_mode' => BannerDeliveryMode::Exclusive->value,
                'max_active_banners' => 1,
                'price_7d_vnd' => 490_000,
                'price_15d_vnd' => 950_000,
                'price_30d_vnd' => 1_790_000,
                'price_90d_vnd' => 4_890_000,
                'price_7d_usd_cents' => 1999,
                'price_15d_usd_cents' => 3799,
                'price_30d_usd_cents' => 6999,
                'price_90d_usd_cents' => 18999,
                'sort_order' => 10,
            ],
            [
                'code' => 'desktop_sidebar_right',
                'name' => 'Desktop Right Sidebar Banner',
                'description' => 'Standard 300x250 rectangle card in the right sidebar.',
                'platform' => 'desktop',
                'recommended_width' => 300,
                'recommended_height' => 250,
                'aspect_ratio' => '300x250',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 3,
                'price_7d_vnd' => 350_000,
                'price_15d_vnd' => 690_000,
                'price_30d_vnd' => 1_290_000,
                'price_90d_vnd' => 3_490_000,
                'price_7d_usd_cents' => 1499,
                'price_15d_usd_cents' => 2799,
                'price_30d_usd_cents' => 4999,
                'price_90d_usd_cents' => 13999,
                'sort_order' => 20,
            ],
            [
                'code' => 'desktop_sidebar_premium',
                'name' => 'Desktop Right Sidebar Tall Premium',
                'description' => 'Exclusive tall 300x600 showcase card in the right sidebar.',
                'platform' => 'desktop',
                'recommended_width' => 300,
                'recommended_height' => 600,
                'aspect_ratio' => '300x600',
                'delivery_mode' => BannerDeliveryMode::Exclusive->value,
                'max_active_banners' => 1,
                'price_7d_vnd' => 690_000,
                'price_15d_vnd' => 1_350_000,
                'price_30d_vnd' => 2_490_000,
                'price_90d_vnd' => 6_790_000,
                'price_7d_usd_cents' => 2799,
                'price_15d_usd_cents' => 5299,
                'price_30d_usd_cents' => 9999,
                'price_90d_usd_cents' => 26999,
                'sort_order' => 30,
            ],
            [
                'code' => 'desktop_explore_top',
                'name' => 'Desktop Explore Top Banner',
                'description' => 'Featured placement at the top of the Explore & Search page.',
                'platform' => 'desktop',
                'recommended_width' => 728,
                'recommended_height' => 90,
                'aspect_ratio' => '728x90',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 2,
                'price_7d_vnd' => 390_000,
                'price_15d_vnd' => 750_000,
                'price_30d_vnd' => 1_390_000,
                'price_90d_vnd' => 3_790_000,
                'price_7d_usd_cents' => 1599,
                'price_15d_usd_cents' => 2999,
                'price_30d_usd_cents' => 5499,
                'price_90d_usd_cents' => 14999,
                'sort_order' => 40,
            ],
            [
                'code' => 'desktop_community_top',
                'name' => 'Desktop Community Top Banner',
                'description' => 'Placement above community discussion feeds.',
                'platform' => 'desktop',
                'recommended_width' => 728,
                'recommended_height' => 90,
                'aspect_ratio' => '728x90',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 2,
                'price_7d_vnd' => 290_000,
                'price_15d_vnd' => 550_000,
                'price_30d_vnd' => 990_000,
                'price_90d_vnd' => 2_690_000,
                'price_7d_usd_cents' => 1199,
                'price_15d_usd_cents' => 2199,
                'price_30d_usd_cents' => 3999,
                'price_90d_usd_cents' => 10999,
                'sort_order' => 50,
            ],
            [
                'code' => 'mobile_after_composer',
                'name' => 'Mobile Feed Below Composer',
                'description' => 'Compact responsive 320x100 banner below the mobile feed composer.',
                'platform' => 'mobile',
                'recommended_width' => 320,
                'recommended_height' => 100,
                'aspect_ratio' => '320x100',
                'delivery_mode' => BannerDeliveryMode::Exclusive->value,
                'max_active_banners' => 1,
                'price_7d_vnd' => 390_000,
                'price_15d_vnd' => 750_000,
                'price_30d_vnd' => 1_390_000,
                'price_90d_vnd' => 3_790_000,
                'price_7d_usd_cents' => 1599,
                'price_15d_usd_cents' => 2999,
                'price_30d_usd_cents' => 5499,
                'price_90d_usd_cents' => 14999,
                'sort_order' => 60,
            ],
            [
                'code' => 'mobile_in_feed',
                'name' => 'Mobile In-Feed Banner',
                'description' => 'Native-feeling 3:1 responsive banner inserted cleanly between feed posts on mobile.',
                'platform' => 'mobile',
                'recommended_width' => 960,
                'recommended_height' => 320,
                'aspect_ratio' => '3:1',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 3,
                'price_7d_vnd' => 320_000,
                'price_15d_vnd' => 620_000,
                'price_30d_vnd' => 1_150_000,
                'price_90d_vnd' => 3_090_000,
                'price_7d_usd_cents' => 1299,
                'price_15d_usd_cents' => 2499,
                'price_30d_usd_cents' => 4599,
                'price_90d_usd_cents' => 12499,
                'sort_order' => 70,
            ],
            [
                'code' => 'mobile_explore',
                'name' => 'Mobile Explore Banner',
                'description' => 'Mobile banner displayed on the Explore & Search screen.',
                'platform' => 'mobile',
                'recommended_width' => 320,
                'recommended_height' => 100,
                'aspect_ratio' => '320x100',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 2,
                'price_7d_vnd' => 250_000,
                'price_15d_vnd' => 490_000,
                'price_30d_vnd' => 890_000,
                'price_90d_vnd' => 2_390_000,
                'price_7d_usd_cents' => 999,
                'price_15d_usd_cents' => 1999,
                'price_30d_usd_cents' => 3599,
                'price_90d_usd_cents' => 9599,
                'sort_order' => 80,
            ],
            [
                'code' => 'mobile_community',
                'name' => 'Mobile Community Banner',
                'description' => 'Mobile banner displayed inside community pages.',
                'platform' => 'mobile',
                'recommended_width' => 320,
                'recommended_height' => 100,
                'aspect_ratio' => '320x100',
                'delivery_mode' => BannerDeliveryMode::Rotation->value,
                'max_active_banners' => 2,
                'price_7d_vnd' => 220_000,
                'price_15d_vnd' => 420_000,
                'price_30d_vnd' => 790_000,
                'price_90d_vnd' => 2_090_000,
                'price_7d_usd_cents' => 899,
                'price_15d_usd_cents' => 1699,
                'price_30d_usd_cents' => 3199,
                'price_90d_usd_cents' => 8499,
                'sort_order' => 90,
            ],
        ];

        foreach ($placements as $placement) {
            BannerPlacement::query()->firstOrCreate(['code' => $placement['code']], $placement);
        }
    }

    public function seedBoostPackages(): void
    {
        $packages = [
            [
                'slug' => 'boost-24h',
                'name' => 'Boost 24 Hours',
                'description' => 'Quick 24-hour visibility boost for announcements and discussions.',
                'duration_hours' => 24,
                'price_usd_cents' => 99,
                'price_vnd' => 25_000,
                'is_featured_slot' => false,
                'priority_weight' => 10,
                'benefits' => ['sponsored_feed_placement', 'live_impressions_clicks', 'approved_review'],
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'boost-3d',
                'name' => 'Boost 3 Days',
                'description' => 'Promote your post across the SyncViet feed for 3 consecutive days.',
                'duration_hours' => 72,
                'price_usd_cents' => 299,
                'price_vnd' => 75_000,
                'is_featured_slot' => false,
                'priority_weight' => 20,
                'benefits' => ['sponsored_feed_placement', 'live_impressions_clicks', 'approved_review'],
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'boost-7d',
                'name' => 'Boost 7 Days',
                'description' => 'Full week sponsored reach with higher delivery priority.',
                'duration_hours' => 168,
                'price_usd_cents' => 499,
                'price_vnd' => 125_000,
                'is_featured_slot' => false,
                'priority_weight' => 30,
                'benefits' => ['sponsored_feed_placement', 'higher_delivery_priority', 'live_impressions_clicks'],
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'slug' => 'boost-30d',
                'name' => 'Boost 30 Days',
                'description' => 'Sustained 30-day sponsored promotion for major launches and products.',
                'duration_hours' => 720,
                'price_usd_cents' => 1499,
                'price_vnd' => 379_000,
                'is_featured_slot' => false,
                'priority_weight' => 40,
                'benefits' => ['sponsored_feed_placement', 'higher_delivery_priority', 'live_impressions_clicks'],
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'slug' => 'featured-post-7d',
                'name' => 'Featured Post (7 Days)',
                'description' => 'Top-priority Featured Post sponsored placement across Feed and Explore.',
                'duration_hours' => 168,
                'price_usd_cents' => 999,
                'price_vnd' => 249_000,
                'is_featured_slot' => true,
                'priority_weight' => 60,
                'benefits' => ['featured_sponsored_slot', 'highest_delivery_priority', 'live_impressions_clicks'],
                'is_active' => true,
                'sort_order' => 50,
            ],
        ];

        foreach ($packages as $pkg) {
            BoostPackage::query()->firstOrCreate(['slug' => $pkg['slug']], $pkg);
        }
    }

    public function seedPaymentMethods(): void
    {
        $methods = [
            [
                'code' => 'vietqr',
                'name' => 'VietQR (Vietnam Instant Bank Transfer)',
                'category' => 'fiat',
                'supported_currencies' => ['VND'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => true,
                'is_live_ready' => false,
                'requires_legal_clearance' => false,
                'instructions' => [
                    'bank_name' => 'Configure in Admin Payment Settings',
                    'account_number' => '',
                    'account_holder' => '',
                    'note_prefix' => 'SYNCVIET',
                ],
                'sort_order' => 10,
            ],
            [
                'code' => 'bank_transfer',
                'name' => 'Vietnam Bank Transfer',
                'category' => 'fiat',
                'supported_currencies' => ['VND'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => true,
                'is_live_ready' => false,
                'requires_legal_clearance' => false,
                'instructions' => [
                    'bank_name' => 'Configure in Admin Payment Settings',
                    'account_number' => '',
                    'account_holder' => '',
                    'note_prefix' => 'SYNCVIET',
                ],
                'sort_order' => 20,
            ],
            [
                'code' => 'paypal',
                'name' => 'PayPal (International USD)',
                'category' => 'fiat',
                'supported_currencies' => ['USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => true,
                'is_live_ready' => false,
                'requires_legal_clearance' => false,
                'instructions' => [
                    'merchant_email' => 'Configure in Admin Payment Settings',
                    'mode' => 'sandbox',
                ],
                'sort_order' => 30,
            ],
            [
                'code' => 'sandbox',
                'name' => 'Sandbox Test Gateway (Dev Only)',
                'category' => 'sandbox',
                'supported_currencies' => ['VND', 'USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => (bool) config('syncviet.sandbox_topup_enabled', false),
                'is_live_ready' => false,
                'requires_legal_clearance' => false,
                'instructions' => ['mode' => 'non_production_only'],
                'sort_order' => 40,
            ],
            [
                'code' => 'binance_pay',
                'name' => 'Binance Pay (Future Adapter)',
                'category' => 'crypto',
                'supported_currencies' => ['USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => false,
                'is_live_ready' => false,
                'requires_legal_clearance' => true,
                'instructions' => ['status' => 'disabled_pending_legal_review'],
                'sort_order' => 60,
            ],
            [
                'code' => 'metamask',
                'name' => 'MetaMask / Web3 Wallet (Future Adapter)',
                'category' => 'crypto',
                'supported_currencies' => ['USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => false,
                'is_live_ready' => false,
                'requires_legal_clearance' => true,
                'instructions' => ['status' => 'disabled_pending_legal_review'],
                'sort_order' => 70,
            ],
            [
                'code' => 'usdt',
                'name' => 'USDT Stablecoin (Future Adapter)',
                'category' => 'crypto',
                'supported_currencies' => ['USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => false,
                'is_live_ready' => false,
                'requires_legal_clearance' => true,
                'instructions' => ['status' => 'disabled_pending_legal_review'],
                'sort_order' => 80,
            ],
            [
                'code' => 'usdc',
                'name' => 'USDC Stablecoin (Future Adapter)',
                'category' => 'crypto',
                'supported_currencies' => ['USD'],
                'fee_percent_bps' => 0,
                'fee_fixed_vnd' => 0,
                'fee_fixed_usd_cents' => 0,
                'is_enabled' => false,
                'is_live_ready' => false,
                'requires_legal_clearance' => true,
                'instructions' => ['status' => 'disabled_pending_legal_review'],
                'sort_order' => 90,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->firstOrCreate(['code' => $method['code']], $method);
        }
    }

    public function seedPlatformComplianceRules(): void
    {
        $rules = [
            [
                'platform' => 'web',
                'allow_external_web_checkout' => true,
                'allow_premium_purchase' => true,
                'allow_banner_display' => true,
                'allow_boost_purchase' => true,
                'require_native_iap_for_digital' => false,
                'allowed_payment_methods' => ['vietqr', 'bank_transfer', 'paypal', 'sandbox'],
                'policy_note' => 'Standard Website browser checkout with configured fiat payment gateways.',
            ],
            [
                'platform' => 'pwa',
                'allow_external_web_checkout' => true,
                'allow_premium_purchase' => true,
                'allow_banner_display' => true,
                'allow_boost_purchase' => true,
                'require_native_iap_for_digital' => false,
                'allowed_payment_methods' => ['vietqr', 'bank_transfer', 'paypal', 'sandbox'],
                'policy_note' => 'Progressive Web App browser checkout using standard web payment flow.',
            ],
            [
                'platform' => 'android_play',
                'allow_external_web_checkout' => false,
                'allow_premium_purchase' => false,
                'allow_banner_display' => true,
                'allow_boost_purchase' => false,
                'require_native_iap_for_digital' => true,
                'allowed_payment_methods' => ['google_play_billing'],
                'policy_note' => 'Google Play policy: in-app digital subscriptions require Google Play Billing SDK integration before enabling purchase UI.',
            ],
            [
                'platform' => 'ios_appstore',
                'allow_external_web_checkout' => false,
                'allow_premium_purchase' => false,
                'allow_banner_display' => true,
                'allow_boost_purchase' => false,
                'require_native_iap_for_digital' => true,
                'allowed_payment_methods' => ['apple_storekit'],
                'policy_note' => 'Apple App Store Guideline 3.1.1: in-app digital subscriptions require Apple StoreKit IAP before enabling purchase UI.',
            ],
        ];

        foreach ($rules as $rule) {
            PlatformComplianceRule::query()->firstOrCreate(['platform' => $rule['platform']], $rule);
        }
    }
}
