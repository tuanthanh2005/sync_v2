<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('comments_locked')->default(false)->after('is_pinned');
            $table->string('content_warning', 160)->nullable()->after('comments_locked');
            $table->boolean('is_spam')->default(false)->after('moderation_status')->index();
            $table->string('moderation_reason', 500)->nullable()->after('is_spam');
            $table->foreignId('moderated_by')->nullable()->after('moderation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->boolean('is_spam')->default(false)->after('moderation_status')->index();
            $table->string('moderation_reason', 500)->nullable()->after('is_spam');
            $table->foreignId('moderated_by')->nullable()->after('moderation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
        });

        Schema::table('communities', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('visibility')->index();
            $table->boolean('is_featured')->default(false)->after('is_verified_badge')->index();
            $table->boolean('allow_new_members')->default(true)->after('is_featured');
            $table->boolean('allow_posting')->default(true)->after('allow_new_members');
            $table->string('moderation_reason', 500)->nullable()->after('allow_posting');
            $table->softDeletes();
        });

        Schema::table('hashtags', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('posts_count')->index();
            $table->boolean('is_restricted')->default(false)->after('is_featured')->index();
            $table->boolean('is_trending_blocked')->default(false)->after('is_restricted');
            $table->boolean('is_spam')->default(false)->after('is_trending_blocked');
        });

        Schema::table('post_polls', function (Blueprint $table): void {
            $table->boolean('is_closed')->default(false)->after('allows_multiple');
            $table->string('moderation_status', 20)->default('approved')->after('is_closed');
            $table->string('moderation_reason', 500)->nullable()->after('moderation_status');
        });

        Schema::table('post_media', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('post_id')->constrained('users')->nullOnDelete();
            $table->string('moderation_status', 20)->default('approved')->after('sort_order')->index();
            $table->string('moderation_reason', 500)->nullable()->after('moderation_status');
            $table->foreignId('moderated_by')->nullable()->after('moderation_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('priority', 16)->default('normal')->after('assigned_to')->index();
            $table->text('internal_note')->nullable()->after('resolution_note');
            $table->json('evidence_snapshot')->nullable()->after('internal_note');
            $table->string('duplicate_group_key', 80)->nullable()->after('evidence_snapshot')->index();
        });

        Schema::create('post_edit_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_title', 180)->nullable();
            $table->text('previous_content');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['post_id', 'created_at']);
        });

        Schema::create('appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sanction_id')->nullable()->constrained('user_sanctions')->nullOnDelete();
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('blocked_keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('keyword', 120)->unique();
            $table->string('match_type', 16)->default('contains');
            $table->string('severity', 16)->default('block');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_keywords');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('post_edit_histories');

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropIndex(['priority']);
            $table->dropIndex(['duplicate_group_key']);
            $table->dropColumn(['priority', 'internal_note', 'evidence_snapshot', 'duplicate_group_key']);
        });

        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropIndex(['moderation_status']);
            $table->dropColumn(['moderation_status', 'moderation_reason']);
        });

        Schema::table('post_polls', function (Blueprint $table): void {
            $table->dropColumn(['is_closed', 'moderation_status', 'moderation_reason']);
        });

        Schema::table('hashtags', function (Blueprint $table): void {
            $table->dropIndex(['is_featured']);
            $table->dropIndex(['is_restricted']);
            $table->dropColumn(['is_featured', 'is_restricted', 'is_trending_blocked', 'is_spam']);
        });

        Schema::table('communities', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['is_featured']);
            $table->dropSoftDeletes();
            $table->dropColumn(['status', 'is_featured', 'allow_new_members', 'allow_posting', 'moderation_reason']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropIndex(['is_spam']);
            $table->dropColumn(['is_spam', 'moderation_reason', 'moderated_at']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropIndex(['is_spam']);
            $table->dropColumn([
                'comments_locked',
                'content_warning',
                'is_spam',
                'moderation_reason',
                'moderated_at',
            ]);
        });
    }
};
