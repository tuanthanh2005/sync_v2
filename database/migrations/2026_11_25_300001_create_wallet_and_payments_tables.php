<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('VND');
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('held_balance')->default(0);
            $table->unsignedBigInteger('total_deposited')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_reference', 120)->nullable()->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('VND');
            $table->string('status', 20)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('ad_credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('ad_credit_accounts')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('ad_campaign_id')->nullable()->index();
            $table->string('type', 24);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->unsignedBigInteger('held_after');
            $table->string('idempotency_key', 120)->unique();
            $table->string('description', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_credit_entries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('ad_credit_accounts');
    }
};
