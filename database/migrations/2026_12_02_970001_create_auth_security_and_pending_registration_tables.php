<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pending_registrations')) {
            Schema::create('pending_registrations', function (Blueprint $table): void {
                $table->id();
                $table->char('public_token', 64)->unique();
                $table->string('name', 120);
                $table->string('username', 50)->index();
                $table->string('email', 191)->index();
                $table->string('normalized_email', 191)->index();
                $table->char('canonical_fingerprint', 64)->nullable()->index();
                $table->text('password_hash');
                $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
                $table->string('preferred_locale', 12)->default('en');
                $table->string('referral_code', 32)->nullable();
                $table->json('acquisition_metadata')->nullable();
                $table->string('ip_address', 45)->nullable()->index();
                $table->char('user_agent_hash', 64)->nullable();
                $table->unsignedSmallInteger('risk_score')->default(0);
                $table->json('risk_signals')->nullable();
                $table->string('status', 24)->default('pending')->index();
                $table->timestamp('expires_at')->index();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['normalized_email', 'status'], 'pending_reg_email_status_idx');
                $table->index(['status', 'expires_at'], 'pending_reg_status_expires_idx');
            });
        }

        if (! Schema::hasTable('auth_verification_challenges')) {
            Schema::create('auth_verification_challenges', function (Blueprint $table): void {
                $table->id();
                $table->string('purpose', 32)->index();
                $table->foreignId('pending_registration_id')->nullable()->constrained('pending_registrations')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('email', 191)->index();
                $table->char('otp_hmac', 64);
                $table->char('link_token_hash', 64)->unique();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('max_attempts')->default(5);
                $table->unsignedSmallInteger('resend_count')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('link_expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['purpose', 'email', 'used_at'], 'auth_chal_purpose_email_used_idx');
                $table->index(['pending_registration_id', 'invalidated_at'], 'auth_chal_pending_inv_idx');
                $table->index(['user_id', 'purpose', 'used_at'], 'auth_chal_user_purpose_idx');
            });
        }

        if (! Schema::hasTable('auth_security_events')) {
            Schema::create('auth_security_events', function (Blueprint $table): void {
                $table->id();
                $table->string('event_type', 48)->index();
                $table->string('severity', 16)->default('info')->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->char('email_hash', 64)->nullable()->index();
                $table->string('email_masked', 191)->nullable();
                $table->string('ip_address', 45)->nullable()->index();
                $table->unsignedSmallInteger('risk_score')->default(0);
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['event_type', 'created_at'], 'auth_sec_evt_type_created_idx');
                $table->index(['severity', 'created_at'], 'auth_sec_evt_sev_created_idx');
            });
        }

        if (! Schema::hasTable('disposable_email_domains')) {
            Schema::create('disposable_email_domains', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 191)->unique();
                $table->boolean('is_active')->default(true)->index();
                $table->string('notes', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_verification_challenges');
        Schema::dropIfExists('pending_registrations');
        Schema::dropIfExists('auth_security_events');
        Schema::dropIfExists('disposable_email_domains');
    }
};
