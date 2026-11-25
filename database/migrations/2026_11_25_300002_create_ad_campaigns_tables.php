<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('name', 140);
            $table->string('objective', 32);
            $table->json('target_topics')->nullable();
            $table->string('target_url', 500)->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('schedule_mode', 20)->default('immediate');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('budget_type', 20)->default('daily');
            $table->unsignedBigInteger('daily_budget')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(7);
            $table->unsignedBigInteger('total_budget');
            $table->unsignedBigInteger('held_amount')->default(0);
            $table->unsignedBigInteger('spent_amount')->default(0);
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 16);
            $table->string('dedup_hash', 64);
            $table->unsignedBigInteger('cost_vnd')->default(0);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ad_campaign_id', 'event_type', 'dedup_hash'], 'ad_events_unique_dedup');
            $table->index(['ad_campaign_id', 'created_at']);
        });

        Schema::create('ad_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('spent')->default(0);
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_daily_stats');
        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('ad_campaigns');
    }
};
