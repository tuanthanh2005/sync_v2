<?php

use App\Models\SystemSetting;
use App\Notifications\AuthOtpVerificationNotification;
use App\Services\Seo\SeoConfigService;
use App\Services\System\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;

uses(RefreshDatabase::class);

test('sysnoxis brand asset suite exists internally with valid icons and versioned manifest', function (): void {
    $requiredAssets = [
        'brand/sysnoxis-icon.svg',
        'brand/sysnoxis-icon-dark.svg',
        'brand/sysnoxis-icon-mono.svg',
        'brand/sysnoxis-icon-transparent.svg',
        'brand/sysnoxis-logo-light.svg',
        'brand/sysnoxis-logo-dark.svg',
        'brand/sysnoxis-logo-mono.svg',
        'brand/sysnoxis-brand-light.svg',
        'brand/sysnoxis-brand-dark.svg',
        'favicon.svg',
        'favicon.ico',
        'icons/favicon-16x16.png',
        'icons/favicon-32x32.png',
        'apple-touch-icon.png',
        'icons/icon-192.png',
        'icons/icon-512.png',
        'icons/icon-maskable-512.png',
    ];

    foreach ($requiredAssets as $relativePath) {
        expect(file_exists(public_path($relativePath)))->toBeTrue("Missing brand asset: {$relativePath}");
    }

    $faviconSvg = (string) file_get_contents(public_path('favicon.svg'));
    expect($faviconSvg)
        ->toContain('#4F46E5')
        ->toContain('#818CF8')
        ->not->toContain('#FF2D20');

    /** @var array{name: string, short_name: string, description: string, icons: list<array{src: string, sizes: string, purpose: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['name'])->toBe('SysNoxis')
        ->and($manifest['short_name'])->toBe('SysNoxis')
        ->and($manifest['description'])->toContain('Connect Beyond Borders.')
        ->and($manifest['icons'][0]['src'])->toContain('?v=sysnoxis-2');
});

test('centralized branding payload exposes versioned sysnoxis defaults and honors admin overrides', function (): void {
    $service = app(SystemSettingService::class);
    $payload = $service->publicPayload();

    expect($payload['siteSlogan'])->toBe('Connect Beyond Borders.')
        ->and($payload['branding']['version'])->toBe('sysnoxis-2')
        ->and($payload['branding']['logoLightUrl'])->toBeNull()
        ->and($payload['branding']['logoDarkUrl'])->toBeNull()
        ->and($payload['branding']['faviconUrl'])->toBeNull()
        ->and($payload['branding']['defaults']['logoLight'])->toBe('/brand/sysnoxis-logo-light.svg?v=sysnoxis-2')
        ->and($payload['branding']['defaults']['logoDark'])->toBe('/brand/sysnoxis-logo-dark.svg?v=sysnoxis-2');

    SystemSetting::query()->create([
        'group' => 'branding',
        'key' => 'favicon_path',
        'value' => 'branding/custom-favicon.png',
        'type' => 'string',
        'is_public' => true,
    ]);
    SystemSetting::query()->create([
        'group' => 'branding',
        'key' => 'og_image_path',
        'value' => 'branding/custom-og.png',
        'type' => 'string',
        'is_public' => true,
    ]);
    $service->flushCache();

    $response = $this->get('/');
    $response->assertOk();
    expect((string) $response->getContent())->toContain('branding/custom-favicon.png');

    $ogUrl = app(SeoConfigService::class)->defaultOgImageUrl();
    expect($ogUrl)->toContain('branding/custom-og.png');
});

test('default open graph svg and branded maintenance response render sysnoxis identity', function (): void {
    $ogResponse = $this->get('/og-default.svg');
    $ogResponse->assertOk();
    $ogResponse->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');
    expect((string) $ogResponse->getContent())
        ->toContain('SysNoxis')
        ->toContain('Connect Beyond Borders.')
        ->toContain('M33 5H15A8 8 0 0 0 15 21');

    SystemSetting::query()->create([
        'group' => 'features',
        'key' => 'maintenance_enabled',
        'value' => '1',
        'type' => 'boolean',
        'is_public' => true,
    ]);
    app(SystemSettingService::class)->flushCache();

    $htmlResponse = $this->get('/');
    $htmlResponse->assertStatus(503);
    expect((string) $htmlResponse->getContent())
        ->toContain('SysNoxis')
        ->toContain('M33 5H15A8 8 0 0 0 15 21');

    $jsonResponse = $this->getJson('/');
    $jsonResponse->assertStatus(503)->assertJsonStructure(['message']);
});

test('email header renders internal sysnoxis logo and slogan without external laravel logo', function (): void {
    $notification = new AuthOtpVerificationNotification(
        otpCode: '123456',
        confirmationUrl: 'https://sysnoxis.com/verify-email/confirm/test-token',
        expireMinutes: 5,
    );

    $mailMessage = $notification->toMail((object) ['email' => 'member@example.com']);
    $html = (string) app(Markdown::class)->render('notifications::email', $mailMessage->data());

    expect($html)
        ->toContain('/icons/icon-192.png?v=sysnoxis-2')
        ->toContain('SysNoxis')
        ->toContain('Connect Beyond Borders.')
        ->not->toContain('laravel.com/img/notification-logo');
});
