<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_credit_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('promo_balance')->default(0)->after('balance');
        });

        Schema::table('ad_credit_entries', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->after('ad_campaign_id')->constrained('users')->nullOnDelete();
            $table->string('wallet_bucket', 16)->default('paid')->after('type');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_amount')->default(0)->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->string('refund_reason', 255)->nullable()->after('refunded_at');
            $table->foreignId('refunded_by')->nullable()->after('refund_reason')->constrained('users')->nullOnDelete();
        });

        Schema::create('payment_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->index();
            $table->string('event_id', 120)->nullable();
            $table->string('status', 24)->index();
            $table->boolean('signature_valid')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->json('payload_summary')->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 32)->index();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->boolean('is_public')->default(false)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('native_name', 80);
            $table->string('english_name', 80);
            $table->string('flag_code', 16);
            $table->boolean('is_rtl')->default(false);
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->unsignedTinyInteger('completion_percent')->default(100);
            $table->unsignedInteger('missing_keys_count')->default(0);
            $table->timestamps();
        });

        Schema::create('translation_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('language_code', 16)->index();
            $table->string('translation_key', 180);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['language_code', 'translation_key'], 'translation_overrides_unique');
        });

        Schema::create('translation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('language_code', 16)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->unsignedInteger('keys_count')->default(0);
            $table->string('summary', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 32)->default('all');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 32)->default('system');
            $table->string('title', 180);
            $table->text('body');
            $table->string('action_url', 500)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('category', 32)->default('system');
            $table->string('subject_template', 200);
            $table->text('body_template');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64);
            $table->string('status', 20)->default('completed');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('translation_revisions');
        Schema::dropIfExists('translation_overrides');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('payment_webhook_logs');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_amount', 'refunded_at', 'refund_reason']);
        });

        Schema::table('ad_credit_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn(['wallet_bucket']);
        });

        Schema::table('ad_credit_accounts', function (Blueprint $table): void {
            $table->dropColumn('promo_balance');
        });
    }
};
