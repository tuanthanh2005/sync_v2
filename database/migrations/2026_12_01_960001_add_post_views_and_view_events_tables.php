<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('views_count')->default(0)->after('shares_count');
            $table->unsignedBigInteger('organic_views_count')->default(0)->after('views_count');
            $table->unsignedBigInteger('promoted_views_count')->default(0)->after('organic_views_count');
            $table->unsignedBigInteger('unique_viewers_count')->default(0)->after('promoted_views_count');
            $table->index('views_count', 'posts_views_count_idx');
        });

        Schema::create('post_view_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('viewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('viewer_hash', 64);
            $table->string('source', 24)->default('feed');
            $table->boolean('is_promoted')->default(false);
            $table->string('viewed_bucket', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['post_id', 'viewer_hash', 'viewed_bucket'], 'post_view_events_dedup_unique');
            $table->index(['post_id', 'viewer_hash'], 'post_view_events_unique_viewer_idx');
            $table->index('created_at', 'post_view_events_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_view_events');

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_views_count_idx');
            $table->dropColumn([
                'views_count',
                'organic_views_count',
                'promoted_views_count',
                'unique_viewers_count',
            ]);
        });
    }
};
