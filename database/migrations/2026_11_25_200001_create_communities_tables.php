<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('category', 32)->default('general')->index();
            $table->text('description')->nullable();
            $table->string('avatar_disk', 32)->nullable();
            $table->string('avatar_path', 255)->nullable();
            $table->string('cover_disk', 32)->nullable();
            $table->string('cover_path', 255)->nullable();
            $table->json('rules')->nullable();
            $table->string('visibility', 20)->default('public');
            $table->boolean('is_verified_badge')->default(false);
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->index(['visibility', 'members_count'], 'communities_featured_idx');
        });

        Schema::create('community_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->unsignedInteger('points')->default(0);
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['community_id', 'user_id']);
            $table->index(['community_id', 'points'], 'community_leaderboard_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
        Schema::dropIfExists('communities');
    }
};
