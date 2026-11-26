<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 100);
            $table->string('driver', 40)->default('gemini');
            $table->string('base_url', 255);
            $table->boolean('is_enabled')->default(true);
            $table->string('compliance_status', 40)->default('requires_review');
            $table->text('compliance_notes')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('project_id', 120)->default('default-project')->index();
            $table->longText('api_key_encrypted');
            $table->char('key_fingerprint', 64)->unique();
            $table->string('key_last4', 8);
            $table->json('allowed_models')->nullable();
            $table->string('billing_tier', 32)->default('free');
            $table->unsignedInteger('rpm_limit')->default(10);
            $table->unsignedInteger('tpm_limit')->default(250000);
            $table->unsignedInteger('rpd_limit')->default(250);
            $table->unsignedBigInteger('monthly_budget_cents')->default(0);
            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('health_status', 32)->default('untested')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('cooldown_until')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider_id', 'is_enabled', 'priority'], 'ai_credentials_route_idx');
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_id', 100);
            $table->string('display_name', 120);
            $table->string('role_group', 32)->default('fast')->index();
            $table->json('capabilities')->nullable();
            $table->unsignedInteger('max_input_tokens')->default(1048576);
            $table->unsignedInteger('max_output_tokens')->default(8192);
            $table->decimal('default_temperature', 3, 2)->default(0.70);
            $table->boolean('supports_thinking')->default(true);
            $table->boolean('supports_structured_output')->default(true);
            $table->boolean('supports_grounding')->default(false);
            $table->boolean('is_paid_only')->default(false);
            $table->unsignedInteger('input_price_per_million_cents')->default(0);
            $table->unsignedInteger('output_price_per_million_cents')->default(0);
            $table->boolean('pricing_verified')->default(false);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(['provider_id', 'model_id'], 'ai_models_provider_model_unique');
        });

        Schema::create('ai_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('username', 60)->unique();
            $table->string('ai_disclosure', 255)->default('Official SyncViet AI Agent — Automated Assistant');
            $table->longText('system_prompt');
            $table->string('personality', 180)->nullable();
            $table->string('writing_style', 180)->nullable();
            $table->string('expertise', 255)->nullable();
            $table->string('default_language', 12)->default('en');
            $table->json('supported_languages')->nullable();
            $table->json('assigned_community_ids')->nullable();
            $table->json('allowed_topics')->nullable();
            $table->json('restricted_topics')->nullable();
            $table->foreignId('preferred_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('posting_schedule')->nullable();
            $table->unsignedSmallInteger('daily_post_limit')->default(2);
            $table->unsignedSmallInteger('daily_comment_limit')->default(5);
            $table->unsignedBigInteger('monthly_token_budget')->default(500000);
            $table->boolean('is_enabled')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 140);
            $table->string('prompt_type', 40)->index();
            $table->foreignId('agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->longText('system_instruction');
            $table->longText('user_template');
            $table->json('allowed_variables')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('ai_prompt_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->longText('system_instruction');
            $table->longText('user_template');
            $table->string('changelog', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version_number'], 'ai_prompt_versions_unique');
        });

        Schema::create('ai_content_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255);
            $table->string('url', 1024);
            $table->char('url_hash', 64)->unique();
            $table->string('publisher', 140)->nullable();
            $table->string('source_type', 32)->default('documentation');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('verification_status', 32)->default('pending')->index();
            $table->longText('excerpt')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_content_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('campaign_name', 120)->nullable();
            $table->string('series_name', 120)->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->foreignId('community_id')->nullable()->constrained('communities')->nullOnDelete();
            $table->string('topic', 180);
            $table->string('category', 32)->default('technology');
            $table->string('target_language', 12)->default('en');
            $table->string('content_type', 40)->default('knowledge');
            $table->string('target_audience', 180)->nullable();
            $table->string('goal', 255)->nullable();
            $table->unsignedSmallInteger('posts_per_day')->default(1);
            $table->json('schedule_slots')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('min_interval_minutes')->default(180);
            $table->unsignedSmallInteger('priority')->default(50);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('last_generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_content_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('ai_content_plans')->nullOnDelete();
            $table->foreignId('community_id')->nullable()->constrained('communities')->nullOnDelete();
            $table->foreignId('prompt_template_id')->nullable()->constrained('ai_prompt_templates')->nullOnDelete();
            $table->unsignedInteger('prompt_version_number')->nullable();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('ai_content_sources')->nullOnDelete();
            $table->foreignId('published_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('title', 255);
            $table->longText('body');
            $table->text('summary')->nullable();
            $table->string('language_code', 12)->default('en')->index();
            $table->string('content_type', 40)->default('knowledge');
            $table->string('category', 32)->default('technology');
            $table->json('suggested_tags')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->json('source_references')->nullable();
            $table->json('factual_claims')->nullable();
            $table->json('review_flags')->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->string('quality_status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('quality_score')->default(0);
            $table->json('quality_report')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thinking_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for'], 'ai_drafts_status_sched_idx');
        });

        Schema::create('ai_content_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('ai_content_drafts')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32);
            $table->text('notes')->nullable();
            $table->char('draft_content_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_content_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('ai_content_drafts')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->timestamp('scheduled_for')->index();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 32)->default('scheduled')->index();
            $table->foreignId('published_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'status'], 'ai_schedules_draft_status_unique');
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('project_id', 120)->nullable()->index();
            $table->string('task_type', 40)->index();
            $table->string('language_code', 12)->nullable();
            $table->string('status', 24)->default('success')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thinking_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedSmallInteger('tool_calls')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->boolean('pricing_verified')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ai_quota_tracking', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('project_id', 120);
            $table->string('model_id', 100);
            $table->string('window_type', 16);
            $table->string('window_key', 32);
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedBigInteger('tokens_count')->default(0);
            $table->timestamp('waiting_for_quota_until')->nullable()->index();
            $table->timestamp('last_rate_limited_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_id', 'project_id', 'model_id', 'window_type', 'window_key'],
                'ai_quota_project_model_window_unique',
            );
        });

        Schema::create('ai_reply_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 32)->default('manual_draft');
            $table->string('language_code', 12)->default('en');
            $table->longText('draft_reply')->nullable();
            $table->foreignId('published_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->string('status', 32)->default('pending_review')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_moderation_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('risk_category', 48);
            $table->string('risk_level', 24)->index();
            $table->text('reason');
            $table->string('suggested_action', 32);
            $table->boolean('review_required')->default(true);
            $table->string('status', 24)->default('pending_human_review')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'ai_mod_entity_idx');
        });

        Schema::create('ai_automation_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 40)->default('general')->index();
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_activity_logs');
        Schema::dropIfExists('ai_automation_settings');
        Schema::dropIfExists('ai_moderation_assessments');
        Schema::dropIfExists('ai_reply_tasks');
        Schema::dropIfExists('ai_quota_tracking');
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_content_schedules');
        Schema::dropIfExists('ai_content_reviews');
        Schema::dropIfExists('ai_content_drafts');
        Schema::dropIfExists('ai_content_plans');
        Schema::dropIfExists('ai_content_sources');
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_prompt_templates');
        Schema::dropIfExists('ai_agents');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_credentials');
        Schema::dropIfExists('ai_providers');
    }
};
