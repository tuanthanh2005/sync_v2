<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_badges')) {
            return;
        }

        Schema::table('user_badges', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_badges', 'status')) {
                $table->string('status', 24)->default('active')->after('badge_type')->index();
            }

            if (! Schema::hasColumn('user_badges', 'grant_reason')) {
                $table->string('grant_reason', 255)->nullable()->after('granted_at');
            }

            if (! Schema::hasColumn('user_badges', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('revoke_reason')->index();
            }
        });

        // Create replacement composite index before dropping the old unique index so MySQL FKs stay satisfied.
        if (! Schema::hasIndex('user_badges', 'user_badges_user_badge_idx')) {
            Schema::table('user_badges', function (Blueprint $table): void {
                $table->index(['user_id', 'badge_type'], 'user_badges_user_badge_idx');
            });
        }

        if (! Schema::hasIndex('user_badges', 'user_badges_type_status_idx')) {
            Schema::table('user_badges', function (Blueprint $table): void {
                $table->index(['badge_type', 'status'], 'user_badges_type_status_idx');
            });
        }

        if (Schema::hasIndex('user_badges', 'user_badges_user_id_badge_type_unique')) {
            Schema::table('user_badges', function (Blueprint $table): void {
                $table->dropUnique('user_badges_user_id_badge_type_unique');
            });
        }

        // Backfill status accurately on any pre-existing badge records without altering user privileges.
        DB::table('user_badges')
            ->whereNotNull('revoked_at')
            ->update(['status' => 'revoked']);

        DB::table('user_badges')
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_badges')) {
            return;
        }

        if (Schema::hasIndex('user_badges', 'user_badges_type_status_idx')) {
            Schema::table('user_badges', function (Blueprint $table): void {
                $table->dropIndex('user_badges_type_status_idx');
            });
        }

        Schema::table('user_badges', function (Blueprint $table): void {
            $dropCols = array_values(array_filter(
                ['status', 'grant_reason', 'is_public'],
                static fn (string $column): bool => Schema::hasColumn('user_badges', $column),
            ));

            if ($dropCols !== []) {
                $table->dropColumn($dropCols);
            }
        });
    }
};
