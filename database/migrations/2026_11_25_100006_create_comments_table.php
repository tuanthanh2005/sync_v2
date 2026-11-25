<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->text('content');
            $table->string('moderation_status', 20)->default('approved');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'moderation_status', 'parent_id', 'id'], 'comments_post_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
