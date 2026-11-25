<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('footer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_page_id')->nullable()->constrained('policy_pages')->cascadeOnDelete();
            $table->string('label_vi', 120);
            $table->string('label_en', 120);
            $table->string('route_name', 120)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('footer_group', 32)->default('legal')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(false)->index();
            $table->boolean('open_in_new_tab')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_visible', 'footer_group', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('footer_links');
    }
};
