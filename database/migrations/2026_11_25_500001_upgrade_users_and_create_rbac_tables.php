<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('hierarchy_level')->default(10);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 48)->index();
            $table->string('action', 32);
            $table->string('slug', 96)->unique();
            $table->string('label', 120);
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('rbac_role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
            $table->string('preferred_locale', 12)->nullable()->after('timezone');
            $table->timestamp('last_login_at')->nullable()->after('preferred_locale')->index();
            $table->timestamp('last_activity_at')->nullable()->after('last_login_at')->index();
            $table->timestamp('suspended_until')->nullable()->after('status')->index();
            $table->string('ban_reason', 500)->nullable()->after('suspended_until');
            $table->unsignedInteger('warnings_count')->default(0)->after('ban_reason');
            $table->unsignedInteger('violations_count')->default(0)->after('warnings_count');
            $table->boolean('can_post')->default(true)->after('violations_count');
            $table->boolean('can_comment')->default(true)->after('can_post');
            $table->boolean('can_create_community')->default(true)->after('can_comment');
            $table->boolean('can_message')->default(true)->after('can_create_community');
            $table->boolean('can_advertise')->default(true)->after('can_message');
            $table->timestamp('post_restricted_until')->nullable()->after('can_advertise');
            $table->timestamp('comment_restricted_until')->nullable()->after('post_restricted_until');
            $table->timestamp('community_restricted_until')->nullable()->after('comment_restricted_until');
            $table->timestamp('message_restricted_until')->nullable()->after('community_restricted_until');
            $table->timestamp('ad_restricted_until')->nullable()->after('message_restricted_until');
            $table->timestamp('deactivated_at')->nullable()->after('ad_restricted_until');
            $table->softDeletes();
        });

        Schema::create('user_sanctions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->text('reason');
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'ends_at'], 'user_sanctions_active_idx');
            $table->index(['type', 'created_at']);
        });

        Schema::create('verification_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('category', 40)->default('creator');
            $table->text('reason');
            $table->text('evidence_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('user_sanctions');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rbac_role_id');
            $table->dropIndex(['last_login_at']);
            $table->dropIndex(['last_activity_at']);
            $table->dropIndex(['suspended_until']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'preferred_locale',
                'last_login_at',
                'last_activity_at',
                'suspended_until',
                'ban_reason',
                'warnings_count',
                'violations_count',
                'can_post',
                'can_comment',
                'can_create_community',
                'can_message',
                'can_advertise',
                'post_restricted_until',
                'comment_restricted_until',
                'community_restricted_until',
                'message_restricted_until',
                'ad_restricted_until',
                'deactivated_at',
            ]);
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
