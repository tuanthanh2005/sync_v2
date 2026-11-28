<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Portable Application Settings
    |--------------------------------------------------------------------------
    */
    'supported_locales' => [
        'en', 'vi', 'es', 'pt-BR', 'id', 'hi', 'th', 'fil',
        'fr', 'de', 'ja', 'ko', 'zh-CN', 'ar', 'tr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverse Proxy / Cloudflare Trust & Automated Backup Retention
    |--------------------------------------------------------------------------
    | Set TRUSTED_PROXIES to comma-separated proxy IPs/CIDRs (or "*" ONLY when
    | origin port 80/443 is strictly firewalled to Cloudflare / Hostinger proxy).
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'backup_retention_days' => (int) env('SYNCVIET_BACKUP_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Centralized Brand Identity & Asset Versioning (SysNoxis)
    |--------------------------------------------------------------------------
    */
    'branding' => [
        'asset_version' => 'sysnoxis-2',
        'default_slogan' => 'Connect Beyond Borders.',
        'assets' => [
            'icon_light' => '/brand/sysnoxis-icon.svg',
            'icon_dark' => '/brand/sysnoxis-icon-dark.svg',
            'icon_mono' => '/brand/sysnoxis-icon-mono.svg',
            'icon_transparent' => '/brand/sysnoxis-icon-transparent.svg',
            'logo_light' => '/brand/sysnoxis-logo-light.svg',
            'logo_dark' => '/brand/sysnoxis-logo-dark.svg',
            'logo_mono' => '/brand/sysnoxis-logo-mono.svg',
            'brand_light' => '/brand/sysnoxis-brand-light.svg',
            'brand_dark' => '/brand/sysnoxis-brand-dark.svg',
            'favicon_svg' => '/favicon.svg',
            'favicon_ico' => '/favicon.ico',
            'favicon_16' => '/icons/favicon-16x16.png',
            'favicon_32' => '/icons/favicon-32x32.png',
            'apple_touch_icon' => '/apple-touch-icon.png',
            'pwa_192' => '/icons/icon-192.png',
            'pwa_512' => '/icons/icon-512.png',
            'pwa_maskable_512' => '/icons/icon-maskable-512.png',
            'og_default' => '/og-default.svg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Storage Disk Abstraction (local, public, or s3 / Cloudflare R2)
    |--------------------------------------------------------------------------
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Global Image Upload Policy & Security Limits
    |--------------------------------------------------------------------------
    | Strict global limit: every uploaded image MUST be smaller than 40 MB
    | (40,000,000 bytes; files >= 40,000,000 bytes are rejected).
    | External image URL import is strictly disabled across the entire platform.
    */
    'uploads' => [
        'max_image_bytes' => 40_000_000,
        'max_post_request_bytes' => 80_000_000,
        'max_input_dimension' => 8000,
        'max_total_pixels' => 25_000_000,
        'convert_to_webp' => true,
        'allowed_mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'contexts' => [
            'avatar' => ['max_bytes' => 40_000_000, 'max_dimension' => 512, 'setting_key' => 'upload_max_mb_avatar'],
            'cover' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_cover'],
            'post' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_post'],
            'comment' => ['max_bytes' => 40_000_000, 'max_dimension' => 1600, 'setting_key' => 'upload_max_mb_post'],
            'community_avatar' => ['max_bytes' => 40_000_000, 'max_dimension' => 512, 'setting_key' => 'upload_max_mb_avatar'],
            'community_cover' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_cover'],
            'banner' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_banner'],
            'branding' => ['max_bytes' => 40_000_000, 'max_dimension' => 1024, 'setting_key' => 'upload_max_mb_cms'],
            'favicon' => ['max_bytes' => 40_000_000, 'max_dimension' => 512, 'setting_key' => 'upload_max_mb_cms'],
            'og_image' => ['max_bytes' => 40_000_000, 'max_dimension' => 1200, 'setting_key' => 'upload_max_mb_cms'],
            'cms' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_cms'],
            'ai_agent' => ['max_bytes' => 40_000_000, 'max_dimension' => 1920, 'setting_key' => 'upload_max_mb_avatar'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Development Seeder Password (never used in production)
    |--------------------------------------------------------------------------
    */
    'dev_seed_password' => env('DEV_SEED_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Payment Webhook & Sandbox Top-up Settings
    |--------------------------------------------------------------------------
    */
    'payment_webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    'sandbox_topup_enabled' => env('APP_ENV', 'production') !== 'production',

    /*
    |--------------------------------------------------------------------------
    | Advertising Engine Configuration (Integer VND, Zero Floats)
    |--------------------------------------------------------------------------
    */
    'ad_pricing' => [
        'min_daily_budget_vnd' => 50_000,
        'max_daily_budget_vnd' => 10_000_000,
        'min_total_budget_vnd' => 100_000,
        'max_total_budget_vnd' => 50_000_000,
        'impression_cost_vnd' => 25,
        'click_cost_vnd' => 1_250,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monetization & Payment Guardrails
    |--------------------------------------------------------------------------
    */
    'monetization' => [
        'max_pending_payments_per_user' => (int) env('SYNCVIET_MAX_PENDING_PAYMENTS', 5),
        'payment_order_expiry_hours' => (int) env('SYNCVIET_PAYMENT_EXPIRY_HOURS', 24),
        'default_usd_to_vnd_rate' => (int) env('SYNCVIET_USD_VND_RATE', 25_500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical Domain & Legacy Domain 301 Redirect Configuration
    |--------------------------------------------------------------------------
    | Controls 301 canonicalization from legacy domains (e.g. syncviet.com,
    | www.syncviet.com) and www apex normalization to the primary domain.
    | Payment webhooks (/webhooks/*), /up, and /.well-known/* are always exempt.
    */
    'domain_migration' => [
        'redirect_enabled' => (bool) env('DOMAIN_REDIRECT_ENABLED', false),
        'primary_url' => rtrim((string) env('SEO_CANONICAL_URL', env('APP_URL', 'https://sysnoxis.com')), '/'),
        'legacy_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('DOMAIN_LEGACY_HOSTS', '')),
        ))),
        'redirect_www_to_apex' => (bool) env('DOMAIN_REDIRECT_WWW_TO_APEX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced International SEO & Organic Growth Engine V2.0
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'canonical_base_url' => rtrim((string) env('SEO_CANONICAL_URL', env('APP_URL', 'https://sysnoxis.com')), '/'),
        'sitemap_shard_size' => (int) env('SEO_SITEMAP_SHARD_SIZE', 5000),
        'sitemap_storage_dir' => 'seo/sitemaps',
        'min_post_words_for_index' => (int) env('SEO_MIN_POST_WORDS', 12),
        'min_post_chars_for_index' => (int) env('SEO_MIN_POST_CHARS', 60),
        'min_topic_posts_for_index' => (int) env('SEO_MIN_TOPIC_POSTS', 2),
        'gsc_daily_inspection_quota' => (int) env('SEO_GSC_INSPECTION_DAILY_QUOTA', 1800),
        'gsc_inspection_batch_size' => (int) env('SEO_GSC_INSPECTION_BATCH_SIZE', 25),
        'indexnow_endpoint' => (string) env('SEO_INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        'pagespeed_api_key' => env('PAGESPEED_INSIGHTS_API_KEY'),
        'default_og_image' => '/og-default.svg',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Content Center & Gemini API Management V1.0
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'gemini_base_url' => rtrim((string) env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
        'request_timeout_seconds' => (int) env('AI_REQUEST_TIMEOUT_SECONDS', 35),
        'max_concurrency' => (int) env('AI_MAX_CONCURRENCY', 1),
        'queue_name' => (string) env('AI_QUEUE_NAME', 'ai'),
        'max_agents_limit' => (int) env('AI_MAX_AGENTS_LIMIT', 30),
        'default_daily_draft_limit' => (int) env('AI_DAILY_DRAFT_LIMIT', 10),
        'default_daily_publish_limit' => (int) env('AI_DAILY_PUBLISH_LIMIT', 5),
        'default_daily_translation_limit' => (int) env('AI_DAILY_TRANSLATION_LIMIT', 10),
        'default_daily_reply_limit' => (int) env('AI_DAILY_REPLY_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Post View Tracking V2.0 (Social News Feed Standard)
    |--------------------------------------------------------------------------
    */
    'views' => [
        'min_duration_ms' => (int) env('SYNCVIET_VIEWS_MIN_DURATION_MS', 500),
        'min_visible_ratio' => (int) env('SYNCVIET_VIEWS_MIN_VISIBLE_RATIO', 50),
        'dedup_window_seconds' => (int) env('SYNCVIET_VIEWS_DEDUP_WINDOW_SECONDS', 300),
        'track_guests' => (bool) env('SYNCVIET_VIEWS_TRACK_GUESTS', true),
        'track_ai' => (bool) env('SYNCVIET_VIEWS_TRACK_AI', true),
        'track_author_self' => (bool) env('SYNCVIET_VIEWS_TRACK_AUTHOR_SELF', false),
        'bot_filtering' => (bool) env('SYNCVIET_VIEWS_BOT_FILTERING', true),
        'retention_days' => (int) env('SYNCVIET_VIEWS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Authentication Security, Email Verification & Anti-Bot V1.0
    |--------------------------------------------------------------------------
    */
    'auth_security' => [
        'turnstile_enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'turnstile_site_key' => (string) env('TURNSTILE_SITE_KEY', ''),
        'turnstile_secret_key' => (string) env('TURNSTILE_SECRET_KEY', ''),
        'turnstile_verify_url' => (string) env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'otp_expire_minutes' => (int) env('AUTH_OTP_EXPIRE_MINUTES', 5),
        'otp_link_expire_minutes' => (int) env('AUTH_OTP_LINK_EXPIRE_MINUTES', 30),
        'otp_resend_cooldown_seconds' => (int) env('AUTH_OTP_RESEND_COOLDOWN', 60),
        'otp_max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
        'otp_max_per_email_hour' => (int) env('AUTH_OTP_MAX_PER_EMAIL_HOUR', 5),
        'global_otp_hourly_budget' => (int) env('AUTH_GLOBAL_OTP_HOURLY_BUDGET', 300),
        'pending_retention_hours' => (int) env('AUTH_PENDING_RETENTION_HOURS', 24),
        'max_active_pending_registrations' => (int) env('AUTH_MAX_ACTIVE_PENDING', 5000),
        'register_limit_per_10min' => (int) env('AUTH_REGISTER_LIMIT_10MIN', 3),
        'register_limit_per_day' => (int) env('AUTH_REGISTER_LIMIT_DAY', 10),
        'login_failed_limit_per_min' => (int) env('AUTH_LOGIN_FAILED_LIMIT_MIN', 5),
        'forgot_password_limit_15min' => (int) env('AUTH_FORGOT_PASSWORD_LIMIT_15MIN', 3),
        'reset_password_limit_15min' => (int) env('AUTH_RESET_PASSWORD_LIMIT_15MIN', 5),
        'change_email_limit_hour' => (int) env('AUTH_CHANGE_EMAIL_LIMIT_HOUR', 3),
    ],
];
