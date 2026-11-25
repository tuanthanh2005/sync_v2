<?php

use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\CreditEntryType;
use App\Enums\UserRole;
use App\Models\AdCampaign;
use App\Models\AdCreditAccount;
use App\Models\AdCreditEntry;
use App\Models\Payment;
use App\Models\Post;
use App\Models\User;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('wallet enforces signed hmac webhooks, idempotent deposits, and production sandbox lock', function (): void {
    $user = User::factory()->create(['username' => 'wallet_owner']);

    $this->actingAs($user)
        ->get(route('wallet.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Wallet')
            ->where('account.balance', 0),
        );

    // Create a pending payment directly through the service to verify webhook security
    $payment = app(WalletService::class)->createTopUpCheckout(
        $user,
        500_000,
        'sandbox',
        'checkout-key-001',
    );

    $payload = json_encode([
        'payment_id' => $payment->id,
        'provider_reference' => 'GW-REF-1001',
        'amount' => 500_000,
    ], JSON_THROW_ON_ERROR);

    // Invalid signature is rejected with 401 and balance remains 0
    $this->postJson(route('webhooks.payments', 'sandbox'), json_decode($payload, true), [
        'X-SyncViet-Timestamp' => (string) time(),
        'X-SyncViet-Signature' => 'invalid-signature-hex',
    ])->assertStatus(401);

    expect(AdCreditAccount::query()->where('user_id', $user->id)->firstOrFail()->balance)->toBe(0);

    // Valid HMAC webhook settles payment atomically once even on replay
    $webhookService = app(HmacPaymentWebhookService::class);
    $ts = time();
    $sig = $webhookService->signPayload($payload, $ts);

    $this->postJson(route('webhooks.payments', 'sandbox'), json_decode($payload, true), [
        'X-SyncViet-Timestamp' => (string) $ts,
        'X-SyncViet-Signature' => $sig,
    ])->assertOk();

    // Replay same webhook
    $this->postJson(route('webhooks.payments', 'sandbox'), json_decode($payload, true), [
        'X-SyncViet-Timestamp' => (string) $ts,
        'X-SyncViet-Signature' => $sig,
    ])->assertOk();

    $account = AdCreditAccount::query()->where('user_id', $user->id)->firstOrFail();

    expect($account->balance)->toBe(500_000)
        ->and($account->total_deposited)->toBe(500_000)
        ->and(AdCreditEntry::query()->where('account_id', $account->id)->where('type', CreditEntryType::Deposit->value)->count())->toBe(1);

    // Top-up idempotency prevents double credits on repeated submission
    $this->actingAs($user)
        ->post(route('wallet.top-up'), [
            'amount' => 200_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'repeat-topup-key',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('wallet.top-up'), [
            'amount' => 200_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'repeat-topup-key',
        ])
        ->assertRedirect();

    expect($account->fresh()->balance)->toBe(700_000);

    // Sandbox top-up disabled when sandbox_topup_enabled is false (production guard)
    config(['syncviet.sandbox_topup_enabled' => false]);

    $this->actingAs($user)
        ->post(route('wallet.top-up'), [
            'amount' => 200_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'prod-blocked-key',
        ])
        ->assertNotFound();
});

test('ad campaigns enforce server integer budgeting, anti-idor, holds, and automatic refunds', function (): void {
    $advertiser = User::factory()->create(['username' => 'ad_creator']);
    $otherUser = User::factory()->create(['username' => 'other_member']);
    $moderator = User::factory()->moderator()->withTwoFactor()->create(['username' => 'ad_mod']);
    $adminNo2fa = User::factory()->admin()->create(['username' => 'ad_admin_no2fa']);

    $ownPost = Post::factory()->for($advertiser, 'author')->create([
        'title' => 'Cloud VPS Guide',
        'visibility' => 'public',
    ]);
    $otherPost = Post::factory()->for($otherUser, 'author')->create(['visibility' => 'public']);

    // Fund wallet with 2.450.000 VND
    $this->actingAs($advertiser)
        ->post(route('wallet.top-up'), [
            'amount' => 2_450_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'fund-advertiser-01',
        ])
        ->assertRedirect();

    // Cannot promote someone else's post
    $this->actingAs($advertiser)
        ->post(route('ads.campaigns.store'), [
            'post_id' => $otherPost->id,
            'objective' => AdObjective::Reach->value,
            'schedule_mode' => 'immediate',
            'budget_type' => 'daily',
            'daily_budget' => 200_000,
            'duration_days' => 7,
        ])
        ->assertSessionHasErrors('post_id');

    // Cannot exceed wallet balance (attempting 10M fails cleanly without modifying balance)
    $this->actingAs($advertiser)
        ->post(route('ads.campaigns.store'), [
            'post_id' => $ownPost->id,
            'objective' => AdObjective::Reach->value,
            'schedule_mode' => 'immediate',
            'budget_type' => 'total',
            'total_budget' => 10_000_000,
        ])
        ->assertSessionHasErrors('budget');

    // Create campaign tampering total_budget = 200.000 while backend computes 200.000 * 7 = 1.400.000
    $this->actingAs($advertiser)
        ->post(route('ads.campaigns.store'), [
            'post_id' => $ownPost->id,
            'objective' => AdObjective::Reach->value,
            'target_topics' => ['Technology', 'SaaS'],
            'target_url' => 'https://example.com/vps',
            'cta_label' => 'Try now',
            'schedule_mode' => 'immediate',
            'budget_type' => 'daily',
            'daily_budget' => 200_000,
            'duration_days' => 7,
            'total_budget' => 200_000,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $campaign = AdCampaign::query()->where('post_id', $ownPost->id)->firstOrFail();
    $account = AdCreditAccount::query()->where('user_id', $advertiser->id)->firstOrFail();

    expect($campaign->total_budget)->toBe(1_400_000)
        ->and($campaign->held_amount)->toBe(1_400_000)
        ->and($campaign->status)->toBe(AdCampaignStatus::PendingReview)
        ->and($account->balance)->toBe(1_050_000)
        ->and($account->held_balance)->toBe(1_400_000);

    // Other user cannot pause/cancel campaign (Anti-IDOR)
    $this->actingAs($otherUser)
        ->post(route('ads.campaigns.status', $campaign->id), ['action' => 'cancel'])
        ->assertForbidden();

    // Admin without 2FA cannot review ads; non-staff gets 403
    $this->actingAs($otherUser)->get(route('admin.ads.index'))->assertForbidden();
    $this->actingAs($adminNo2fa)->get(route('admin.ads.index'))->assertRedirect(route('security.edit'));

    // Rejecting campaign refunds 100% back to advertiser wallet atomically
    $this->actingAs($moderator)
        ->post(route('admin.ads.review', $campaign->id), [
            'decision' => 'reject',
            'reason' => 'Destination link mismatch.',
        ])
        ->assertRedirect();

    expect($campaign->fresh()->status)->toBe(AdCampaignStatus::Rejected)
        ->and($campaign->fresh()->held_amount)->toBe(0)
        ->and($account->fresh()->balance)->toBe(2_450_000)
        ->and($account->fresh()->held_balance)->toBe(0);
});

test('approved campaigns serve in feed and deduplicate impression and click billing against fraud', function (): void {
    $advertiser = User::factory()->create(['username' => 'sponsored_biz', 'role' => UserRole::User]);
    $viewer = User::factory()->create(['username' => 'feed_reader', 'role' => UserRole::User]);
    $moderator = User::factory()->moderator()->withTwoFactor()->create(['username' => 'ad_approver']);

    $organicPost = Post::factory()->for($viewer, 'author')->create([
        'title' => 'Organic Community Post',
        'visibility' => 'public',
    ]);
    $adPost = Post::factory()->for($advertiser, 'author')->create([
        'title' => 'Cloud Hosting Promo',
        'visibility' => 'public',
    ]);

    $this->actingAs($advertiser)
        ->post(route('wallet.top-up'), [
            'amount' => 500_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'fund-delivery-01',
        ])
        ->assertRedirect();

    $this->actingAs($advertiser)
        ->post(route('ads.campaigns.store'), [
            'post_id' => $adPost->id,
            'objective' => AdObjective::Reach->value,
            'target_url' => 'https://example.com/offer',
            'cta_label' => 'Explore offer',
            'schedule_mode' => 'immediate',
            'budget_type' => 'total',
            'total_budget' => 200_000,
        ])
        ->assertRedirect();

    $campaign = AdCampaign::query()->where('post_id', $adPost->id)->firstOrFail();

    // Moderator approves campaign
    $this->actingAs($moderator)
        ->post(route('admin.ads.review', $campaign->id), ['decision' => 'approve'])
        ->assertRedirect();

    expect($campaign->fresh()->status)->toBe(AdCampaignStatus::Active);

    // Viewer visits Home Feed twice -> sponsored post rendered & only charged 1 impression (25 VND)
    $this->actingAs($viewer)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('sponsoredPost.is_sponsored', true)
            ->where('sponsoredPost.ad_campaign.id', $campaign->id),
        );

    $this->actingAs($viewer)->get(route('home'))->assertOk();

    expect($campaign->fresh()->impressions_count)->toBe(1)
        ->and($campaign->fresh()->spent_amount)->toBe(25);

    // Owner self-click does not charge click budget
    $this->actingAs($advertiser)
        ->get(route('ads.click', $campaign->id))
        ->assertRedirect('https://example.com/offer');

    expect($campaign->fresh()->spent_amount)->toBe(25);

    // Viewer clicking charges 1.250 VND once even if clicked twice on same day
    $this->actingAs($viewer)
        ->get(route('ads.click', $campaign->id))
        ->assertRedirect('https://example.com/offer');

    $this->actingAs($viewer)
        ->get(route('ads.click', $campaign->id))
        ->assertRedirect('https://example.com/offer');

    $account = AdCreditAccount::query()->where('user_id', $advertiser->id)->firstOrFail();

    expect($campaign->fresh()->clicks_count)->toBe(1)
        ->and($campaign->fresh()->spent_amount)->toBe(1_275)
        ->and($campaign->fresh()->held_amount)->toBe(198_725)
        ->and($account->total_spent)->toBe(1_275);
});
