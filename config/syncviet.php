<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Portable Application Settings
    |--------------------------------------------------------------------------
    */
    'supported_locales' => ['vi', 'en'],

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
];
