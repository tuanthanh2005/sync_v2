<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 40)->default('general')->index();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seo_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('locale', 12)->default('en');
            $table->char('path_hash', 64)->nullable()->unique();
            $table->string('path', 512)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('robots', 80)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->string('og_description', 500)->nullable();
            $table->string('og_image', 1024)->nullable();
            $table->string('og_type', 40)->nullable();
            $table->string('twitter_card', 40)->nullable();
            $table->string('schema_type', 60)->nullable();
            $table->json('custom_json_ld')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'locale'], 'seo_metadata_entity_locale_unique');
        });

        Schema::create('seo_url_registry', function (Blueprint $table): void {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->string('url', 1024);
            $table->string('path', 512);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('locale', 12)->default('en');
            $table->string('source_locale', 12)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('index_eligibility', 32)->default('eligible');
            $table->string('status', 32)->default('eligible');
            $table->string('exclusion_reason', 120)->nullable();
            $table->unsignedSmallInteger('http_status')->default(200);
            $table->unsignedSmallInteger('priority_score')->default(50);
            $table->unsignedSmallInteger('quality_score')->default(0);
            $table->boolean('is_in_sitemap')->default(false);
            $table->string('sitemap_shard_key', 100)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('lastmod_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_inspected_at')->nullable();
            $table->string('inspection_verdict', 40)->nullable();
            $table->string('inspection_coverage_state', 160)->nullable();
            $table->timestamp('indexnow_last_submitted_at')->nullable();
            $table->char('indexnow_last_hash', 64)->nullable();
            $table->boolean('is_noindex_forced')->default(false);
            $table->json('metadata_snapshot')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'locale'], 'seo_registry_entity_locale_idx');
            $table->index(['index_eligibility', 'is_in_sitemap'], 'seo_registry_eligibility_sitemap_idx');
            $table->index(['status', 'priority_score'], 'seo_registry_status_priority_idx');
            $table->index('sitemap_shard_key', 'seo_registry_shard_idx');
            $table->index(['locale', 'entity_type', 'index_eligibility'], 'seo_registry_locale_type_elig_idx');
        });

        Schema::create('seo_url_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('url_registry_id')->constrained('seo_url_registry')->cascadeOnDelete();
            $table->string('provider', 32)->default('google_search_console');
            $table->string('inspection_status', 32)->default('queued');
            $table->string('verdict', 40)->nullable();
            $table->string('coverage_state', 160)->nullable();
            $table->string('indexing_state', 80)->nullable();
            $table->string('page_fetch_state', 80)->nullable();
            $table->string('robots_txt_state', 80)->nullable();
            $table->string('google_canonical', 1024)->nullable();
            $table->string('user_canonical', 1024)->nullable();
            $table->string('mobile_usability_verdict', 40)->nullable();
            $table->string('rich_results_verdict', 40)->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->json('raw_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();

            $table->index(['url_registry_id', 'inspected_at'], 'seo_inspections_registry_time_idx');
            $table->index('inspection_status', 'seo_inspections_status_idx');
        });

        Schema::create('seo_sitemaps', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('type', 32)->index();
            $table->string('locale', 12)->nullable()->index();
            $table->unsignedInteger('total_urls')->default(0);
            $table->unsignedInteger('total_shards')->default(0);
            $table->string('status', 32)->default('ready');
            $table->boolean('is_dirty')->default(true)->index();
            $table->string('etag', 64)->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('gsc_submitted_at')->nullable();
            $table->string('gsc_status', 40)->nullable();
            $table->unsignedInteger('gsc_errors')->default(0);
            $table->unsignedInteger('gsc_warnings')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_sitemap_shards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sitemap_id')->constrained('seo_sitemaps')->cascadeOnDelete();
            $table->string('shard_key', 100)->unique();
            $table->unsignedInteger('shard_index')->default(1);
            $table->string('storage_path', 255);
            $table->unsignedInteger('url_count')->default(0);
            $table->unsignedInteger('byte_size')->default(0);
            $table->string('etag', 64)->nullable();
            $table->boolean('is_dirty')->default(true)->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('lastmod_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_content_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('original_language', 12)->default('en');
            $table->string('target_locale', 12);
            $table->string('translated_title', 255)->nullable();
            $table->longText('translated_body');
            $table->string('translated_description', 500)->nullable();
            $table->string('localized_slug', 180);
            $table->string('provider', 40)->default('manual');
            $table->char('source_content_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('review_status', 32)->default('draft');
            $table->string('publication_status', 32)->default('draft');
            $table->boolean('is_ai_assisted')->default(false);
            $table->boolean('is_stale')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'target_locale'], 'seo_translations_entity_target_unique');
            $table->index(['target_locale', 'publication_status', 'review_status'], 'seo_translations_pub_review_idx');
            $table->index('localized_slug', 'seo_translations_slug_idx');
        });

        Schema::create('seo_redirects', function (Blueprint $table): void {
            $table->id();
            $table->char('source_path_hash', 64)->unique();
            $table->string('source_path', 512);
            $table->string('target_url', 1024);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seo_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger_type', 32)->default('manual');
            $table->string('status', 32)->default('running')->index();
            $table->unsignedTinyInteger('technical_health_score')->default(0);
            $table->unsignedInteger('urls_scanned')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->json('summary')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_audit_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_run_id')->constrained('seo_audit_runs')->cascadeOnDelete();
            $table->foreignId('url_registry_id')->nullable()->constrained('seo_url_registry')->nullOnDelete();
            $table->string('check_code', 64)->index();
            $table->string('severity', 16)->index();
            $table->string('url', 1024)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->text('recommendation')->nullable();
            $table->string('status', 24)->default('open');
            $table->char('fingerprint', 64)->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['audit_run_id', 'severity'], 'seo_audit_issues_run_sev_idx');
            $table->index(['status', 'severity'], 'seo_audit_issues_status_sev_idx');
        });

        Schema::create('seo_search_analytics', function (Blueprint $table): void {
            $table->id();
            $table->char('row_hash', 64)->unique();
            $table->date('date')->index();
            $table->string('property_url', 255);
            $table->char('page_url_hash', 64)->index();
            $table->string('page_url', 1024);
            $table->string('query', 255)->default('')->index();
            $table->string('country', 8)->default('')->index();
            $table->string('device', 16)->default('');
            $table->string('locale', 12)->nullable()->index();
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamps();

            $table->index(['date', 'locale'], 'seo_analytics_date_locale_idx');
            $table->index(['entity_type', 'entity_id'], 'seo_analytics_entity_idx');
        });

        Schema::create('seo_api_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->string('auth_type', 32)->default('none');
            $table->string('property_identifier', 255)->nullable();
            $table->string('status', 32)->default('not_connected');
            $table->string('verification_method', 32)->nullable();
            $table->string('verification_token', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->longText('credentials_encrypted')->nullable();
            $table->longText('access_token_encrypted')->nullable();
            $table->longText('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seo_api_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('endpoint', 80);
            $table->date('usage_date');
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->unsignedInteger('quota_limit')->default(2000);
            $table->timestamp('last_called_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'endpoint', 'usage_date'], 'seo_api_usage_unique');
        });

        Schema::create('seo_indexnow_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('url_registry_id')->nullable()->constrained('seo_url_registry')->nullOnDelete();
            $table->string('url', 1024);
            $table->char('url_hash', 64);
            $table->char('content_hash', 64)->nullable();
            $table->string('endpoint', 255)->default('https://api.indexnow.org/indexnow');
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('response_excerpt', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'seo_indexnow_status_created_idx');
            $table->index(['url_hash', 'content_hash'], 'seo_indexnow_dedupe_idx');
        });

        Schema::create('seo_job_history', function (Blueprint $table): void {
            $table->id();
            $table->string('job_name', 80);
            $table->string('queue', 40)->default('default');
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('context')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['job_name', 'started_at'], 'seo_job_history_name_time_idx');
            $table->index('status', 'seo_job_history_status_idx');
        });

        Schema::create('seo_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('priority', 16)->default('medium')->index();
            $table->string('locale', 12)->nullable()->index();
            $table->foreignId('url_registry_id')->nullable()->constrained('seo_url_registry')->nullOnDelete();
            $table->string('query', 255)->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->json('metrics')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->char('fingerprint', 64)->unique();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_organic_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 40);
            $table->char('session_hash', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 40)->default('organic_search');
            $table->string('search_engine', 32)->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at'], 'seo_organic_type_time_idx');
            $table->index(['source', 'locale', 'occurred_at'], 'seo_organic_source_locale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_organic_events');
        Schema::dropIfExists('seo_recommendations');
        Schema::dropIfExists('seo_job_history');
        Schema::dropIfExists('seo_indexnow_submissions');
        Schema::dropIfExists('seo_api_usage');
        Schema::dropIfExists('seo_api_connections');
        Schema::dropIfExists('seo_search_analytics');
        Schema::dropIfExists('seo_audit_issues');
        Schema::dropIfExists('seo_audit_runs');
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('seo_content_translations');
        Schema::dropIfExists('seo_sitemap_shards');
        Schema::dropIfExists('seo_sitemaps');
        Schema::dropIfExists('seo_url_inspections');
        Schema::dropIfExists('seo_url_registry');
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('seo_settings');
    }
};
