<?php

namespace Database\Seeders;

use App\Models\SeoApiConnection;
use App\Models\SeoSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeoSeeder extends Seeder
{
    public function run(): void
    {
        self::seedDefaultsIfEmpty();
    }

    public static function seedDefaultsIfEmpty(): void
    {
        if (! Schema::hasTable('seo_settings') || ! Schema::hasTable('seo_api_connections')) {
            return;
        }

        $defaults = [
            ['group' => 'general', 'key' => 'seo_enabled', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'general', 'key' => 'allow_indexing_in_non_production', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'general', 'key' => 'default_og_image', 'value' => '/og-default.png', 'type' => 'string'],
            ['group' => 'general', 'key' => 'organization_same_as', 'value' => '[]', 'type' => 'json'],
            ['group' => 'templates', 'key' => 'title_template_home', 'value' => '{site} — Global Community & Discussions', 'type' => 'string'],
            ['group' => 'templates', 'key' => 'title_template_post', 'value' => '{title} | {site}', 'type' => 'string'],
            ['group' => 'templates', 'key' => 'title_template_community', 'value' => '{name} Community | {site}', 'type' => 'string'],
            ['group' => 'templates', 'key' => 'title_template_profile', 'value' => '{name} (@{username}) | {site}', 'type' => 'string'],
            ['group' => 'templates', 'key' => 'title_template_topic', 'value' => '#{topic} Discussions & Insights | {site}', 'type' => 'string'],
            ['group' => 'templates', 'key' => 'description_template_home', 'value' => 'Join {site} to discover multilingual discussions, global communities, and real-time insights across 15 languages.', 'type' => 'string'],
            ['group' => 'robots', 'key' => 'robots_extra_disallow', 'value' => '', 'type' => 'string'],
            ['group' => 'robots', 'key' => 'robots_allow_ai_crawlers', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'quality', 'key' => 'min_post_words', 'value' => '12', 'type' => 'integer'],
            ['group' => 'quality', 'key' => 'min_post_chars', 'value' => '60', 'type' => 'integer'],
            ['group' => 'quality', 'key' => 'min_topic_posts', 'value' => '2', 'type' => 'integer'],
            ['group' => 'quality', 'key' => 'index_user_profiles', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'analytics', 'key' => 'ga4_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'analytics', 'key' => 'ga4_measurement_id', 'value' => '', 'type' => 'string'],
            ['group' => 'analytics', 'key' => 'ga4_require_consent', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'indexnow', 'key' => 'indexnow_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'indexnow', 'key' => 'indexnow_key', 'value' => Str::lower(Str::random(32)), 'type' => 'string'],
            ['group' => 'cloudflare', 'key' => 'cloudflare_purge_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'cloudflare', 'key' => 'cloudflare_zone_id', 'value' => '', 'type' => 'string'],
            ['group' => 'gsc', 'key' => 'gsc_daily_inspection_limit', 'value' => '1800', 'type' => 'integer'],
            ['group' => 'gsc', 'key' => 'gsc_auto_submit_sitemaps', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'verification', 'key' => 'bing_meta_verification', 'value' => '', 'type' => 'string'],
        ];

        foreach ($defaults as $item) {
            SeoSetting::query()->firstOrCreate(
                ['key' => $item['key']],
                [
                    'group' => $item['group'],
                    'value' => $item['value'],
                    'type' => $item['type'],
                    'is_encrypted' => false,
                ],
            );
        }

        $providers = [
            'google_site_verification' => ['auth_type' => 'manual_dns_or_file', 'status' => 'not_verified'],
            'google_search_console' => ['auth_type' => 'none', 'status' => 'not_connected'],
            'bing_indexnow' => ['auth_type' => 'key_file', 'status' => 'disabled'],
            'ga4' => ['auth_type' => 'measurement_id', 'status' => 'disabled'],
            'cloudflare' => ['auth_type' => 'api_token', 'status' => 'not_configured'],
            'pagespeed_insights' => ['auth_type' => 'api_key', 'status' => 'not_configured'],
        ];

        foreach ($providers as $provider => $meta) {
            SeoApiConnection::query()->firstOrCreate(
                ['provider' => $provider],
                [
                    'auth_type' => $meta['auth_type'],
                    'status' => $meta['status'],
                ],
            );
        }
    }
}
