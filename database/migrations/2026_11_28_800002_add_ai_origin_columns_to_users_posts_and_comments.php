<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'account_type')) {
                $table->string('account_type', 24)->default('human')->after('role')->index();
            }
            if (! Schema::hasColumn('users', 'ai_disclosure')) {
                $table->string('ai_disclosure', 255)->nullable()->after('account_type');
            }
        });

        Schema::table('posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('posts', 'content_origin')) {
                $table->string('content_origin', 24)->default('human')->after('is_ai_assisted')->index();
            }
            if (! Schema::hasColumn('posts', 'ai_agent_id')) {
                $table->foreignId('ai_agent_id')->nullable()->after('content_origin')->constrained('ai_agents')->nullOnDelete();
            }
            if (! Schema::hasColumn('posts', 'ai_draft_id')) {
                $table->foreignId('ai_draft_id')->nullable()->after('ai_agent_id')->constrained('ai_content_drafts')->nullOnDelete();
            }
        });

        Schema::table('comments', function (Blueprint $table): void {
            if (! Schema::hasColumn('comments', 'is_ai_generated')) {
                $table->boolean('is_ai_generated')->default(false)->after('moderation_status')->index();
            }
            if (! Schema::hasColumn('comments', 'ai_agent_id')) {
                $table->foreignId('ai_agent_id')->nullable()->after('is_ai_generated')->constrained('ai_agents')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            if (Schema::hasColumn('comments', 'ai_agent_id')) {
                $table->dropConstrainedForeignId('ai_agent_id');
            }
            if (Schema::hasColumn('comments', 'is_ai_generated')) {
                $table->dropColumn('is_ai_generated');
            }
        });

        Schema::table('posts', function (Blueprint $table): void {
            if (Schema::hasColumn('posts', 'ai_draft_id')) {
                $table->dropConstrainedForeignId('ai_draft_id');
            }
            if (Schema::hasColumn('posts', 'ai_agent_id')) {
                $table->dropConstrainedForeignId('ai_agent_id');
            }
            if (Schema::hasColumn('posts', 'content_origin')) {
                $table->dropColumn('content_origin');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $cols = array_filter(['account_type', 'ai_disclosure'], static fn (string $c): bool => Schema::hasColumn('users', $c));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
