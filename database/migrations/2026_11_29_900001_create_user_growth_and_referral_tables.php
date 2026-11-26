<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 48)->default('general')->index();
            $table->text('value')->nullable();
            $table->string('type', 24)->default('string');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('growth_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 96)->unique();
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->string('type', 32)->index(); // launch|early_member|referral|premium_reward|community_growth|seasonal|founding_member
            $table->string('status', 24)->default('draft')->index(); // draft|scheduled|active|paused|completed|cancelled
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->string('target_audience', 48)->default('human_members');
            $table->json('target_languages')->nullable();
            $table->json('qualification_rules')->nullable();
            $table->json('reward_config')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('current_participants')->default(0);
            $table->unsignedInteger('max_reward_days_budget')->nullable();
            $table->unsignedInteger('used_reward_days_budget')->default(0);
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('growth_campaign_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('growth_campaigns')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('qualification_rules')->nullable();
            $table->json('reward_config')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campaign_id', 'version_number']);
        });

        Schema::create('growth_campaign_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('growth_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campaign_version_id')->nullable()->constrained('growth_campaign_versions')->nullOnDelete();
            $table->string('status', 24)->default('enrolled')->index();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('rewarded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });

        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 24)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->string('disabled_reason', 255)->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('visits_count')->default(0);
            $table->unsignedInteger('registrations_count')->default(0);
            $table->unsignedInteger('qualified_count')->default(0);
            $table->timestamps();
        });

        Schema::create('referral_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
            $table->string('visitor_hash', 64)->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('landing_path', 255)->nullable();
            $table->string('referer_host', 160)->nullable();
            $table->string('locale', 16)->default('en');
            $table->boolean('is_bot')->default(false);
            $table->timestamp('visited_at')->useCurrent()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->unique(['referral_code_id', 'visitor_hash']);
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $table->foreignId('campaign_version_id')->nullable()->constrained('growth_campaign_versions')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index(); // pending|under_review|qualified|rejected|rewarded|revoked
            $table->string('status_reason', 255)->nullable();
            $table->string('attribution_source', 40)->default('referral');
            $table->string('landing_path', 255)->nullable();
            $table->string('locale', 16)->default('en');
            $table->string('ip_hash', 64)->nullable()->index();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('risk_signals')->nullable();
            $table->timestamp('eligible_at')->nullable()->index();
            $table->timestamp('qualified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });

        Schema::create('referral_qualification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['referral_id', 'created_at']);
        });

        Schema::create('referral_reward_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('required_qualified_referrals')->index();
            $table->unsignedInteger('cumulative_premium_days');
            $table->boolean('is_repeatable')->default(false);
            $table->unsignedInteger('max_claims_per_user')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('referral_reward_rules')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedInteger('claim_index')->default(1);
            $table->unsignedInteger('milestone_referrals')->default(0);
            $table->unsignedInteger('days_granted');
            $table->unsignedInteger('cumulative_days_target')->default(0);
            $table->string('source', 32)->default('referral_reward')->index();
            $table->string('status', 24)->default('granted')->index(); // granted|held|revoked
            $table->string('note', 255)->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'rule_id', 'claim_index']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('early_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('member_number')->unique();
            $table->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('status', 24)->default('active')->index(); // active|revoked
            $table->unsignedInteger('premium_days_granted')->default(0);
            $table->timestamp('qualified_at')->useCurrent();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('badge_type', 40)->index(); // early_member|founding_member|official_account|referral_ambassador|community_builder
            $table->string('label_key', 96);
            $table->string('icon', 40)->default('award');
            $table->string('color', 32)->default('amber');
            $table->string('grant_source', 40)->default('system');
            $table->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'badge_type']);
        });

        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 255);
            $table->string('icon', 40)->default('trophy');
            $table->string('color', 32)->default('indigo');
            $table->string('criteria_type', 48)->index();
            $table->json('criteria_config')->nullable();
            $table->unsignedInteger('reward_premium_days')->default(0);
            $table->string('grants_badge_type', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained('achievements')->cascadeOnDelete();
            $table->string('status', 24)->default('unlocked')->index(); // unlocked|revoked
            $table->timestamp('unlocked_at')->useCurrent();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);
        });

        Schema::create('onboarding_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('current_step', 40)->default('interests');
            $table->json('completed_steps')->nullable();
            $table->json('selected_interests')->nullable();
            $table->string('selected_locale', 16)->nullable();
            $table->string('return_to_url', 255)->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('milestone', 48)->index();
            $table->string('source', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();

            $table->unique(['user_id', 'milestone']);
        });

        Schema::create('growth_analytics', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('total_human_users')->default(0);
            $table->unsignedInteger('new_human_registrations')->default(0);
            $table->unsignedInteger('verified_human_users')->default(0);
            $table->unsignedInteger('activated_users')->default(0);
            $table->unsignedInteger('dau_human')->default(0);
            $table->unsignedInteger('wau_human')->default(0);
            $table->unsignedInteger('mau_human')->default(0);
            $table->unsignedInteger('referral_registrations')->default(0);
            $table->unsignedInteger('qualified_referrals')->default(0);
            $table->unsignedInteger('organic_registrations')->default(0);
            $table->decimal('d1_retention_rate', 5, 2)->default(0);
            $table->decimal('d7_retention_rate', 5, 2)->default(0);
            $table->decimal('d30_retention_rate', 5, 2)->default(0);
            $table->unsignedInteger('premium_rewards_granted_days')->default(0);
            $table->unsignedInteger('promotional_premium_users')->default(0);
            $table->unsignedInteger('paid_premium_users')->default(0);
            $table->json('metrics_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('growth_fraud_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('signals')->nullable();
            $table->string('status', 24)->default('pending')->index(); // pending|approved|rejected|appealed
            $table->text('decision_note')->nullable();
            $table->text('appeal_note')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('growth_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 96)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_activity_logs');
        Schema::dropIfExists('growth_fraud_reviews');
        Schema::dropIfExists('growth_analytics');
        Schema::dropIfExists('user_activation_events');
        Schema::dropIfExists('onboarding_progress');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('early_members');
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_reward_rules');
        Schema::dropIfExists('referral_qualification_events');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_visits');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('growth_campaign_participants');
        Schema::dropIfExists('growth_campaign_versions');
        Schema::dropIfExists('growth_campaigns');
        Schema::dropIfExists('growth_settings');
    }
};
