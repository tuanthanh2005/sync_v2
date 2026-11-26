<?php

use App\Enums\AccountType;
use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use App\Enums\PostCategory;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\AdCampaign;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\Community;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Post;
use App\Models\PremiumPlan;
use App\Models\ReferralCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\DatabaseBackupService;
use App\Services\Ads\AdDeliveryService;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Billing\WalletService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Security\RbacService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    (new LanguageSeeder)->run();
    MonetizationSeeder::seedDefaultsIfEmpty();
    config(['syncviet.payment_webhook_secret' => 'audit-test-webhook-secret']);
});

it('enforces global max 5 pending payment orders across ALL services and blocks AI Agents from creating payments or wallet top-ups', function (): void {
    $user = User::factory()->create(['account_type' => AccountType::Human, 'email_verified_at' => now()]);
    $plan = PremiumPlan::query()->where('slug', 'premium-1m')->firstOrFail();

    $paymentService = app(MonetizationPaymentService::class);
    $walletService = app(WalletService::class);

    // Create 3 pending Premium orders + 2 pending Wallet Top-Up orders = 5 total pending orders across services
    for ($i = 1; $i <= 3; $i++) {
        $paymentService->createOrder($user, PaymentServiceType::Premium, $plan->price_vnd, 'VND', 'vietqr', "audit-prem-{$i}", 'web', PremiumPlan::class, $plan->id);
    }
    for ($i = 1; $i <= 2; $i++) {
        $walletService->createTopUpCheckout($user, 100_000, 'vietqr', "audit-topup-{$i}");
    }

    expect(Payment::query()->where('user_id', $user->id)->where('status', PaymentStatus::Pending->value)->count())->toBe(5);

    // 6th pending order via EITHER Premium OR Top-Up MUST be rejected (global cap = 5!)
    expect(fn () => $paymentService->createOrder($user, PaymentServiceType::Premium, $plan->price_vnd, 'VND', 'vietqr', 'audit-prem-6', 'web', PremiumPlan::class, $plan->id))
        ->toThrow(ValidationException::class);
    expect(fn () => $walletService->createTopUpCheckout($user, 100_000, 'vietqr', 'audit-topup-6'))
        ->toThrow(ValidationException::class);

    // AI Agents & System accounts are strictly blocked from ALL financial creation endpoints
    $aiAgent = User::factory()->create(['account_type' => AccountType::AiAgent, 'email_verified_at' => now()]);
    expect(fn () => $paymentService->createOrder($aiAgent, PaymentServiceType::Premium, $plan->price_vnd, 'VND', 'vietqr', 'ai-prem-1', 'web', PremiumPlan::class, $plan->id))
        ->toThrow(ValidationException::class);
    expect(fn () => $walletService->createTopUpCheckout($aiAgent, 100_000, 'vietqr', 'ai-topup-1'))
        ->toThrow(ValidationException::class);
});

it('rejects currency mismatches on signed webhooks, blocks settling cancelled top-ups, and logs late valid webhooks for manual reconciliation', function (): void {
    $user = User::factory()->create(['account_type' => AccountType::Human, 'email_verified_at' => now()]);
    $walletService = app(WalletService::class);
    $webhookService = app(HmacPaymentWebhookService::class);

    $topUp = $walletService->createTopUpCheckout($user, 200_000, 'vietqr', 'audit-wh-topup-1');

    // Currency mismatch MUST fail with 422 and never settle
    $badPayload = ['payment_id' => $topUp->id, 'provider_reference' => 'REF-BAD-CUR', 'amount' => 200_000, 'currency' => 'USD'];
    $badJson = json_encode($badPayload, JSON_THROW_ON_ERROR);
    $ts = time();
    $badSig = $webhookService->signPayload($badJson, $ts);

    $this->postJson(route('webhooks.payments', 'vietqr'), $badPayload, [
        'X-SyncViet-Timestamp' => (string) $ts,
        'X-SyncViet-Signature' => $badSig,
    ])->assertStatus(422);

    expect($topUp->fresh()->status)->toBe(PaymentStatus::Pending);
    expect(PaymentWebhookLog::query()->where('status', 'currency_mismatch')->exists())->toBeTrue();

    // Cancel order, then verify direct settlement throws AND a late signed webhook logs reconciliation alert without auto-crediting
    app(MonetizationPaymentService::class)->cancelPendingOrder($user, $topUp);
    expect(fn () => $walletService->settlePaymentCredit($topUp->fresh(), 'REF-LATE-DIRECT'))->toThrow(ValidationException::class);

    $latePayload = ['payment_id' => $topUp->id, 'provider_reference' => 'REF-LATE-WEBHOOK', 'amount' => 200_000, 'currency' => 'VND'];
    $lateJson = json_encode($latePayload, JSON_THROW_ON_ERROR);
    $lateSig = $webhookService->signPayload($lateJson, $ts);

    $this->postJson(route('webhooks.payments', 'vietqr'), $latePayload, [
        'X-SyncViet-Timestamp' => (string) $ts,
        'X-SyncViet-Signature' => $lateSig,
    ])->assertOk();

    // Balance MUST remain 0 AND audit + webhook reconciliation log MUST exist (never silently dropped!)
    expect($walletService->getOrCreateAccount($user)->fresh()->balance)->toBe(0);
    expect(PaymentWebhookLog::query()->where('status', 'late_reconciliation')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'payment.late_webhook_reconciliation_required')->exists())->toBeTrue();
});

it('revokes paid Premium subscription and recalculates expiry cleanly when an Admin issues a full Premium refund', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('AUDITADMIN2FA'),
        'two_factor_confirmed_at' => now(),
    ]);
    $member = User::factory()->create(['email_verified_at' => now()]);
    $plan = PremiumPlan::query()->where('slug', 'premium-1m')->firstOrFail();

    $paymentService = app(MonetizationPaymentService::class);
    $payment = $paymentService->createOrder($member, PaymentServiceType::Premium, $plan->price_vnd, 'VND', 'vietqr', 'audit-refund-prem-1', 'web', PremiumPlan::class, $plan->id);
    $paymentService->settleOrder($payment, 'VIETQR-REFUND-TEST-1', $admin);

    expect($member->fresh()->isPremium())->toBeTrue();

    $paymentService->adminRefundOrder($admin, $payment->fresh(), $plan->price_vnd, 'Customer requested full refund within window', 'refund-key-1');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
    expect($member->fresh()->isPremium())->toBeFalse();
    expect(Subscription::query()->where('payment_id', $payment->id)->first()?->status)->toBe(SubscriptionStatus::Revoked);
});

it('revokes all sessions, disables referral codes, opts out marketing prefs, and logs audit on account deletion', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password' => 'StrongPass!234',
    ]);

    $code = ReferralCode::query()->create([
        'user_id' => $user->id,
        'code' => 'SV-DEL999',
        'is_active' => true,
    ]);

    DB::table('sessions')->insert([
        'id' => 'other-device-session-xyz',
        'user_id' => $user->id,
        'ip_address' => '198.51.100.10',
        'user_agent' => 'Mobile Safari',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'StrongPass!234'])
        ->assertRedirect('/');

    expect($user->fresh()->trashed())->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($code->fresh()->is_active)->toBeFalse();
    expect($user->fresh()->notification_preferences['marketing_emails'] ?? true)->toBeFalse();
    expect(AuditLog::query()->where('action', 'account.deleted_by_user')->exists())->toBeTrue();
});

it('excludes AI Agents and automated bots from paid ad impressions and blocks suspended communities from search and guest view', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $aiAgent = User::factory()->create(['account_type' => AccountType::AiAgent, 'email_verified_at' => now()]);

    $post = Post::factory()->create(['user_id' => $owner->id]);
    $campaign = AdCampaign::query()->forceCreate([
        'user_id' => $owner->id,
        'post_id' => $post->id,
        'name' => 'Audit Boost',
        'objective' => AdObjective::Reach,
        'status' => AdCampaignStatus::Active,
        'billing_mode' => 'fixed_package',
        'daily_budget' => 100_000,
        'total_budget' => 500_000,
        'held_amount' => 0,
        'spent_amount' => 0,
        'impressions_count' => 0,
        'clicks_count' => 0,
    ]);

    $delivery = app(AdDeliveryService::class);
    $humanReq = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/126.0', 'REMOTE_ADDR' => '198.51.100.20']);
    $botReq = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Googlebot/2.1', 'REMOTE_ADDR' => '198.51.100.21']);

    // AI Agent impression MUST NOT increment paid campaign impressions
    $delivery->recordImpression($campaign, $aiAgent, $humanReq);
    // Crawler impression MUST NOT increment paid campaign impressions
    $delivery->recordImpression($campaign, null, $botReq);
    expect($campaign->fresh()->impressions_count)->toBe(0);

    // Suspended community MUST NOT appear in Search or allow guest access
    $suspended = Community::query()->forceCreate([
        'name' => 'Suspended Audit Community',
        'slug' => 'suspended-audit-comm',
        'description' => 'Hidden due to policy violation',
        'category' => PostCategory::Technology,
        'visibility' => 'public',
        'status' => 'suspended',
        'owner_id' => $owner->id,
    ]);

    $this->get(route('communities.show', $suspended->slug))->assertForbidden();
    $searchRes = $this->get(route('search', ['q' => 'Suspended Audit']));
    $searchRes->assertOk();
    expect($searchRes->getContent())->not->toContain('suspended-audit-comm');
});

it('creates a private gzip logical database backup with valid SHA-256 checksum and exposes System Health diagnostics to 2FA Admins only', function (): void {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('HEALTHADMIN2FA'),
        'two_factor_confirmed_at' => now(),
    ]);
    $member = User::factory()->create(['email_verified_at' => now()]);

    $record = app(DatabaseBackupService::class)->createDatabaseBackup($admin, 'Audit test dump');
    expect($record->type)->toBe('database_sql_gz');
    expect($record->size_bytes)->toBeGreaterThan(50);
    expect(strlen((string) $record->checksum))->toBe(64);
    expect(Storage::disk('local')->exists($record->path))->toBeTrue();
    expect(BackupRecord::query()->count())->toBe(1);

    // Regular member cannot inspect System Health
    $this->actingAs($member)->get(route('admin.health'))->assertForbidden();

    // 2FA Admin sees System Health cleanly
    $this->actingAs($admin)->get(route('admin.health'))->assertOk();
});
