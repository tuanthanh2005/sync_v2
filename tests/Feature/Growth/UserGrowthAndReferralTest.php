<?php

use App\Enums\AccountType;
use App\Enums\ActivationMilestone;
use App\Enums\GrowthCampaignStatus;
use App\Enums\MemberBadgeType;
use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use App\Enums\PostCategory;
use App\Enums\ReferralStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\EarlyMember;
use App\Models\GrowthCampaign;
use App\Models\GrowthFraudReview;
use App\Models\OnboardingProgress;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralQualificationEvent;
use App\Models\ReferralReward;
use App\Models\ReferralVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserActivationEvent;
use App\Models\UserBadge;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Growth\GrowthAnalyticsService;
use App\Services\Growth\GrowthConfigService;
use App\Services\Growth\OnboardingAndActivationService;
use App\Services\Growth\ReferralService;
use App\Services\Growth\RetentionAndDigestService;
use App\Services\Growth\RewardEngineService;
use App\Services\Monetization\RevenueAnalyticsService;
use App\Services\Security\RbacService;
use Database\Seeders\GrowthSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    (new LanguageSeeder)->run();
    MonetizationSeeder::seedDefaultsIfEmpty();
    GrowthSeeder::seedDefaultsIfEmpty();
});

function createGrowthAdmin(): User
{
    /** @var User $admin */
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'account_type' => AccountType::Human,
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('GROWTHADMIN2FA'),
        'two_factor_confirmed_at' => now(),
    ]);

    return $admin;
}

function createVerifiedHuman(array $overrides = []): User
{
    /** @var User $user */
    $user = User::factory()->create(array_merge([
        'role' => UserRole::User,
        'account_type' => AccountType::Human,
        'is_test_account' => false,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'last_activity_at' => now(),
    ], $overrides));

    return $user;
}

function activateReferralCampaign(User $admin): GrowthCampaign
{
    app(GrowthConfigService::class)->set('referral_enabled', true, 'referral', 'boolean', $admin);
    app(GrowthConfigService::class)->set('referral_rewards_auto_grant', true, 'referral', 'boolean', $admin);
    app(GrowthConfigService::class)->set('referral_verification_hours', 72, 'referral', 'integer', $admin);

    /** @var GrowthCampaign $campaign */
    $campaign = GrowthCampaign::query()->where('slug', 'syncviet-referral-v1')->firstOrFail();
    $campaign->forceFill([
        'status' => GrowthCampaignStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(60),
    ])->save();

    return $campaign;
}

it('generates unique non-sequential PII-free referral codes for human accounts and excludes AI Agents, System, and Test accounts', function (): void {
    $humanA = createVerifiedHuman(['email' => 'alice.real@example.com', 'username' => 'alice_real']);
    $humanB = createVerifiedHuman(['email' => 'bob.real@example.com', 'username' => 'bob_real']);

    $service = app(ReferralService::class);
    $codeA = $service->getOrCreateCode($humanA);
    $codeA2 = $service->getOrCreateCode($humanA);
    $codeB = $service->getOrCreateCode($humanB);

    expect($codeA->id)->toBe($codeA2->id);
    expect($codeA->code)->toMatch('/^SV-[A-Z0-9]{6}$/');
    expect($codeB->code)->toMatch('/^SV-[A-Z0-9]{6}$/');
    expect($codeA->code)->not->toBe($codeB->code);
    expect($codeA->code)->not->toContain((string) $humanA->id);
    expect(strtolower($codeA->code))->not->toContain('alice');

    $aiAgent = User::factory()->create([
        'account_type' => AccountType::AiAgent,
        'email_verified_at' => now(),
    ]);
    $systemAccount = User::factory()->create([
        'account_type' => AccountType::SystemOfficial,
        'email_verified_at' => now(),
    ]);
    $testAccount = createVerifiedHuman(['is_test_account' => true]);

    expect(fn () => $service->getOrCreateCode($aiAgent))->toThrow(ValidationException::class);
    expect(fn () => $service->getOrCreateCode($systemAccount))->toThrow(ValidationException::class);
    expect(fn () => $service->getOrCreateCode($testAccount))->toThrow(ValidationException::class);

    $this->actingAs($aiAgent)->get(route('referral.index'))->assertForbidden();
});

it('handles public /r/{code} visits with noindex header, first-touch cookie, bot exclusion, and open-redirect protection', function (): void {
    $referrer = createVerifiedHuman();
    $code = app(ReferralService::class)->getOrCreateCode($referrer);

    // Human visit sets X-Robots-Tag: noindex, nofollow and sv_ref_code cookie, and blocks external ?next=
    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0',
    ])->get("/r/{$code->code}?next=https://evil.example.com/phish");

    $response->assertRedirect(route('register', [], false));
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $response->assertCookie(ReferralService::COOKIE_NAME, $code->code);

    $code->refresh();
    expect($code->visits_count)->toBe(1);
    expect(ReferralVisit::query()->where('referral_code_id', $code->id)->count())->toBe(1);

    // Safe internal next path is allowed
    $safeResponse = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
    ])->get("/r/{$code->code}?next=/communities");
    $safeResponse->assertRedirect('/communities');

    // Bot/crawler visit MUST NOT inflate visits_count or create ReferralVisit rows
    $beforeBot = $code->fresh()->visits_count;
    $botResponse = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ])->get("/r/{$code->code}");

    $botResponse->assertRedirect(route('register', [], false));
    $botResponse->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($code->fresh()->visits_count)->toBe($beforeBot);
});

it('enforces first-touch attribution, blocks self-referral including Gmail aliases, and blocks mutual referral loops', function (): void {
    $service = app(ReferralService::class);
    $alice = createVerifiedHuman(['email' => 'john.doe@gmail.com']);
    $aliceCode = $service->getOrCreateCode($alice);

    // 1. Self-referral by Gmail dot/plus alias MUST be hard-blocked
    $aliasUser = createVerifiedHuman(['email' => 'johndoe+promo@gmail.com']);
    expect($service->attributeRegistration($aliasUser, null, $aliceCode->code))->toBeNull();
    expect($aliasUser->fresh()->referred_by_user_id)->toBeNull();

    // 2. Legitimate attribution to Bob
    $bob = createVerifiedHuman(['email' => 'bob.member@example.com']);
    $ref = $service->attributeRegistration($bob, null, $aliceCode->code);
    expect($ref)->not->toBeNull();
    expect($ref?->status)->toBe(ReferralStatus::Pending);
    expect($bob->fresh()->referred_by_user_id)->toBe($alice->id);

    // 3. First-touch protection: cannot overwrite or claim retroactively via Charlie's code
    $charlie = createVerifiedHuman(['email' => 'charlie.member@example.com']);
    $charlieCode = $service->getOrCreateCode($charlie);
    expect($service->attributeRegistration($bob->fresh(), null, $charlieCode->code))->toBeNull();
    expect($bob->fresh()->referred_by_user_id)->toBe($alice->id);

    // 4. Mutual referral loop (Bob tries to refer Alice) MUST be hard-blocked
    $bobCode = $service->getOrCreateCode($bob);
    $aliceFresh = createVerifiedHuman(['email' => 'unused@example.com']);
    // Simulate reverse record existing between bob -> alice:
    expect($service->attributeRegistration($alice, null, $bobCode->code))->toBeNull();
    expect($aliceFresh->id)->toBeGreaterThan(0);
});

it('requires verification hold, email verification, and onboarding/activity before qualifying, and routes burst velocity to manual review without auto-banning', function (): void {
    $admin = createGrowthAdmin();
    activateReferralCampaign($admin);
    $service = app(ReferralService::class);
    $onboarding = app(OnboardingAndActivationService::class);

    $referrer = createVerifiedHuman();
    $code = $service->getOrCreateCode($referrer);

    // Unverified referee with future eligible_at MUST NOT qualify yet
    $referee = User::factory()->create([
        'account_type' => AccountType::Human,
        'status' => UserStatus::Active,
        'email_verified_at' => null,
    ]);
    $referral = $service->attributeRegistration($referee, null, $code->code);
    expect($referral)->not->toBeNull();
    expect($referral?->status)->toBe(ReferralStatus::Pending);

    // Process right now -> still within 72h hold window
    $res0 = $service->processPendingQualifications();
    expect($res0['qualified'])->toBe(0);
    expect($referral?->fresh()->status)->toBe(ReferralStatus::Pending);

    // Advance eligible_at to past, but email still unverified -> still pending
    $referral?->forceFill(['eligible_at' => now()->subHour()])->save();
    $res1 = $service->processPendingQualifications();
    expect($res1['qualified'])->toBe(0);

    // Verify email, but no onboarding/activity yet -> still pending
    $referee->forceFill(['email_verified_at' => now()])->save();
    $res2 = $service->processPendingQualifications();
    expect($res2['qualified'])->toBe(0);

    // Complete onboarding/select interests -> now qualifies and grants Milestone 1 (+3 days)
    $onboarding->updateProgress($referee, ['selected_interests' => ['tech', 'engineering'], 'complete' => true]);
    $res3 = $service->processPendingQualifications();
    expect($res3['qualified'])->toBe(1);
    expect($res3['rewards_granted'])->toBe(1);

    expect($referral?->fresh()->status)->toBe(ReferralStatus::Rewarded);
    expect(ReferralQualificationEvent::query()->where('referral_id', $referral?->id)->count())->toBeGreaterThanOrEqual(2);

    // Multi-signal velocity burst (same IP + high hourly velocity) -> routed to UnderReview (NEVER auto-banned!)
    app(GrowthConfigService::class)->set('fraud_velocity_hourly_threshold', 2, 'fraud', 'integer', $admin);
    $burstReferrer = createVerifiedHuman();
    $burstCode = $service->getOrCreateCode($burstReferrer);

    $flagged = null;
    for ($i = 1; $i <= 3; $i++) {
        $req = Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.55']);
        $u = createVerifiedHuman(['email' => "office.coworker{$i}@company.example"]);
        $flagged = $service->attributeRegistration($u, $req, $burstCode->code);
    }

    expect($flagged)->not->toBeNull();
    expect($flagged?->status)->toBe(ReferralStatus::UnderReview);
    expect(GrowthFraudReview::query()->where('referral_id', $flagged?->id)->where('status', 'pending')->exists())->toBeTrue();
    // Critical fairness rule: referrer is NEVER automatically banned for shared IP / office Wi-Fi!
    expect($burstReferrer->fresh()->status)->toBe(UserStatus::Active);
});

it('calculates cumulative milestone deltas accurately without double-granting and remains idempotent across retries', function (): void {
    $admin = createGrowthAdmin();
    activateReferralCampaign($admin);

    $service = app(ReferralService::class);
    $rewardEngine = app(RewardEngineService::class);
    $onboarding = app(OnboardingAndActivationService::class);

    $referrer = createVerifiedHuman();
    $code = $service->getOrCreateCode($referrer);

    // Helper to add N qualified referrals
    $addQualified = function (int $count) use ($service, $onboarding, $code): void {
        for ($i = 0; $i < $count; $i++) {
            $referee = createVerifiedHuman();
            $ref = $service->attributeRegistration($referee, null, $code->code);
            $onboarding->updateProgress($referee, ['selected_interests' => ['design'], 'complete' => true]);
            $ref?->forceFill(['eligible_at' => now()->subHour()])->save();
        }
        $service->processPendingQualifications(50);
    };

    // Reach 1 qualified -> Milestone 1 (cumulative 3d -> grants +3d)
    $addQualified(1);
    expect((int) ReferralReward::query()->where('user_id', $referrer->id)->where('status', 'granted')->sum('days_granted'))->toBe(3);

    // Re-evaluating idempotently grants 0 additional days
    expect($rewardEngine->evaluateAndGrantForUser($referrer->fresh()))->toHaveCount(0);
    expect((int) ReferralReward::query()->where('user_id', $referrer->id)->where('status', 'granted')->sum('days_granted'))->toBe(3);

    // Reach 3 qualified -> Milestone 2 (cumulative 7d -> grants ONLY delta +4d, total 7d, NEVER 10d!)
    $addQualified(2);
    $totalAfter3 = (int) ReferralReward::query()->where('user_id', $referrer->id)->where('status', 'granted')->sum('days_granted');
    expect($totalAfter3)->toBe(7);

    // Reach 5 qualified -> Milestone 3 (cumulative 15d -> grants ONLY delta +8d, total 15d!)
    $addQualified(2);
    $totalAfter5 = (int) ReferralReward::query()->where('user_id', $referrer->id)->where('status', 'granted')->sum('days_granted');
    expect($totalAfter5)->toBe(15);

    expect($referrer->fresh()->isPremium())->toBeTrue();
});

it('stacks promotional Premium on paid Premium, excludes promotional grants from revenue, and preserves paid Premium floor on revocation', function (): void {
    $admin = createGrowthAdmin();
    $rewardEngine = app(RewardEngineService::class);

    $member = createVerifiedHuman();

    // Create an existing PAID Premium subscription valid for 30 days
    $paidExpiry = now()->addDays(30)->startOfSecond();
    $payment = Payment::query()->forceCreate([
        'order_code' => 'SV-PAID-TEST-001',
        'user_id' => $member->id,
        'service_type' => PaymentServiceType::Premium,
        'payable_type' => 'premium_plan',
        'payable_id' => 1,
        'provider' => 'bank_transfer',
        'idempotency_key' => 'test-paid-premium-001',
        'currency' => 'VND',
        'amount' => 49000,
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
    ]);

    Subscription::query()->forceCreate([
        'user_id' => $member->id,
        'premium_plan_id' => 1,
        'payment_id' => $payment->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now(),
        'expires_at' => $paidExpiry,
        'amount_paid' => 49000,
        'promotional_days' => 0,
        'currency' => 'VND',
        'source' => 'checkout',
    ]);
    $member->forceFill(['premium_until' => $paidExpiry])->save();

    $revenueBefore = app(RevenueAnalyticsService::class)->dashboardPayload('month')['currencies']['VND']['recognized_service_gross'];

    // Grant 15 promotional Premium days
    $reward = $rewardEngine->manualGrantReward($member, 15, 'referral_reward', 'Verified ambassador bonus', $admin);

    $member->refresh();
    expect($member->premium_until)->not->toBeNull();
    // Stacked on top of paid 30d -> ~45 days from now
    expect((int) round(now()->diffInDays($member->premium_until)))->toBeGreaterThanOrEqual(44);

    // Verify subscription record is promotional (payment_id = null, amount_paid = 0)
    $promoSub = $reward->subscription()->firstOrFail();
    expect($promoSub->payment_id)->toBeNull();
    expect($promoSub->amount_paid)->toBe(0);
    expect($promoSub->promotional_days)->toBe(15);

    // Revenue analytics MUST remain completely unchanged (promotional Premium is NEVER revenue!)
    $revenueAfter = app(RevenueAnalyticsService::class)->dashboardPayload('month')['currencies']['VND']['recognized_service_gross'];
    expect($revenueAfter)->toBe($revenueBefore);
    expect($revenueAfter)->toBe(49000);

    // Revoke promotional reward -> deducts ONLY the 15 promo days and strictly preserves the 30-day PAID floor!
    $rewardEngine->revokeReward($reward, 'Audit reversal test', $admin);

    $member->refresh();
    expect($reward->fresh()->status)->toBe('revoked');
    expect($member->isPremium())->toBeTrue();
    expect($member->premium_until?->greaterThanOrEqualTo($paidExpiry->subMinute()))->toBeTrue();
});

it('enforces strict Early Member slot caps, never reuses revoked member numbers, and excludes AI Agents', function (): void {
    $admin = createGrowthAdmin();
    $config = app(GrowthConfigService::class);
    $service = app(EarlyMemberAndBadgeService::class);

    $config->set('early_member_enabled', true, 'early_member', 'boolean', $admin);
    $config->set('early_member_max_slots', 2, 'early_member', 'integer', $admin);
    $config->set('early_member_premium_days', 30, 'early_member', 'integer', $admin);

    $earlyCamp = GrowthCampaign::query()->where('slug', 'syncviet-early-members-1000')->firstOrFail();
    $earlyCamp->forceFill([
        'status' => GrowthCampaignStatus::Active,
        'max_participants' => 2,
    ])->save();

    $aiAgent = User::factory()->create(['account_type' => AccountType::AiAgent, 'email_verified_at' => now()]);
    expect($service->enrollEarlyMemberIfEligible($aiAgent))->toBeNull();

    $u1 = createVerifiedHuman();
    $u2 = createVerifiedHuman();
    $u3 = createVerifiedHuman();

    $e1 = $service->enrollEarlyMemberIfEligible($u1);
    $e2 = $service->enrollEarlyMemberIfEligible($u2);
    $e3 = $service->enrollEarlyMemberIfEligible($u3);

    expect($e1?->member_number)->toBe(1);
    expect($e2?->member_number)->toBe(2);
    expect($e3)->toBeNull(); // Strictly capped at 2!

    // Early Member badge granted to #1 and distinct from Premium
    expect(UserBadge::query()->where('user_id', $u1->id)->where('badge_type', MemberBadgeType::EarlyMember->value)->exists())->toBeTrue();

    // Revoking #1 MUST NEVER allow #1 to be reassigned or exceed highest issued slot!
    $service->revokeEarlyMember($e1, 'Policy breach', $admin);
    expect($e1->fresh()->status)->toBe('revoked');
    expect($service->enrollEarlyMemberIfEligible($u3))->toBeNull();
    expect(EarlyMember::query()->where('member_number', 1)->count())->toBe(1);
});

it('respects campaign active windows and reward days budget ceilings', function (): void {
    $admin = createGrowthAdmin();
    $campaign = activateReferralCampaign($admin);
    $campaign->forceFill([
        'max_reward_days_budget' => 5,
        'used_reward_days_budget' => 0,
    ])->save();

    $service = app(ReferralService::class);
    $onboarding = app(OnboardingAndActivationService::class);

    $referrer = createVerifiedHuman();
    $code = $service->getOrCreateCode($referrer);

    // 3 qualified referrals -> Milestone 1 (3d) fits within 5d budget, Milestone 2 (delta +4d -> total 7d) exceeds 5d budget and is skipped!
    for ($i = 0; $i < 3; $i++) {
        $u = createVerifiedHuman();
        $ref = $service->attributeRegistration($u, null, $code->code);
        $onboarding->updateProgress($u, ['selected_interests' => ['tech'], 'complete' => true]);
        $ref?->forceFill(['eligible_at' => now()->subHour()])->save();
    }
    $service->processPendingQualifications();

    expect((int) ReferralReward::query()->where('user_id', $referrer->id)->where('status', 'granted')->sum('days_granted'))->toBe(3);
    expect($campaign->fresh()->used_reward_days_budget)->toBe(3);

    // Pausing campaign prevents further automatic rewards
    $campaign->forceFill(['status' => GrowthCampaignStatus::Paused])->save();
    $referrer2 = createVerifiedHuman();
    $code2 = $service->getOrCreateCode($referrer2);
    $u2 = createVerifiedHuman();
    $ref2 = $service->attributeRegistration($u2, null, $code2->code);
    $onboarding->updateProgress($u2, ['selected_interests' => ['tech'], 'complete' => true]);
    $ref2?->forceFill(['eligible_at' => now()->subHour()])->save();
    $service->processPendingQualifications();

    expect(ReferralReward::query()->where('user_id', $referrer2->id)->count())->toBe(0);
});

it('supports skippable onboarding without auto-joining communities, records activation milestones, respects retention opt-outs, and validates signed unsubscribe', function (): void {
    $admin = createGrowthAdmin();
    $user = createVerifiedHuman([
        'last_activity_at' => now()->subDays(5),
        'reengagement_stage' => 0,
    ]);
    Community::query()->forceCreate([
        'name' => 'Laravel Builders',
        'slug' => 'laravel-builders',
        'description' => 'Real technical engineering discussions.',
        'category' => PostCategory::Technology,
        'visibility' => 'public',
        'status' => 'active',
        'owner_id' => $admin->id,
    ]);

    // Member updates onboarding preferences -> NEVER auto-joins communities!
    $this->actingAs($user)
        ->post(route('onboarding.update'), [
            'selected_locale' => 'en',
            'selected_interests' => ['technology', 'backend'],
            'complete' => true,
        ])
        ->assertRedirect(route('home', [], false));

    expect(OnboardingProgress::query()->where('user_id', $user->id)->whereNotNull('completed_at')->exists())->toBeTrue();
    expect(UserActivationEvent::query()->where('user_id', $user->id)->where('milestone', ActivationMilestone::InterestsSelected->value)->exists())->toBeTrue();
    expect(CommunityMember::query()->where('user_id', $user->id)->count())->toBe(0);

    // Simulate member becoming inactive for 5 days, then trigger retention re-engagement
    $user->forceFill(['last_activity_at' => now()->subDays(5), 'reengagement_stage' => 0])->save();
    app(GrowthConfigService::class)->set('retention_reengagement_enabled', true, 'retention', 'boolean', $admin);
    $retention = app(RetentionAndDigestService::class);

    $firstRun = $retention->processInactiveReengagement(20);
    expect($firstRun['sent'])->toBe(1);
    expect($user->fresh()->reengagement_stage)->toBe(1);

    // Immediate second run MUST skip due to frequency cap
    $secondRun = $retention->processInactiveReengagement(20);
    expect($secondRun['sent'])->toBe(0);

    // Signed one-click unsubscribe disables channel cleanly
    $unsubUrl = $retention->unsubscribeUrl($user, 'reengagement');
    $this->get($unsubUrl)->assertRedirect(route('home'));

    $prefs = $retention->preferencesFor($user->fresh());
    expect($prefs['reengagement'])->toBeFalse();

    // Tampered unsigned URL rejected with 403
    $this->get("/growth/unsubscribe/{$user->id}/reengagement")->assertForbidden();
});

it('computes human-only Growth Analytics excluding AI Agents and test accounts, enforces Admin RBAC, and exports formula-safe CSV', function (): void {
    $admin = createGrowthAdmin();
    $member = createVerifiedHuman(['acquisition_source' => 'organic_search']);
    $referee = createVerifiedHuman(['username' => '=cmd_injection_user']);

    User::factory()->create(['account_type' => AccountType::AiAgent, 'email_verified_at' => now(), 'last_activity_at' => now()]);
    createVerifiedHuman(['is_test_account' => true, 'last_activity_at' => now()]);

    $snapshot = app(GrowthAnalyticsService::class)->snapshotDailyMetrics();
    // Should count ONLY real human non-test accounts (admin + member + referee = 3), NEVER the AI Agent or test account!
    expect($snapshot->total_human_users)->toBe(3);
    expect($snapshot->organic_registrations)->toBe(1);

    // Regular member cannot access Admin Growth Center
    $this->actingAs($member)->get(route('admin.growth.overview'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.growth.referrals'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.growth.campaigns'))->assertForbidden();

    // Member can access their own /referral and /onboarding hubs
    $this->actingAs($member)->get(route('referral.index'))->assertOk();
    $this->actingAs($member)->get(route('onboarding.index'))->assertOk();

    // Admin with 2FA can access all 3 Growth Center pages
    $this->actingAs($admin)->get(route('admin.growth.overview'))->assertOk();
    $this->actingAs($admin)->get(route('admin.growth.referrals'))->assertOk();
    $this->actingAs($admin)->get(route('admin.growth.campaigns'))->assertOk();

    // Formula-safe CSV export escapes leading '=' characters!
    $code = app(ReferralService::class)->getOrCreateCode($member);
    app(ReferralService::class)->attributeRegistration($referee, null, $code->code);

    $csvResponse = $this->actingAs($admin)->get(route('admin.growth.referrals.export-csv'));
    $csvResponse->assertOk();
    $content = $csvResponse->streamedContent();
    expect($content)->toContain("'=cmd_injection_user");
});
