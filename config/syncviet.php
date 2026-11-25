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
];
