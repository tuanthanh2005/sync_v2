<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('premium_until')->nullable()->after('ad_restricted_until')->index();
            $table->timestamp('premium_suspended_at')->nullable()->after('premium_until');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('order_code', 40)->nullable()->unique()->after('id');
            $table->string('service_type', 24)->default('topup')->after('user_id')->index();
            $table->string('payable_type', 120)->nullable()->after('service_type');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
            $table->unsignedBigInteger('fee_amount')->default(0)->after('amount');
            $table->unsignedInteger('exchange_rate_snapshot')->nullable()->after('currency');
            $table->string('platform', 24)->default('web')->after('status');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('expires_at');

            $table->index(['user_id', 'service_type', 'status'], 'payments_user_service_status_idx');
            $table->index(['payable_type', 'payable_id'], 'payments_payable_idx');
        });

        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->foreignId('boost_package_id')->nullable()->after('post_id')->constrained('boost_packages')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->after('boost_package_id')->constrained('payments')->nullOnDelete();
            $table->string('billing_mode', 16)->default('cpc_cpm')->after('budget_type');
            $table->boolean('is_featured_slot')->default(false)->after('billing_mode');
            $table->timestamp('activated_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('boost_package_id');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['billing_mode', 'is_featured_slot', 'activated_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_user_service_status_idx');
            $table->dropIndex('payments_payable_idx');
            $table->dropUnique(['order_code']);
            $table->dropColumn([
                'order_code',
                'service_type',
                'payable_type',
                'payable_id',
                'fee_amount',
                'exchange_rate_snapshot',
                'platform',
                'expires_at',
                'cancelled_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['premium_until', 'premium_suspended_at']);
        });
    }
};
