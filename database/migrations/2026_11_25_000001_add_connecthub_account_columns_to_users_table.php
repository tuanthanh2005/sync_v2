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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->unique()->after('name');
            $table->string('role', 20)->default('user')->after('email');
            $table->string('status', 20)->default('active')->after('role');
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh')->after('status');

            $table->index('role');
            $table->index('status');
            $table->index(['status', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'role']);
            $table->dropIndex(['status']);
            $table->dropIndex(['role']);
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role', 'status', 'timezone']);
        });
    }
};
