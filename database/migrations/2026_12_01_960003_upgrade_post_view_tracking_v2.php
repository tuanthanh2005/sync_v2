<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('human_views_count')->default(0)->after('unique_viewers_count');
            $table->unsignedBigInteger('guest_views_count')->default(0)->after('human_views_count');
            $table->unsignedBigInteger('ai_views_count')->default(0)->after('guest_views_count');
            $table->unsignedBigInteger('ai_content_accesses_count')->default(0)->after('ai_views_count');
            $table->unsignedBigInteger('legacy_views_count')->default(0)->after('ai_content_accesses_count');
            $table->unsignedBigInteger('invalid_views_count')->default(0)->after('legacy_views_count');
            $table->unsignedBigInteger('unique_human_viewers_count')->default(0)->after('invalid_views_count');
        });

        // Preserve historical pre-V2.0 view counts honestly as Legacy / Unknown
        // without ever resetting views_count or fabricating human attribution.
        DB::table('posts')
            ->where('views_count', '>', 0)
            ->where('human_views_count', 0)
            ->where('guest_views_count', 0)
            ->where('ai_views_count', 0)
            ->where('ai_content_accesses_count', 0)
            ->where('legacy_views_count', 0)
            ->update([
                'legacy_views_count' => DB::raw('views_count'),
            ]);

        Schema::table('post_view_events', function (Blueprint $table): void {
            $table->foreignId('ai_agent_id')
                ->nullable()
                ->after('viewer_user_id')
                ->constrained('ai_agents')
                ->nullOnDelete();
            $table->string('viewer_type', 24)->default('legacy')->after('viewer_hash');
            $table->unsignedInteger('visible_ms')->nullable()->after('source');
            $table->unsignedTinyInteger('visible_ratio')->nullable()->after('visible_ms');
            $table->boolean('is_valid')->default(true)->after('is_promoted');

            $table->index(['post_id', 'viewer_hash', 'created_at'], 'post_view_events_sliding_dedup_idx');
            $table->index(['viewer_type', 'created_at'], 'post_view_events_type_created_idx');
            $table->index(['post_id', 'viewer_type', 'viewer_hash'], 'post_view_events_unique_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_view_events', function (Blueprint $table): void {
            $table->dropIndex('post_view_events_sliding_dedup_idx');
            $table->dropIndex('post_view_events_type_created_idx');
            $table->dropIndex('post_view_events_unique_type_idx');
            $table->dropConstrainedForeignId('ai_agent_id');
            $table->dropColumn([
                'viewer_type',
                'visible_ms',
                'visible_ratio',
                'is_valid',
            ]);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn([
                'human_views_count',
                'guest_views_count',
                'ai_views_count',
                'ai_content_accesses_count',
                'legacy_views_count',
                'invalid_views_count',
                'unique_human_viewers_count',
            ]);
        });
    }
};
