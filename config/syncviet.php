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
    | Media Storage Disk Abstraction (local, public, or s3 / Cloudflare R2)
    |--------------------------------------------------------------------------
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

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
    | Advanced International SEO & Organic Growth Engine V2.0
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'canonical_base_url' => rtrim((string) env('SEO_CANONICAL_URL', env('APP_URL', 'https://syncviet.com')), '/'),
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
];
