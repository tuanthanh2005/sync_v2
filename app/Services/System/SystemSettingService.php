<?php

namespace App\Services\System;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SystemSettingService
{
    public const CACHE_KEY = 'syncviet.system_settings.v1';

    /** @var array<string, mixed>|null */
    private ?array $memoizedAll = null;

    /**
     * @return array<string, array{group: string, type: string, value: mixed, is_public: bool}>
     */
    public static function defaults(): array
    {
        return [
            // General & Branding
            'site_name' => ['group' => 'general', 'type' => 'string', 'value' => config('app.name', 'SysNoxis'), 'is_public' => true],
            'site_slogan' => ['group' => 'general', 'type' => 'string', 'value' => 'Global Social Community', 'is_public' => true],
            'site_description' => ['group' => 'general', 'type' => 'string', 'value' => 'Connect without boundaries. Discover ideas, share stories, and build trusted communities.', 'is_public' => true],
            'contact_email' => ['group' => 'general', 'type' => 'string', 'value' => '', 'is_public' => true],
            'support_contact_info' => ['group' => 'general', 'type' => 'string', 'value' => '', 'is_public' => true],
            'footer_copyright_text' => ['group' => 'general', 'type' => 'string', 'value' => '', 'is_public' => true],
            'default_locale' => ['group' => 'general', 'type' => 'string', 'value' => config('app.locale', 'en'), 'is_public' => true],
            'default_theme' => ['group' => 'general', 'type' => 'string', 'value' => 'system', 'is_public' => true],
            'logo_light_path' => ['group' => 'branding', 'type' => 'string', 'value' => '', 'is_public' => true],
            'logo_dark_path' => ['group' => 'branding', 'type' => 'string', 'value' => '', 'is_public' => true],
            'favicon_path' => ['group' => 'branding', 'type' => 'string', 'value' => '', 'is_public' => true],
            'og_image_path' => ['group' => 'branding', 'type' => 'string', 'value' => '', 'is_public' => true],

            // Feature Flags (all map to real implemented platform modules)
            'feature_registration' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_google_login' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_posting' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_image_upload' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_comments' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_reactions' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_shares' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_communities' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_polls' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_follows' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_messaging' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_ads' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_payments' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_premium' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_banner_ads' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_boost_post' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feature_adsense' => ['group' => 'features', 'type' => 'boolean', 'value' => false, 'is_public' => true],
            'feature_require_email_verification' => ['group' => 'features', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'maintenance_enabled' => ['group' => 'features', 'type' => 'boolean', 'value' => false, 'is_public' => true],
            'maintenance_message' => ['group' => 'features', 'type' => 'string', 'value' => 'SysNoxis is undergoing scheduled maintenance. Please check back shortly.', 'is_public' => true],

            // Content & Image Upload Policy Settings (Admin may lower caps, NEVER exceed 40 MB)
            'content_post_max_length' => ['group' => 'content', 'type' => 'integer', 'value' => 3000, 'is_public' => true],
            'content_post_max_images' => ['group' => 'content', 'type' => 'integer', 'value' => 4, 'is_public' => true],
            'content_upload_max_mb' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'upload_max_mb_avatar' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'upload_max_mb_cover' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'upload_max_mb_post' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'upload_max_mb_banner' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'upload_max_mb_cms' => ['group' => 'content', 'type' => 'integer', 'value' => 40, 'is_public' => true],
            'content_comment_max_length' => ['group' => 'content', 'type' => 'integer', 'value' => 1500, 'is_public' => true],
            'content_posts_per_hour_limit' => ['group' => 'content', 'type' => 'integer', 'value' => 20, 'is_public' => false],
            'content_comments_per_hour_limit' => ['group' => 'content', 'type' => 'integer', 'value' => 60, 'is_public' => false],
            'content_blocked_domains' => ['group' => 'content', 'type' => 'string', 'value' => '', 'is_public' => false],
            'content_moderation_mode' => ['group' => 'content', 'type' => 'string', 'value' => 'post_publish_filter', 'is_public' => false],

            // Advertising Engine Config
            'ad_feed_frequency' => ['group' => 'ads', 'type' => 'integer', 'value' => 5, 'is_public' => false],
            'ad_min_daily_budget_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000), 'is_public' => true],
            'ad_max_daily_budget_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.max_daily_budget_vnd', 10_000_000), 'is_public' => true],
            'ad_min_total_budget_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.min_total_budget_vnd', 100_000), 'is_public' => true],
            'ad_max_total_budget_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000), 'is_public' => true],
            'ad_impression_cost_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.impression_cost_vnd', 25), 'is_public' => false],
            'ad_click_cost_vnd' => ['group' => 'ads', 'type' => 'integer', 'value' => (int) config('syncviet.ad_pricing.click_cost_vnd', 1_250), 'is_public' => false],

            // SEO Settings
            'seo_title_suffix' => ['group' => 'seo', 'type' => 'string', 'value' => config('app.name', 'SysNoxis'), 'is_public' => true],
            'seo_meta_description' => ['group' => 'seo', 'type' => 'string', 'value' => 'SysNoxis global community platform for ideas, discussions, and communities.', 'is_public' => true],
            'seo_robots_index' => ['group' => 'seo', 'type' => 'boolean', 'value' => true, 'is_public' => true],

            // Post View Tracking V2.0 Settings (Social News Feed Standard)
            'views_min_duration_ms' => ['group' => 'views', 'type' => 'integer', 'value' => (int) config('syncviet.views.min_duration_ms', 500), 'is_public' => true],
            'views_min_visible_ratio' => ['group' => 'views', 'type' => 'integer', 'value' => (int) config('syncviet.views.min_visible_ratio', 50), 'is_public' => true],
            'views_dedup_window_seconds' => ['group' => 'views', 'type' => 'integer', 'value' => (int) config('syncviet.views.dedup_window_seconds', 300), 'is_public' => true],
            'views_track_guests' => ['group' => 'views', 'type' => 'boolean', 'value' => (bool) config('syncviet.views.track_guests', true), 'is_public' => true],
            'views_track_ai' => ['group' => 'views', 'type' => 'boolean', 'value' => (bool) config('syncviet.views.track_ai', true), 'is_public' => true],
            'views_track_author_self' => ['group' => 'views', 'type' => 'boolean', 'value' => (bool) config('syncviet.views.track_author_self', false), 'is_public' => false],
            'views_bot_filtering' => ['group' => 'views', 'type' => 'boolean', 'value' => (bool) config('syncviet.views.bot_filtering', true), 'is_public' => false],
            'views_retention_days' => ['group' => 'views', 'type' => 'integer', 'value' => (int) config('syncviet.views.retention_days', 30), 'is_public' => false],

            // Monetization & Controlled Google AdSense Settings (Zero Arbitrary JS Injection)
            'monetization_usd_to_vnd_rate' => ['group' => 'monetization', 'type' => 'integer', 'value' => (int) config('syncviet.monetization.default_usd_to_vnd_rate', 25_500), 'is_public' => true],
            'monetization_max_pending_payments' => ['group' => 'monetization', 'type' => 'integer', 'value' => (int) config('syncviet.monetization.max_pending_payments_per_user', 5), 'is_public' => true],
            'monetization_allow_banner_from_ad_balance' => ['group' => 'monetization', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'adsense_enabled' => ['group' => 'adsense', 'type' => 'boolean', 'value' => false, 'is_public' => true],
            'adsense_publisher_id' => ['group' => 'adsense', 'type' => 'string', 'value' => '', 'is_public' => true],
            'adsense_slots' => ['group' => 'adsense', 'type' => 'json', 'value' => [], 'is_public' => true],
            'adsense_allowed_pages' => ['group' => 'adsense', 'type' => 'json', 'value' => ['home', 'explore', 'community', 'post'], 'is_public' => true],
            'adsense_consent_required' => ['group' => 'adsense', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'adsense_ads_txt_line' => ['group' => 'adsense', 'type' => 'string', 'value' => '', 'is_public' => false],

            // Authentication Security, Email Verification & Anti-Bot Protection V1.0
            'auth_emergency_registration_pause' => ['group' => 'auth_security', 'type' => 'boolean', 'value' => false, 'is_public' => true],
            'auth_turnstile_enabled' => ['group' => 'auth_security', 'type' => 'boolean', 'value' => (bool) config('syncviet.auth_security.turnstile_enabled', false), 'is_public' => true],
            'auth_turnstile_site_key' => ['group' => 'auth_security', 'type' => 'string', 'value' => (string) config('syncviet.auth_security.turnstile_site_key', ''), 'is_public' => true],
            'auth_turnstile_secret_encrypted' => ['group' => 'auth_security', 'type' => 'string', 'value' => '', 'is_public' => false],
            'auth_otp_expire_minutes' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.otp_expire_minutes', 5), 'is_public' => true],
            'auth_otp_resend_cooldown' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.otp_resend_cooldown_seconds', 60), 'is_public' => true],
            'auth_otp_max_attempts' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.otp_max_attempts', 5), 'is_public' => false],
            'auth_otp_max_per_email_hour' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.otp_max_per_email_hour', 5), 'is_public' => false],
            'auth_global_otp_hourly_budget' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.global_otp_hourly_budget', 300), 'is_public' => false],
            'auth_register_limit_10min' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.register_limit_per_10min', 3), 'is_public' => false],
            'auth_register_limit_day' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.register_limit_per_day', 10), 'is_public' => false],
            'auth_login_failed_limit_min' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.login_failed_limit_per_min', 5), 'is_public' => false],
            'auth_forgot_password_limit_15min' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.forgot_password_limit_15min', 3), 'is_public' => false],
            'auth_disposable_email_protection' => ['group' => 'auth_security', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'auth_suspicious_risk_threshold' => ['group' => 'auth_security', 'type' => 'integer', 'value' => 50, 'is_public' => false],
            'auth_pending_retention_hours' => ['group' => 'auth_security', 'type' => 'integer', 'value' => (int) config('syncviet.auth_security.pending_retention_hours', 24), 'is_public' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memoizedAll !== null) {
            return $this->memoizedAll;
        }

        $defaults = self::defaults();
        $resolved = [];

        foreach ($defaults as $key => $meta) {
            $resolved[$key] = $meta['value'];
        }

        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), static function () use ($defaults): array {
            if (! Schema::hasTable('system_settings')) {
                return [];
            }

            $rows = SystemSetting::query()->get(['key', 'value', 'type']);
            $map = [];

            foreach ($rows as $row) {
                $type = $defaults[$row->key]['type'] ?? $row->type;
                $map[$row->key] = self::castValue($row->value, $type);
            }

            return $map;
        });

        $this->memoizedAll = array_merge($resolved, $stored);

        return $this->memoizedAll;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $fallback;
    }

    public function featureEnabled(string $featureKey): bool
    {
        return (bool) $this->get($featureKey, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $all = $this->all();

        return [
            'siteName' => (string) $all['site_name'],
            'siteSlogan' => (string) $all['site_slogan'],
            'siteDescription' => (string) $all['site_description'],
            'contactEmail' => (string) $all['contact_email'],
            'defaultTheme' => (string) $all['default_theme'],
            'maintenanceEnabled' => (bool) $all['maintenance_enabled'],
            'maintenanceMessage' => (string) $all['maintenance_message'],
            'features' => [
                'registration' => (bool) $all['feature_registration'],
                'googleLogin' => (bool) $all['feature_google_login'],
                'posting' => (bool) $all['feature_posting'],
                'imageUpload' => (bool) $all['feature_image_upload'],
                'comments' => (bool) $all['feature_comments'],
                'reactions' => (bool) $all['feature_reactions'],
                'shares' => (bool) $all['feature_shares'],
                'communities' => (bool) $all['feature_communities'],
                'polls' => (bool) $all['feature_polls'],
                'follows' => (bool) $all['feature_follows'],
                'messaging' => (bool) $all['feature_messaging'],
                'ads' => (bool) $all['feature_ads'],
                'payments' => (bool) $all['feature_payments'],
                'premium' => (bool) $all['feature_premium'],
                'bannerAds' => (bool) $all['feature_banner_ads'],
                'boostPost' => (bool) $all['feature_boost_post'],
                'adsense' => (bool) $all['feature_adsense'],
            ],
            'limits' => [
                'postMaxLength' => (int) $all['content_post_max_length'],
                'postMaxImages' => (int) $all['content_post_max_images'],
                'uploadMaxMb' => min(40, max(1, (int) $all['content_upload_max_mb'])),
                'uploadMaxBytes' => min(40, max(1, (int) $all['content_upload_max_mb'])) * 1_000_000,
                'uploadMaxPostRequestBytes' => (int) config('syncviet.uploads.max_post_request_bytes', SafeImageUploadService::MAX_POST_REQUEST_BYTES),
                'uploadLimitsByContext' => [
                    'avatar' => min(40, max(1, min((int) $all['content_upload_max_mb'], (int) $all['upload_max_mb_avatar']))) * 1_000_000,
                    'cover' => min(40, max(1, min((int) $all['content_upload_max_mb'], (int) $all['upload_max_mb_cover']))) * 1_000_000,
                    'post' => min(40, max(1, min((int) $all['content_upload_max_mb'], (int) $all['upload_max_mb_post']))) * 1_000_000,
                    'banner' => min(40, max(1, min((int) $all['content_upload_max_mb'], (int) $all['upload_max_mb_banner']))) * 1_000_000,
                    'cms' => min(40, max(1, min((int) $all['content_upload_max_mb'], (int) $all['upload_max_mb_cms']))) * 1_000_000,
                ],
                'commentMaxLength' => (int) $all['content_comment_max_length'],
            ],
            'views' => [
                'minDurationMs' => max(100, min(10_000, (int) $all['views_min_duration_ms'])),
                'minVisibleRatio' => max(10, min(100, (int) $all['views_min_visible_ratio'])),
                'dedupWindowSeconds' => max(30, min(86_400, (int) $all['views_dedup_window_seconds'])),
                'trackGuests' => (bool) $all['views_track_guests'],
                'trackAi' => (bool) $all['views_track_ai'],
            ],
            'auth' => [
                'mandatoryEmailVerification' => true,
                'emergencyRegistrationPause' => (bool) $all['auth_emergency_registration_pause'],
                'turnstileEnabled' => (bool) $all['auth_turnstile_enabled'] && trim((string) $all['auth_turnstile_site_key']) !== '',
                'turnstileSiteKey' => trim((string) $all['auth_turnstile_site_key']),
                'otpExpireMinutes' => max(3, min(15, (int) $all['auth_otp_expire_minutes'])),
                'otpResendCooldown' => max(30, min(300, (int) $all['auth_otp_resend_cooldown'])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function updateMany(array $values, User $actor, AuditLogger $auditLogger): void
    {
        $defaults = self::defaults();

        foreach ($values as $key => $rawValue) {
            if (! isset($defaults[$key])) {
                continue;
            }

            $uploadLimitKeys = [
                'content_upload_max_mb',
                'upload_max_mb_avatar',
                'upload_max_mb_cover',
                'upload_max_mb_post',
                'upload_max_mb_banner',
                'upload_max_mb_cms',
            ];

            $boundedIntegerKeys = [
                'views_min_duration_ms' => [100, 10_000],
                'views_min_visible_ratio' => [10, 100],
                'views_dedup_window_seconds' => [30, 86_400],
                'views_retention_days' => [7, 180],
                'auth_otp_expire_minutes' => [3, 15],
                'auth_otp_resend_cooldown' => [30, 300],
                'auth_otp_max_attempts' => [3, 10],
                'auth_otp_max_per_email_hour' => [2, 15],
                'auth_global_otp_hourly_budget' => [20, 5000],
                'auth_register_limit_10min' => [1, 20],
                'auth_register_limit_day' => [3, 50],
                'auth_login_failed_limit_min' => [3, 20],
                'auth_forgot_password_limit_15min' => [1, 10],
                'auth_suspicious_risk_threshold' => [20, 100],
                'auth_pending_retention_hours' => [6, 72],
            ];

            if ($key === 'feature_require_email_verification' && app()->isProduction()) {
                $rawValue = true;
            }

            $meta = $defaults[$key];
            $storedValue = match ($meta['type']) {
                'boolean' => filter_var($rawValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                'integer' => in_array($key, $uploadLimitKeys, true)
                    ? (string) max(1, min(40, (int) $rawValue))
                    : (isset($boundedIntegerKeys[$key])
                        ? (string) max($boundedIntegerKeys[$key][0], min($boundedIntegerKeys[$key][1], (int) $rawValue))
                        : (string) max(0, (int) $rawValue)),
                'json' => json_encode($rawValue) ?: '[]',
                default => trim((string) $rawValue),
            };

            $setting = SystemSetting::query()->firstOrNew(['key' => $key]);
            $setting->forceFill([
                'group' => $meta['group'],
                'value' => $storedValue,
                'type' => $meta['type'],
                'is_public' => $meta['is_public'],
                'updated_by' => $actor->id,
            ])->save();
        }

        $this->flushCache();

        $auditLogger->log(
            'system.settings_updated',
            null,
            ['keys' => array_values(array_intersect(array_keys($values), array_keys($defaults)))],
            $actor,
        );
    }

    public function flushCache(): void
    {
        $this->memoizedAll = null;
        Cache::forget(self::CACHE_KEY);
    }

    private static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1' || strtolower($value) === 'true',
            'integer' => (int) $value,
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}
