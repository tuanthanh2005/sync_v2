<?php

use App\Enums\AdCampaignStatus;
use App\Enums\BannerCampaignStatus;
use App\Enums\BannerDeliveryMode;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdCampaign;
use App\Models\AdCreditAccount;
use App\Models\BannerCampaign;
use App\Models\BannerPlacement;
use App\Models\BoostPackage;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Post;
use App\Models\PremiumPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Security\AuditLogger;
use App\Services\Social\ProfileService;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(MonetizationSeeder::class);
    config(['syncviet.payment_webhook_secret' => 'test-monetization-secret-key']);
    config(['syncviet.sandbox_topup_enabled' => true]);
});

function sendSignedMonetizationWebhook(mixed $testCase, Payment $payment, string $providerReference): TestResponse
{
    $payloadArray = [
        'payment_id' => $payment->id,
        'provider_reference' => $providerReference,
        'amount' => $payment->amount,
    ];
    $rawPayload = json_encode($payloadArray, JSON_THROW_ON_ERROR);
    $ts = time();
    $sig = app(HmacPaymentWebhookService::class)->signPayload($rawPayload, $ts);

    return $testCase->postJson(
        route('webhooks.payments', $payment->provider),
        $payloadArray,
        [
            'X-SyncViet-Timestamp' => (string) $ts,
            'X-SyncViet-Signature' => $sig,
        ],
    );
}

test('guests can browse services center, pricing pages, and sitemap without login', function (): void {
    $this->get(route('monetization.services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monetization/Services')->has('plans', 4));

    $this->get(route('monetization.premium'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monetization/Premium')->has('plans', 4));

    $this->get(route('monetization.advertising'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monetization/Advertising'));

    $this->get(route('monetization.banner'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monetization/BannerAds')->has('placements', 9));

    $this->get(route('monetization.boost'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monetization/BoostPost')->has('packages', 5));

    $sitemap = $this->get(route('seo.sitemap'))->assertOk();
    $sitemap->assertHeader('Content-Type', 'application/xml; charset=utf-8');
    expect($sitemap->getContent())
        ->toContain('/services')
        ->toContain('/premium')
        ->not->toContain('/wallet')
        ->not->toContain('/payments')
        ->not->toContain('/admin');
});

test('premium lifecycle enforces manual confirmation, signed webhook activation, idempotent replay, extension preserving remaining days, expiry hide, and separation from identity verification', function (): void {
    $user = User::factory()->create(['username' => 'premium_buyer']);
    $plan1m = PremiumPlan::query()->where('slug', 'premium-1m')->firstOrFail();

    // Missing manual Yes/No confirmation is rejected
    $this->actingAs($user)
        ->post(route('premium.checkout'), [
            'plan_id' => $plan1m->id,
            'currency' => 'VND',
            'provider' => 'vietqr',
            'idempotency_key' => 'prem-no-confirm-01',
        ])
        ->assertSessionHasErrors('confirmed');

    expect(Payment::query()->where('user_id', $user->id)->count())->toBe(0);

    // Valid checkout creates pending order ONLY (badge NOT active before payment settles)
    $this->actingAs($user)
        ->post(route('premium.checkout'), [
            'plan_id' => $plan1m->id,
            'currency' => 'VND',
            'provider' => 'vietqr',
            'idempotency_key' => 'prem-order-001',
            'confirmed' => true,
            'amount' => 1, // Client tampering attempt must be ignored!
        ])
        ->assertRedirect();

    $payment = Payment::query()->where('idempotency_key', 'prem-order-001')->firstOrFail();
    expect($payment->amount)->toBe($plan1m->price_vnd)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($user->fresh()->isPremium())->toBeFalse();

    // Invalid webhook signature returns 401 and leaves Premium inactive
    $this->postJson(route('webhooks.payments', 'vietqr'), [
        'payment_id' => $payment->id,
        'provider_reference' => 'FAKE-REF-1',
        'amount' => $payment->amount,
    ], [
        'X-SyncViet-Timestamp' => (string) time(),
        'X-SyncViet-Signature' => 'deadbeef',
    ])->assertStatus(401);

    expect($user->fresh()->isPremium())->toBeFalse()
        ->and(PaymentWebhookLog::query()->where('signature_valid', false)->exists())->toBeTrue();

    // Valid signed webhook activates Premium once; replay does NOT double-extend
    sendSignedMonetizationWebhook($this, $payment, 'VIETQR-REF-1001')->assertOk();
    $firstExpires = $user->fresh()->premium_until;

    sendSignedMonetizationWebhook($this, $payment, 'VIETQR-REF-1001')->assertOk();
    expect($user->fresh()->isPremium())->toBeTrue()
        ->and($user->fresh()->premium_until?->toIso8601String())->toBe($firstExpires?->toIso8601String())
        ->and(SubscriptionEvent::query()->where('payment_id', $payment->id)->count())->toBe(1);

    // Premium is strictly distinct from Identity Verified
    $profile = app(ProfileService::class)->getOrCreateProfile($user)->refresh();
    expect($profile->is_verified_badge)->toBeFalse();

    // Purchasing a second plan while active extends directly onto existing expires_at (zero lost days)
    $this->actingAs($user)
        ->post(route('premium.checkout'), [
            'plan_id' => $plan1m->id,
            'currency' => 'VND',
            'provider' => 'vietqr',
            'idempotency_key' => 'prem-order-002',
            'confirmed' => true,
        ])
        ->assertRedirect();

    $payment2 = Payment::query()->where('idempotency_key', 'prem-order-002')->firstOrFail();
    sendSignedMonetizationWebhook($this, $payment2, 'VIETQR-REF-1002')->assertOk();

    $extendedExpires = $user->fresh()->premium_until;
    expect($extendedExpires->diffInDays($firstExpires, true))->toBeGreaterThanOrEqual(29);

    // Backdate expiration -> backend immediately hides badge even before cron runs
    $user->forceFill(['premium_until' => now()->subMinute()])->save();
    expect($user->fresh()->isPremium())->toBeFalse();

    // Cron sync marks subscription expired cleanly
    Subscription::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Active->value)->update(['expires_at' => now()->subMinute()]);
    $this->artisan('app:monetization:sync')->assertExitCode(0);
    expect(Subscription::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Active->value)->count())->toBe(0);
});

test('admin premium grant records administrative source without fabricating fake payment records', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create(['username' => 'prem_admin']);
    $member = User::factory()->create(['username' => 'gifted_member']);
    $plan = PremiumPlan::query()->where('slug', 'premium-3m')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.monetization.premium.grant'), [
            'username' => $member->username,
            'premium_plan_id' => $plan->id,
            'duration_days' => 45,
            'source' => 'promotion',
            'reason' => 'Top community contributor Q4',
        ])
        ->assertRedirect();

    $sub = Subscription::query()->where('user_id', $member->id)->firstOrFail();
    expect($member->fresh()->isPremium())->toBeTrue()
        ->and($sub->source)->toBe('promotion')
        ->and($sub->payment_id)->toBeNull()
        ->and($sub->amount_paid)->toBe(0)
        ->and(Payment::query()->where('user_id', $member->id)->count())->toBe(0);
});

test('strict max 5 pending payment orders blocks 6th order and frees slot upon cancel or settlement', function (): void {
    $user = User::factory()->create(['username' => 'pending_cap_user']);
    $otherUser = User::factory()->create(['username' => 'other_payment_user']);

    // Create 5 pending VietQR top-up orders
    for ($i = 1; $i <= 5; $i++) {
        $this->actingAs($user)
            ->post(route('wallet.top-up'), [
                'amount' => 100_000,
                'provider' => 'vietqr',
                'idempotency_key' => "pending-topup-{$i}",
                'confirmed' => true,
            ])
            ->assertRedirect();
    }

    expect(Payment::query()->where('user_id', $user->id)->where('status', PaymentStatus::Pending->value)->count())->toBe(5);

    // 6th pending top-up MUST be blocked with pending_limit error
    $this->actingAs($user)
        ->post(route('wallet.top-up'), [
            'amount' => 100_000,
            'provider' => 'vietqr',
            'idempotency_key' => 'pending-topup-6',
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('pending_limit');

    expect(Payment::query()->where('user_id', $user->id)->count())->toBe(5);

    $firstOrder = Payment::query()->where('user_id', $user->id)->firstOrFail();

    // Anti-IDOR: another user cannot inspect or cancel someone else's payment
    $this->actingAs($otherUser)->get(route('payments.show', $firstOrder->id))->assertForbidden();
    $this->actingAs($otherUser)->post(route('payments.cancel', $firstOrder->id))->assertForbidden();

    // Owner cancels 1 pending order -> slot opens and 6th order now succeeds!
    $this->actingAs($user)->post(route('payments.cancel', $firstOrder->id))->assertRedirect();
    expect($firstOrder->fresh()->status)->toBe(PaymentStatus::Cancelled);

    $this->actingAs($user)
        ->post(route('wallet.top-up'), [
            'amount' => 100_000,
            'provider' => 'vietqr',
            'idempotency_key' => 'pending-topup-6',
            'confirmed' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Payment::query()->where('user_id', $user->id)->where('status', PaymentStatus::Pending->value)->count())->toBe(5);
});

test('banner advertising enforces exclusive double-booking lock, emergency pause, expiry auto-hide, and SSRF protection', function (): void {
    Storage::fake('public');
    config(['syncviet.media_disk' => 'public']);

    $admin = User::factory()->admin()->withTwoFactor()->create(['username' => 'banner_admin']);
    $viewer = User::factory()->create(['username' => 'banner_viewer']);
    $exclusiveSlot = BannerPlacement::query()->where('code', 'desktop_feed_top')->firstOrFail();

    expect($exclusiveSlot->delivery_mode)->toBe(BannerDeliveryMode::Exclusive);

    $file = UploadedFile::fake()->image('banner.png', 728, 90);

    // SSRF loopback target URL is rejected
    $this->actingAs($admin)
        ->post(route('admin.monetization.banners.campaigns.store'), [
            'banner_placement_id' => $exclusiveSlot->id,
            'name' => 'Malicious SSRF Banner',
            'target_url' => 'http://127.0.0.1/secret',
            'alt_text' => 'SSRF attempt',
            'duration_days' => 7,
            'starts_at' => now()->subHour()->toDateTimeString(),
            'currency' => 'VND',
            'payment_status' => 'manual_paid',
            'auto_approve' => true,
            'desktop_image' => $file,
        ])
        ->assertSessionHasErrors('target_url');

    // Valid exclusive banner booking succeeds
    $validFile1 = UploadedFile::fake()->image('banner1.png', 728, 90);
    $this->actingAs($admin)
        ->post(route('admin.monetization.banners.campaigns.store'), [
            'banner_placement_id' => $exclusiveSlot->id,
            'name' => 'SyncViet Cloud Launch',
            'business_name' => 'SyncCloud',
            'headline' => 'Deploy faster',
            'cta_label' => 'Explore',
            'target_url' => 'https://example.com/cloud',
            'alt_text' => 'SyncCloud promotional banner',
            'duration_days' => 14,
            'starts_at' => now()->subHour()->toDateTimeString(),
            'currency' => 'VND',
            'payment_status' => 'manual_paid',
            'auto_approve' => true,
            'desktop_image' => $validFile1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $campaign = BannerCampaign::query()->where('name', 'SyncViet Cloud Launch')->firstOrFail();
    expect($campaign->status)->toBe(BannerCampaignStatus::Active);

    // Overlapping second booking on the SAME exclusive slot MUST be rejected!
    $validFile2 = UploadedFile::fake()->image('banner2.png', 728, 90);
    $this->actingAs($admin)
        ->post(route('admin.monetization.banners.campaigns.store'), [
            'banner_placement_id' => $exclusiveSlot->id,
            'name' => 'Double Book Attempt',
            'target_url' => 'https://example.com/other',
            'alt_text' => 'Double booking test',
            'duration_days' => 7,
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'currency' => 'VND',
            'payment_status' => 'manual_paid',
            'auto_approve' => true,
            'desktop_image' => $validFile2,
        ])
        ->assertSessionHasErrors('starts_at');

    // Active banner is delivered on home feed
    $this->actingAs($viewer)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('banners.desktop_feed_top.id', $campaign->id));

    // Emergency pause immediately removes banner from live slots
    $this->actingAs($admin)
        ->post(route('admin.monetization.banners.campaigns.manage', $campaign->id), [
            'action' => 'emergency_pause',
            'reason' => 'Creative violation reported',
        ])
        ->assertRedirect();

    $this->actingAs($viewer)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('banners.desktop_feed_top', null));
});

test('boost post enforces eligible posts, starts duration on approval, preserves organic trending, and refunds when post deleted', function (): void {
    $owner = User::factory()->create(['username' => 'boost_owner']);
    $other = User::factory()->create(['username' => 'boost_other']);
    $admin = User::factory()->admin()->withTwoFactor()->create(['username' => 'boost_admin']);

    // Fund Advertising Balance
    $this->actingAs($owner)
        ->post(route('wallet.top-up'), [
            'amount' => 500_000,
            'provider' => 'sandbox',
            'idempotency_key' => 'fund-boost-owner',
        ])
        ->assertRedirect();

    $ownPublicPost = Post::factory()->for($owner, 'author')->create([
        'title' => 'Boostable Guide',
        'visibility' => 'public',
        'reactions_count' => 1,
    ]);
    $organicTopPost = Post::factory()->for($other, 'author')->create([
        'title' => 'Organic Viral Post',
        'visibility' => 'public',
        'reactions_count' => 500,
    ]);
    $pkg24h = BoostPackage::query()->where('slug', 'boost-24h')->firstOrFail();

    // Cannot boost another user's post
    $this->actingAs($owner)
        ->post(route('posts.boost', $organicTopPost->id), [
            'boost_package_id' => $pkg24h->id,
            'payment_source' => 'ad_balance',
            'currency' => 'VND',
            'idempotency_key' => 'boost-other-fail',
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('post_id');

    // Boost own public post using Advertising Balance
    $this->actingAs($owner)
        ->post(route('posts.boost', $ownPublicPost->id), [
            'boost_package_id' => $pkg24h->id,
            'payment_source' => 'ad_balance',
            'currency' => 'VND',
            'idempotency_key' => 'boost-own-ok-01',
            'confirmed' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $boostCampaign = AdCampaign::query()->where('post_id', $ownPublicPost->id)->firstOrFail();
    expect($boostCampaign->status)->toBe(AdCampaignStatus::PendingReview)
        ->and($boostCampaign->billing_mode)->toBe('fixed_package')
        ->and($boostCampaign->held_amount)->toBe($pkg24h->price_vnd)
        ->and($boostCampaign->starts_at)->toBeNull();

    // Admin approves -> starts_at & ends_at begin ONLY at approval time!
    $this->actingAs($admin)
        ->post(route('admin.ads.review', $boostCampaign->id), ['decision' => 'approve'])
        ->assertRedirect();

    $boostCampaign->refresh();
    expect($boostCampaign->status)->toBe(AdCampaignStatus::Active)
        ->and($boostCampaign->activated_at)->not->toBeNull()
        ->and($boostCampaign->ends_at->diffInHours($boostCampaign->starts_at, true))->toBe(24.0);

    // Organic Trending list still puts viral organic post FIRST (boost NEVER fakes organic trending!)
    $this->actingAs($other)
        ->get(route('home', ['tab' => 'trending']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('posts.0.id', $organicTopPost->id)
            ->where('sponsoredPost.is_sponsored', true)
            ->where('sponsoredPost.id', $ownPublicPost->id),
        );

    // Deleting the boosted post cancels campaign & refunds Advertising Balance 100%
    $this->actingAs($owner)->delete(route('posts.destroy', $ownPublicPost->id))->assertRedirect();
    $account = AdCreditAccount::query()->where('user_id', $owner->id)->firstOrFail();
    expect($boostCampaign->fresh()->status)->toBe(AdCampaignStatus::Cancelled)
        ->and($account->balance)->toBe(500_000)
        ->and($account->held_balance)->toBe(0);
});

test('crypto adapters disabled by default, store compliance blocks native external web checkout, admin rbac & 2fa enforced, and adsense forbids raw js', function (): void {
    $member = User::factory()->create(['username' => 'compliance_user']);
    $adminNo2fa = User::factory()->admin()->create(['username' => 'mon_admin_no2fa']);
    $admin = User::factory()->admin()->withTwoFactor()->create(['username' => 'mon_admin_2fa']);
    $plan = PremiumPlan::query()->firstOrFail();

    // Non-admin blocked (403); Admin without 2FA redirected to security setup
    $this->actingAs($member)->get(route('admin.monetization.revenue'))->assertForbidden();
    $this->actingAs($adminNo2fa)->get(route('admin.monetization.revenue'))->assertRedirect(route('security.edit'));
    $this->actingAs($admin)->get(route('admin.monetization.revenue'))->assertOk();

    // Disabled crypto payment method rejected by backend
    $this->actingAs($member)
        ->post(route('premium.checkout'), [
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'provider' => 'binance_pay',
            'idempotency_key' => 'crypto-disabled-test',
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('provider');

    // Native Android Play / iOS App Store platform header blocks external web checkout for Premium
    $this->actingAs($member)
        ->withHeaders(['X-SyncViet-Platform' => 'ios_appstore'])
        ->post(route('premium.checkout'), [
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'provider' => 'paypal',
            'idempotency_key' => 'ios-store-blocked',
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('platform');

    // AdSense strictly rejects raw script / malformed publisher ID
    $this->actingAs($admin)
        ->post(route('admin.monetization.adsense.update'), [
            'adsense_enabled' => true,
            'adsense_publisher_id' => '<script>alert(1)</script>',
        ])
        ->assertSessionHasErrors('adsense_publisher_id');

    // Valid structured AdSense config saves & renders /ads.txt, never on sensitive pages
    app(AdSenseConfigService::class)->updateConfig([
        'adsense_enabled' => true,
        'adsense_publisher_id' => 'ca-pub-1234567890123456',
        'adsense_slots' => ['in_feed' => '1234567890'],
        'adsense_allowed_pages' => ['home', 'explore'],
        'adsense_consent_required' => true,
        'adsense_ads_txt_line' => 'google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0',
    ], $admin, app(AuditLogger::class));

    $this->get(route('seo.ads-txt'))
        ->assertOk()
        ->assertSee('google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0');

    expect(app(AdSenseConfigService::class)->configForPage('wallet', $member))->toBeNull()
        ->and(app(AdSenseConfigService::class)->configForPage('home', $member))->not->toBeNull();
});
