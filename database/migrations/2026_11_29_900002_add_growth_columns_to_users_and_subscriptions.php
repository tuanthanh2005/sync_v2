<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->foreignId('referred_by_user_id')->nullable()->after('acquisition_locale')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'notification_preferences')) {
                $table->json('notification_preferences')->nullable()->after('referred_by_user_id');
            }
            if (! Schema::hasColumn('users', 'last_reengagement_sent_at')) {
                $table->timestamp('last_reengagement_sent_at')->nullable()->after('last_activity_at');
            }
            if (! Schema::hasColumn('users', 'reengagement_stage')) {
                $table->unsignedTinyInteger('reengagement_stage')->default(0)->after('last_reengagement_sent_at');
            }
            if (! Schema::hasColumn('users', 'is_test_account')) {
                $table->boolean('is_test_account')->default(false)->after('account_type')->index();
            }
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'promotional_days')) {
                $table->unsignedInteger('promotional_days')->default(0)->after('amount_paid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (Schema::hasColumn('subscriptions', 'promotional_days')) {
                $table->dropColumn('promotional_days');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->dropConstrainedForeignId('referred_by_user_id');
            }
            foreach (['notification_preferences', 'last_reengagement_sent_at', 'reengagement_stage', 'is_test_account'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
