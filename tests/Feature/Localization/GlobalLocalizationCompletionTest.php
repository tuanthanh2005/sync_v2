<?php

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\Language;
use App\Models\PolicyPage;
use App\Models\Role;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Billing\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\Policy\FooterMenuService;
use App\Services\Security\RbacService;
use App\Services\System\LanguageService;
use App\Services\System\LocalizationAuditService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    app(LanguageService::class)->ensureSeeded();
});

test('all 15 locales pass strict automated localization audit with zero missing keys, empty values, placeholders, or hardcoded ui', function (): void {
    $audit = app(LocalizationAuditService::class)->audit(includeSourceScan: true);

    expect($audit['default_locale'])->toBe('en')
        ->and($audit['fallback_locale'])->toBe('en')
        ->and($audit['summary']['locales_checked'])->toBe(15)
        ->and($audit['summary']['total_missing_keys'])->toBe(0)
        ->and($audit['summary']['total_empty_keys'])->toBe(0)
        ->and($audit['summary']['total_duplicate_keys'])->toBe(0)
        ->and($audit['summary']['total_placeholder_errors'])->toBe(0)
        ->and($audit['summary']['undefined_frontend_keys'])->toBe(0)
        ->and($audit['summary']['missing_backend_keys'])->toBe(0)
        ->and($audit['summary']['vi_locale_shortcuts'])->toBe(0)
        ->and($audit['summary']['hardcoded_vue_total'])->toBe(0)
        ->and($audit['summary']['hardcoded_php_messages'])->toBe(0)
        ->and($audit['summary']['strict_passed'])->toBeTrue();

    $this->artisan('localization:audit', ['--strict' => true, '--no-source-scan' => true])
        ->assertSuccessful();
});

test('laravel validation, auth, passwords, and pagination messages are localized across all 15 languages', function (): void {
    foreach (LanguageService::allCodes() as $code) {
        $requiredMsg = (string) Lang::get('validation.required', ['attribute' => 'email'], $code);
        $authFailed = (string) Lang::get('auth.failed', [], $code);
        $pwSent = (string) Lang::get('passwords.sent', [], $code);
        $nextPage = (string) Lang::get('pagination.next', [], $code);

        expect($requiredMsg)->not->toBe('validation.required')->toContain('email')
            ->and($authFailed)->not->toBe('auth.failed')->not->toBeEmpty()
            ->and($pwSent)->not->toBe('passwords.sent')->not->toBeEmpty()
            ->and($nextPage)->not->toBe('pagination.next')->not->toBeEmpty();

        if ($code !== 'en') {
            expect($authFailed)->not->toBe((string) Lang::get('auth.failed', [], 'en'));
        }
    }
});

test('guest, authenticated, and admin language switching persists cleanly across all 15 locales and enables rtl for arabic', function (): void {
    // Default is English when no Accept-Language matches
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('isRtl', false),
        );

    // Guest switching to Arabic activates RTL and persists via session/cookie
    $this->post(route('locale.update'), ['locale' => 'ar'])
        ->assertRedirect()
        ->assertSessionHas('locale', 'ar')
        ->assertCookie('syncviet_locale', 'ar');

    $this->withSession(['locale' => 'ar'])
        ->get(route('home'))
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('isRtl', true),
        );

    // Authenticated member switching locale persists to users.preferred_locale
    $member = User::factory()->create(['preferred_locale' => 'en']);
    foreach (['vi', 'ja', 'es', 'de', 'ko', 'tr'] as $code) {
        $this->actingAs($member)
            ->post(route('locale.update'), ['locale' => $code])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($member->fresh()->preferred_locale)->toBe($code);
    }

    // Admin Center respects active locale
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create([
        'rbac_role_id' => $superAdminRole->id,
        'preferred_locale' => 'fr',
    ]);

    $this->actingAs($admin)
        ->withSession(['locale' => 'fr'])
        ->get(route('admin.languages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'fr')
            ->where('isRtl', false),
        );
});

test('unsupported or path-traversal locales are strictly rejected and safely fall back to english', function (): void {
    foreach (['../../etc/passwd', 'xx-INVALID', '<script>alert(1)</script>', 'en/../vi'] as $malicious) {
        $this->post(route('locale.update'), ['locale' => $malicious])
            ->assertSessionHasErrors('locale');
    }

    // Tampered cookie safely falls back to English
    $this->withUnencryptedCookie('syncviet_locale', '../../.env')
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('isRtl', false),
        );
});

test('switching language never alters pending payment amount or currency, referral attribution, or auth session', function (): void {
    $member = User::factory()->create(['preferred_locale' => 'en']);
    $walletService = app(WalletService::class);
    $payment = $walletService->createTopUpCheckout($member, 250_000, 'vietqr', 'i18n-immutability-001');

    $this->actingAs($member)
        ->withCookie('sv_ref_code', 'SV-TEST99')
        ->post(route('locale.update'), ['locale' => 'ja'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($member);

    $refreshed = $payment->fresh();
    expect($refreshed->amount)->toBe(250_000)
        ->and($refreshed->currency)->toBe('VND')
        ->and($member->fresh()->preferred_locale)->toBe('ja');
});

test('notifications, emails, footer groups, and policy titles localize to recipient locale without leaking vietnamese to international users', function (): void {
    $actor = User::factory()->create(['name' => 'Alex', 'username' => 'alex_dev']);
    $jpUser = User::factory()->create(['preferred_locale' => 'ja']);
    $arUser = User::factory()->create(['preferred_locale' => 'ar']);

    // HasLocalePreference guarantees queue/mail isolation per recipient
    expect($jpUser->preferredLocale())->toBe('ja')
        ->and($arUser->preferredLocale())->toBe('ar');

    // SocialActivityNotification translates dynamically per user locale (never Vietnamese for ja/ar!)
    $jpUser->notify(new SocialActivityNotification(
        eventKey: 'user.followed',
        titleVi: "{$actor->name} đã bắt đầu theo dõi bạn.",
        titleEn: "{$actor->name} started following you.",
        url: '/u/alex_dev',
        actorName: $actor->name,
        actorUsername: $actor->username,
        titleKey: ':name started following you.',
        titleParams: ['name' => $actor->name],
    ));

    $notifService = app(NotificationService::class);
    $jpList = $notifService->forUser($jpUser, 'ja');
    $viList = $notifService->forUser($jpUser, 'vi');
    $arList = $notifService->forUser($jpUser, 'ar');

    expect($jpList[0]['title'])->toContain('Alex')->not->toContain('theo dõi')
        ->and($viList[0]['title'])->toContain('Alex')->toContain('theo dõi')
        ->and($arList[0]['title'])->toContain('Alex')->not->toContain('theo dõi');

    // VerifyEmail notification respects each user's preferredLocale independently
    Notification::fake();
    $jpUser->sendEmailVerificationNotification();
    $arUser->sendEmailVerificationNotification();

    Notification::assertSentTo($jpUser, VerifyEmail::class, function (VerifyEmail $notification, array $channels, User $notifiable): bool {
        $prev = App::getLocale();
        App::setLocale($notifiable->preferredLocale());
        try {
            $mail = $notification->toMail($notifiable);
        } finally {
            App::setLocale($prev);
        }

        return $mail->subject === (string) Lang::get('Verify your email address', [], 'ja')
            && $mail->subject !== 'Verify your email address';
    });

    Notification::assertSentTo($arUser, VerifyEmail::class, function (VerifyEmail $notification, array $channels, User $notifiable): bool {
        $prev = App::getLocale();
        App::setLocale($notifiable->preferredLocale());
        try {
            $mail = $notification->toMail($notifiable);
        } finally {
            App::setLocale($prev);
        }

        return $mail->subject === (string) Lang::get('Verify your email address', [], 'ar')
            && $mail->subject !== 'Verify your email address';
    });

    // FooterGroup & PolicyPage localize across all 15 languages (never Vietnamese for fr/ja!)
    expect(FooterGroup::Legal->label('fr'))->not->toBe('Pháp lý & Chính sách')
        ->and(FooterGroup::Platform->label('ja'))->not->toBe('Nền tảng');

    $policy = PolicyPage::query()->updateOrCreate(
        ['slug' => 'terms-of-service'],
        [
            'title_vi' => 'Điều khoản dịch vụ',
            'title_en' => 'Terms of Service',
            'summary_vi' => 'Tóm tắt tiếng Việt',
            'summary_en' => 'English summary',
            'content_vi' => '<p>Nội dung tiếng Việt</p>',
            'content_en' => '<p>English content</p>',
            'status' => PolicyStatus::Published,
            'is_visible' => true,
            'footer_group' => FooterGroup::Legal,
            'sort_order' => 1,
        ],
    );

    expect($policy->titleForLocale('vi'))->toBe('Điều khoản dịch vụ')
        ->and($policy->titleForLocale('ja'))->toBe((string) Lang::get('Terms of Service', [], 'ja'))
        ->and($policy->titleForLocale('ja'))->not->toBe('Điều khoản dịch vụ')
        ->and($policy->contentForLocale('ja'))->toBe('<p>English content</p>');

    // FooterMenuService cache isolation returns distinct labels per locale
    $footerService = app(FooterMenuService::class);
    $footerService->flushCache();
    $enFooter = $footerService->shared('en');
    $viFooter = $footerService->shared('vi');
    expect($enFooter['year'])->toBe($viFooter['year']);
});

test('blade error pages render localized copy and rtl direction for arabic', function (): void {
    $this->withSession(['locale' => 'ar'])
        ->get('/this-route-definitely-does-not-exist-404')
        ->assertNotFound()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee((string) Lang::get('Back to home', [], 'ar'), false);

    $this->withSession(['locale' => 'ja'])
        ->get('/this-route-definitely-does-not-exist-404')
        ->assertNotFound()
        ->assertSee('lang="ja"', false)
        ->assertSee('dir="ltr"', false)
        ->assertSee((string) Lang::get('Back to home', [], 'ja'), false);
});

test('admin localization center enforces permissions, sanitizes html on import, exports csv/json reports, and supports override rollback', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();

    // Non-staff denied
    $this->actingAs($member)->get(route('admin.languages.index'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.languages.audit-report'))->assertForbidden();

    // Admin views coverage dashboard + key explorer
    $this->actingAs($admin)
        ->get(route('admin.languages.index', ['browse_locale' => 'vi', 'browse_module' => 'auth']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Languages/Index')
            ->where('auditSummary.default_locale', 'en')
            ->where('auditSummary.fallback_locale', 'en')
            ->where('keyBrowser.locale', 'vi'),
        );

    // Export CSV & JSON audit reports
    $this->actingAs($admin)
        ->get(route('admin.languages.audit-report', ['format' => 'csv']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $this->actingAs($admin)
        ->get(route('admin.languages.audit-report', ['format' => 'json']))
        ->assertOk()
        ->assertJsonPath('default_locale', 'en');

    // Import strips XSS script tags safely and rejects unknown schema keys
    $this->actingAs($admin)
        ->post(route('admin.languages.import', 'vi'), [
            'json_payload' => json_encode([
                'nav.home' => '<script>alert(1)</script>Trang chủ SyncViet',
                'unknown.injected.key' => 'Should be ignored',
            ]),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $override = TranslationOverride::query()
        ->where('language_code', 'vi')
        ->where('translation_key', 'nav.home')
        ->firstOrFail();

    expect($override->value)->toBe('alert(1)Trang chủ SyncViet')
        ->and($override->value)->not->toContain('<script>')
        ->and(TranslationOverride::query()->where('translation_key', 'unknown.injected.key')->exists())->toBeFalse();

    // Rollback removes overrides cleanly and invalidates locale cache
    $this->actingAs($admin)
        ->post(route('admin.languages.rollback', 'vi'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(TranslationOverride::query()->where('language_code', 'vi')->count())->toBe(0);
    expect(app(LanguageService::class)->overridesForLocale('vi'))->toBeEmpty();

    // Default language (en) cannot be disabled
    $en = Language::query()->where('code', 'en')->firstOrFail();
    $this->actingAs($admin)
        ->post(route('admin.languages.update', 'en'), [
            'is_enabled' => false,
            'is_default' => $en->is_default,
            'sort_order' => $en->sort_order,
        ])
        ->assertSessionHasErrors('is_enabled');
});
