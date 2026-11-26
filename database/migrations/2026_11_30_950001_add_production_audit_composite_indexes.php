<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive performance composite indexes identified during Production Launch Audit V1.0.
     * Zero destructive alterations; 100% safe for online/shared-hosting migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->index(['user_id', 'status', 'expires_at'], 'payments_user_status_exp_idx');
            });
        }

        if (Schema::hasTable('referrals')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->index(['status', 'eligible_at'], 'referrals_status_eligible_idx');
            });
        }

        if (Schema::hasTable('communities')) {
            Schema::table('communities', function (Blueprint $table): void {
                $table->index(['visibility', 'status', 'members_count'], 'communities_vis_status_members_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropIndex('payments_user_status_exp_idx');
            });
        }

        if (Schema::hasTable('referrals')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->dropIndex('referrals_status_eligible_idx');
            });
        }

        if (Schema::hasTable('communities')) {
            Schema::table('communities', function (Blueprint $table): void {
                $table->dropIndex('communities_vis_status_members_idx');
            });
        }
    }
};
