<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedBigInteger('price_usd_cents');
            $table->unsignedBigInteger('price_vnd');
            $table->unsignedBigInteger('promo_price_usd_cents')->nullable();
            $table->unsignedBigInteger('promo_price_vnd')->nullable();
            $table->json('benefits')->nullable();
            $table->string('ad_reduction_mode', 20)->default('reduced');
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('premium_plan_id')->constrained('premium_plans')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->string('source', 24)->default('purchase');
            $table->string('currency', 3)->default('VND');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('reminder_7d_sent_at')->nullable();
            $table->timestamp('reminder_3d_sent_at')->nullable();
            $table->timestamp('reminder_1d_sent_at')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at'], 'subscriptions_user_status_expires_idx');
        });

        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->integer('days_delta')->default(0);
            $table->timestamp('previous_expires_at')->nullable();
            $table->timestamp('new_expires_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_id', 'event_type'], 'sub_events_payment_event_unique');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('banner_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('platform', 16)->default('desktop');
            $table->unsignedSmallInteger('recommended_width')->default(728);
            $table->unsignedSmallInteger('recommended_height')->default(90);
            $table->string('aspect_ratio', 24)->default('728x90');
            $table->string('delivery_mode', 16)->default('exclusive');
            $table->unsignedTinyInteger('max_active_banners')->default(1);
            $table->unsignedBigInteger('price_7d_vnd');
            $table->unsignedBigInteger('price_15d_vnd');
            $table->unsignedBigInteger('price_30d_vnd');
            $table->unsignedBigInteger('price_90d_vnd');
            $table->unsignedBigInteger('price_7d_usd_cents');
            $table->unsignedBigInteger('price_15d_usd_cents');
            $table->unsignedBigInteger('price_30d_usd_cents');
            $table->unsignedBigInteger('price_90d_usd_cents');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
        });

        Schema::create('banner_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banner_placement_id')->constrained('banner_placements')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 140);
            $table->string('business_name', 120)->nullable();
            $table->string('logo_disk', 32)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('desktop_image_disk', 32)->nullable();
            $table->string('desktop_image_path', 255)->nullable();
            $table->string('mobile_image_disk', 32)->nullable();
            $table->string('mobile_image_path', 255)->nullable();
            $table->string('headline', 160)->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('target_url', 500);
            $table->string('alt_text', 180);
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->string('currency', 3)->default('VND');
            $table->unsignedBigInteger('price_amount')->default(0);
            $table->string('payment_status', 24)->default('pending');
            $table->string('status', 24)->default('pending_payment')->index();
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('emergency_paused_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(
                ['banner_placement_id', 'status', 'starts_at', 'ends_at'],
                'banner_campaigns_placement_schedule_idx',
            );
        });

        Schema::create('banner_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('banner_campaign_id')->constrained('banner_campaigns')->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 16);
            $table->string('dedup_hash', 64);
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['banner_campaign_id', 'event_type', 'dedup_hash'], 'banner_events_unique_dedup');
            $table->index(['viewer_id', 'event_type']);
        });

        Schema::create('boost_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('duration_hours');
            $table->unsignedBigInteger('price_usd_cents');
            $table->unsignedBigInteger('price_vnd');
            $table->boolean('is_featured_slot')->default(false);
            $table->unsignedSmallInteger('priority_weight')->default(10);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->string('category', 20)->default('fiat');
            $table->json('supported_currencies');
            $table->unsignedSmallInteger('fee_percent_bps')->default(0);
            $table->unsignedBigInteger('fee_fixed_vnd')->default(0);
            $table->unsignedBigInteger('fee_fixed_usd_cents')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_live_ready')->default(false);
            $table->boolean('requires_legal_clearance')->default(false);
            $table->json('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
        });

        Schema::create('platform_compliance_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 24)->unique();
            $table->boolean('allow_external_web_checkout')->default(true);
            $table->boolean('allow_premium_purchase')->default(true);
            $table->boolean('allow_banner_display')->default(true);
            $table->boolean('allow_boost_purchase')->default(true);
            $table->boolean('require_native_iap_for_digital')->default(false);
            $table->json('allowed_payment_methods');
            $table->string('policy_note', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_compliance_rules');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('boost_packages');
        Schema::dropIfExists('banner_events');
        Schema::dropIfExists('banner_campaigns');
        Schema::dropIfExists('banner_placements');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('premium_plans');
    }
};
