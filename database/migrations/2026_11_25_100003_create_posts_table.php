<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('community_id')->nullable()->index();
            $table->string('category', 32)->default('general');
            $table->string('title', 180)->nullable();
            $table->text('content');
            $table->string('link_url', 500)->nullable();
            $table->json('link_meta')->nullable();
            $table->string('visibility', 20)->default('public');
            $table->boolean('is_pinned')->default(false);
            $table->string('moderation_status', 20)->default('approved');
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['visibility', 'moderation_status', 'published_at', 'id'], 'posts_feed_idx');
            $table->index(['category', 'visibility', 'moderation_status', 'published_at', 'id'], 'posts_category_feed_idx');
            $table->index(['user_id', 'moderation_status', 'published_at', 'id'], 'posts_author_feed_idx');
            $table->index(['community_id', 'is_pinned', 'moderation_status', 'published_at'], 'posts_community_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
