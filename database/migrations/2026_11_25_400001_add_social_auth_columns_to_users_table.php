<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('social_provider', 30)->nullable()->after('timezone');
            $table->string('social_provider_id', 100)->nullable()->after('social_provider');
            $table->string('avatar_url')->nullable()->after('social_provider_id');

            $table->index(['social_provider', 'social_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['social_provider', 'social_provider_id']);
            $table->dropColumn(['social_provider', 'social_provider_id', 'avatar_url']);
        });
    }
};
