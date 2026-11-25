<?php

use App\Models\AdCreditAccount;
use App\Models\BackupRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\Security\RbacService;
use App\Services\System\LanguageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    app(LanguageService::class)->ensureSeeded();
});

test('finance controls enforce rbac, password confirmation, promo bucket separation, and non-negative invariants', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $modRole = Role::query()->where('slug', 'moderator')->firstOrFail();
    $financeRole = Role::query()->where('slug', 'finance_manager')->firstOrFail();

    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $moderator = User::factory()->moderator()->create(['rbac_role_id' => $modRole->id]);
    $financeManager = User::factory()->moderator()->create(['rbac_role_id' => $financeRole->id]);
    $member = User::factory()->create();

    // Moderator is denied finance access; Finance Manager is permitted
    $this->actingAs($moderator)->get(route('admin.finance.index'))->assertForbidden();
    $this->actingAs($financeManager)->get(route('admin.finance.index'))->assertOk();

    // Wrong password rejected on ledger adjustment
    $this->actingAs($admin)
        ->post(route('admin.finance.wallet.adjust', $member->id), [
            'amount' => 150_000,
            'bucket' => 'promo',
            'reason' => 'Welcome campaign grant',
            'current_password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('current_password');

    // Valid promo grant credits promo_balance without touching paid balance
    $this->actingAs($admin)
        ->post(route('admin.finance.wallet.adjust', $member->id), [
            'amount' => 150_000,
            'bucket' => 'promo',
            'reason' => 'Welcome campaign grant',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    $account = AdCreditAccount::query()->where('user_id', $member->id)->firstOrFail();
    expect($account->promo_balance)->toBe(150_000)
        ->and($account->balance)->toBe(0);

    // Non-negative balance invariant rejects over-deduction
    $this->actingAs($admin)
        ->post(route('admin.finance.wallet.adjust', $member->id), [
            'amount' => -200_000,
            'bucket' => 'promo',
            'reason' => 'Over-deduction test',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('amount');

    // Settled payment refund enforces remaining limit and deducts cleanly
    $walletService = app(WalletService::class);
    $payment = $walletService->createTopUpCheckout($member, 300_000, 'sandbox', 'fin-refund-001');
    $walletService->settlePaymentCredit($payment, 'PROV-REF-001');

    $this->actingAs($admin)
        ->post(route('admin.finance.refund', $payment->id), [
            'amount' => 400_000,
            'reason' => 'Too large refund',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('amount');

    $this->actingAs($admin)
        ->post(route('admin.finance.refund', $payment->id), [
            'amount' => 100_000,
            'reason' => 'Partial duplicate invoice refund',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    expect($payment->fresh()->refunded_amount)->toBe(100_000)
        ->and($account->fresh()->balance)->toBe(200_000);
});

test('system settings feature flags and maintenance mode affect backend behavior safely', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();

    // Disable posting feature flag
    $this->actingAs($admin)
        ->post(route('admin.settings.update'), [
            'settings' => [
                'feature_posting' => false,
                'maintenance_enabled' => false,
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->post(route('posts.store'), [
            'content' => 'Blocked because feature_posting is disabled',
            'category' => 'general',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('content');

    // Enable maintenance mode -> public/member gets 503 while Admin still has full access
    $this->actingAs($admin)
        ->post(route('admin.settings.update'), [
            'settings' => [
                'maintenance_enabled' => true,
                'maintenance_message' => 'Scheduled maintenance in progress.',
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)->get(route('home'))->assertStatus(503);
    $this->actingAs($admin)->get(route('admin.overview'))->assertOk();
});

test('global 15-language localization detects accept-language, enables rtl for arabic, and maintains 100 percent key parity', function (): void {
    // Auto-detect Arabic from Accept-Language header on first visit and activate RTL
    $this->withHeader('Accept-Language', 'ar-SA,ar;q=0.9,en;q=0.8')
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('isRtl', true),
        );

    // Switching locale persists to DB preferred_locale for authenticated users
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post(route('locale.update'), ['locale' => 'ja'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->preferred_locale)->toBe('ja');

    // Verify 100% key parity across ALL 15 frontend and backend locale JSON files
    $languageService = app(LanguageService::class);
    $enBackend = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

    foreach (LanguageService::allCodes() as $code) {
        $health = $languageService->translationHealth($code);
        expect($health['total_keys'])->toBeGreaterThan(500)
            ->and($health['missing_keys'])->toBeEmpty("Missing frontend keys in {$code}")
            ->and($health['completion_percent'])->toBe(100);

        $backendPath = base_path("lang/{$code}.json");
        expect(file_exists($backendPath))->toBeTrue("Missing backend lang file lang/{$code}.json");
        $backendData = json_decode((string) file_get_contents($backendPath), true);
        expect(is_array($backendData))->toBeTrue();
        expect(array_diff_key($enBackend, $backendData))->toBeEmpty("Missing backend keys in lang/{$code}.json");
    }
});

test('admin can create and verify portable snapshot backups with sha-256 checksum', function (): void {
    Storage::fake('local');
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);

    $this->actingAs($admin)
        ->post(route('admin.backups.store'), ['notes' => 'Automated governance snapshot'])
        ->assertSessionHasNoErrors();

    $record = BackupRecord::query()->latest('id')->firstOrFail();
    expect($record->status)->toBe('completed')
        ->and(strlen($record->checksum))->toBe(64);
    Storage::disk('local')->assertExists($record->path);
});
