<?php

namespace App\Services\System;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
            'site_slogan' => ['group' => 'general', 'type' => 'string', 'value' => (string) config('syncviet.branding.default_slogan', 'Connect Beyond Borders.'), 'is_public' => true],
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

            // Publishing Rules & Per-Tier Quotas V1.0
            'posting_new_member_age_days' => ['group' => 'publishing', 'type' => 'integer', 'value' => 7, 'is_public' => false],
            'posting_limit_24h_new' => ['group' => 'publishing', 'type' => 'integer', 'value' => 10, 'is_public' => false],
            'posting_limit_24h_standard' => ['group' => 'publishing', 'type' => 'integer', 'value' => 30, 'is_public' => false],
            'posting_limit_24h_premium' => ['group' => 'publishing', 'type' => 'integer', 'value' => 100, 'is_public' => false],
            'posting_limit_60m_new' => ['group' => 'publishing', 'type' => 'integer', 'value' => 3, 'is_public' => false],
            'posting_limit_60m_standard' => ['group' => 'publishing', 'type' => 'integer', 'value' => 10, 'is_public' => false],
            'posting_limit_60m_premium' => ['group' => 'publishing', 'type' => 'integer', 'value' => 20, 'is_public' => false],
            'posting_min_interval_new' => ['group' => 'publishing', 'type' => 'integer', 'value' => 60, 'is_public' => false],
            'posting_min_interval_standard' => ['group' => 'publishing', 'type' => 'integer', 'value' => 30, 'is_public' => false],
            'posting_min_interval_premium' => ['group' => 'publishing', 'type' => 'integer', 'value' => 15, 'is_public' => false],
            'comment_limit_24h_new' => ['group' => 'publishing', 'type' => 'integer', 'value' => 50, 'is_public' => false],
            'comment_limit_24h_standard' => ['group' => 'publishing', 'type' => 'integer', 'value' => 200, 'is_public' => false],
            'comment_limit_24h_premium' => ['group' => 'publishing', 'type' => 'integer', 'value' => 500, 'is_public' => false],
            'post_max_images_new' => ['group' => 'publishing', 'type' => 'integer', 'value' => 4, 'is_public' => false],
            'post_max_images_standard' => ['group' => 'publishing', 'type' => 'integer', 'value' => 4, 'is_public' => false],
            'post_max_images_premium' => ['group' => 'publishing', 'type' => 'integer', 'value' => 8, 'is_public' => false],
            'post_edits_per_day_limit' => ['group' => 'publishing', 'type' => 'integer', 'value' => 15, 'is_public' => false],
            'post_edit_window_minutes' => ['group' => 'publishing', 'type' => 'integer', 'value' => 0, 'is_public' => false],

            // Anti-Spam & Duplicate Detection V1.0
            'antispam_enabled' => ['group' => 'antispam', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'duplicate_detection_enabled' => ['group' => 'antispam', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'duplicate_detection_window_hours' => ['group' => 'antispam', 'type' => 'integer', 'value' => 24, 'is_public' => false],
            'duplicate_similarity_threshold' => ['group' => 'antispam', 'type' => 'integer', 'value' => 85, 'is_public' => false],
            'antispam_medium_threshold' => ['group' => 'antispam', 'type' => 'integer', 'value' => 30, 'is_public' => false],
            'antispam_high_threshold' => ['group' => 'antispam', 'type' => 'integer', 'value' => 60, 'is_public' => false],
            'antispam_violation_threshold' => ['group' => 'antispam', 'type' => 'integer', 'value' => 85, 'is_public' => false],
            'antispam_max_links_per_post' => ['group' => 'antispam', 'type' => 'integer', 'value' => 5, 'is_public' => false],
            'antispam_max_mentions_per_post' => ['group' => 'antispam', 'type' => 'integer', 'value' => 10, 'is_public' => false],
            'antispam_max_hashtags_per_post' => ['group' => 'antispam', 'type' => 'integer', 'value' => 10, 'is_public' => false],
            'antispam_temp_restriction_hours' => ['group' => 'antispam', 'type' => 'integer', 'value' => 6, 'is_public' => false],

            // Moderation Workflow V1.0
            'moderation_risk_based_enabled' => ['group' => 'moderation', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'moderation_require_manual_review' => ['group' => 'moderation', 'type' => 'boolean', 'value' => false, 'is_public' => false],
            'moderation_review_threshold' => ['group' => 'moderation', 'type' => 'integer', 'value' => 60, 'is_public' => false],
            'moderation_ai_assist_enabled' => ['group' => 'moderation', 'type' => 'boolean', 'value' => false, 'is_public' => false],
            'moderation_suspicious_link_action' => ['group' => 'moderation', 'type' => 'string', 'value' => 'flag', 'is_public' => false],
            'moderation_violation_action' => ['group' => 'moderation', 'type' => 'string', 'value' => 'reject', 'is_public' => false],

            // Feed Distribution & Personalization V1.0
            'feed_enable_trending' => ['group' => 'feed', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feed_enable_recommended' => ['group' => 'feed', 'type' => 'boolean', 'value' => true, 'is_public' => true],
            'feed_enable_fresh_discovery' => ['group' => 'feed', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'feed_candidate_window_hours' => ['group' => 'feed', 'type' => 'integer', 'value' => 168, 'is_public' => false],
            'feed_refresh_interval_minutes' => ['group' => 'feed', 'type' => 'integer', 'value' => 5, 'is_public' => false],
            'feed_page_size' => ['group' => 'feed', 'type' => 'integer', 'value' => 10, 'is_public' => true],
            'feed_author_diversity_max' => ['group' => 'feed', 'type' => 'integer', 'value' => 2, 'is_public' => false],
            'feed_category_diversity_enabled' => ['group' => 'feed', 'type' => 'boolean', 'value' => true, 'is_public' => false],
            'feed_allocation_ranking_pct' => ['group' => 'feed', 'type' => 'integer', 'value' => 70, 'is_public' => false],
            'feed_allocation_fresh_pct' => ['group' => 'feed', 'type' => 'integer', 'value' => 20, 'is_public' => false],
            'feed_allocation_discovery_pct' => ['group' => 'feed', 'type' => 'integer', 'value' => 10, 'is_public' => false],
            'feed_ad_spacing_posts' => ['group' => 'feed', 'type' => 'integer', 'value' => 6, 'is_public' => true],

            // Trending Weights V1.0
            'trending_weight_human_view' => ['group' => 'trending', 'type' => 'float', 'value' => 0.1, 'is_public' => false],
            'trending_weight_reaction' => ['group' => 'trending', 'type' => 'float', 'value' => 2.0, 'is_public' => false],
            'trending_weight_comment' => ['group' => 'trending', 'type' => 'float', 'value' => 4.0, 'is_public' => false],
            'trending_weight_bookmark' => ['group' => 'trending', 'type' => 'float', 'value' => 5.0, 'is_public' => false],
            'trending_weight_share' => ['group' => 'trending', 'type' => 'float', 'value' => 6.0, 'is_public' => false],
            'trending_decay_base_hours' => ['group' => 'trending', 'type' => 'float', 'value' => 24.0, 'is_public' => false],
            'trending_decay_exponent' => ['group' => 'trending', 'type' => 'float', 'value' => 1.5, 'is_public' => false],
            'trending_per_user_contribution_cap' => ['group' => 'trending', 'type' => 'integer', 'value' => 20, 'is_public' => false],
            'trending_view_engagement_ratio' => ['group' => 'trending', 'type' => 'integer', 'value' => 200, 'is_public' => false],

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
     * Centralized brand identity payload with Admin overrides and versioned SysNoxis defaults.
     *
     * @return array{
     *     version: string,
     *     logoLightUrl: string|null,
     *     logoDarkUrl: string|null,
     *     faviconUrl: string|null,
     *     ogImageUrl: string|null,
     *     defaults: array{
     *         iconLight: string,
     *         iconDark: string,
     *         logoLight: string,
     *         logoDark: string,
     *         faviconSvg: string,
     *         faviconIco: string,
     *         favicon16: string,
     *         favicon32: string,
     *         appleTouchIcon: string,
     *         pwa192: string,
     *         ogImage: string
     *     }
     * }
     */
    public function brandingPayload(): array
    {
        $all = $this->all();
        $version = (string) config('syncviet.branding.asset_version', 'sysnoxis-2');
        $v = '?v='.rawurlencode($version);

        return [
            'version' => $version,
            'logoLightUrl' => $this->resolveBrandingAssetUrl((string) ($all['logo_light_path'] ?? '')),
            'logoDarkUrl' => $this->resolveBrandingAssetUrl((string) ($all['logo_dark_path'] ?? '')),
            'faviconUrl' => $this->resolveBrandingAssetUrl((string) ($all['favicon_path'] ?? '')),
            'ogImageUrl' => $this->resolveBrandingAssetUrl((string) ($all['og_image_path'] ?? '')),
            'defaults' => [
                'iconLight' => (string) config('syncviet.branding.assets.icon_light', '/brand/sysnoxis-icon.svg').$v,
                'iconDark' => (string) config('syncviet.branding.assets.icon_dark', '/brand/sysnoxis-icon-dark.svg').$v,
                'logoLight' => (string) config('syncviet.branding.assets.logo_light', '/brand/sysnoxis-logo-light.svg').$v,
                'logoDark' => (string) config('syncviet.branding.assets.logo_dark', '/brand/sysnoxis-logo-dark.svg').$v,
                'faviconSvg' => (string) config('syncviet.branding.assets.favicon_svg', '/favicon.svg').$v,
                'faviconIco' => (string) config('syncviet.branding.assets.favicon_ico', '/favicon.ico').$v,
                'favicon16' => (string) config('syncviet.branding.assets.favicon_16', '/icons/favicon-16x16.png').$v,
                'favicon32' => (string) config('syncviet.branding.assets.favicon_32', '/icons/favicon-32x32.png').$v,
                'appleTouchIcon' => (string) config('syncviet.branding.assets.apple_touch_icon', '/apple-touch-icon.png').$v,
                'pwa192' => (string) config('syncviet.branding.assets.pwa_192', '/icons/icon-192.png').$v,
                'ogImage' => (string) config('syncviet.branding.assets.og_default', '/og-default.png').$v,
            ],
        ];
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
            'branding' => $this->brandingPayload(),
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
                'content_post_max_length' => [100, 10_000],
                'content_comment_max_length' => [100, 5_000],
                'posting_new_member_age_days' => [1, 90],
                'posting_limit_24h_new' => [1, 200],
                'posting_limit_24h_standard' => [1, 500],
                'posting_limit_24h_premium' => [1, 1_000],
                'posting_limit_60m_new' => [1, 50],
                'posting_limit_60m_standard' => [1, 100],
                'posting_limit_60m_premium' => [1, 200],
                'posting_min_interval_new' => [0, 3_600],
                'posting_min_interval_standard' => [0, 3_600],
                'posting_min_interval_premium' => [0, 3_600],
                'comment_limit_24h_new' => [1, 1_000],
                'comment_limit_24h_standard' => [1, 2_000],
                'comment_limit_24h_premium' => [1, 5_000],
                'post_max_images_new' => [1, 12],
                'post_max_images_standard' => [1, 12],
                'post_max_images_premium' => [1, 12],
                'post_edits_per_day_limit' => [1, 200],
                'post_edit_window_minutes' => [0, 525_600],
                'duplicate_detection_window_hours' => [1, 720],
                'duplicate_similarity_threshold' => [50, 100],
                'antispam_medium_threshold' => [10, 90],
                'antispam_high_threshold' => [20, 95],
                'antispam_violation_threshold' => [30, 100],
                'antispam_max_links_per_post' => [0, 50],
                'antispam_max_mentions_per_post' => [0, 50],
                'antispam_max_hashtags_per_post' => [0, 50],
                'antispam_temp_restriction_hours' => [1, 720],
                'moderation_review_threshold' => [20, 95],
                'feed_candidate_window_hours' => [12, 720],
                'feed_refresh_interval_minutes' => [1, 60],
                'feed_page_size' => [5, 30],
                'feed_author_diversity_max' => [1, 10],
                'feed_allocation_ranking_pct' => [10, 100],
                'feed_allocation_fresh_pct' => [0, 80],
                'feed_allocation_discovery_pct' => [0, 50],
                'feed_ad_spacing_posts' => [3, 20],
                'trending_per_user_contribution_cap' => [1, 100],
                'trending_view_engagement_ratio' => [0, 5_000],
            ];

            $boundedFloatKeys = [
                'trending_weight_human_view' => [0.0, 5.0],
                'trending_weight_reaction' => [0.0, 20.0],
                'trending_weight_comment' => [0.0, 30.0],
                'trending_weight_bookmark' => [0.0, 30.0],
                'trending_weight_share' => [0.0, 30.0],
                'trending_decay_base_hours' => [1.0, 168.0],
                'trending_decay_exponent' => [0.1, 4.0],
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
                'float' => isset($boundedFloatKeys[$key])
                    ? (string) round(max($boundedFloatKeys[$key][0], min($boundedFloatKeys[$key][1], (float) $rawValue)), 4)
                    : (string) round((float) $rawValue, 4),
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

    private function resolveBrandingAssetUrl(string $path): ?string
    {
        $clean = trim($path);
        if ($clean === '') {
            return null;
        }

        $disk = (string) config('syncviet.media_disk', 'public');

        return Storage::disk($disk)->url($clean);
    }

    private static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1' || strtolower($value) === 'true',
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}
