<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mobile viewport meta enables safe area without blocking accessibility zoom', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)
        ->toContain('width=device-width, initial-scale=1, viewport-fit=cover')
        ->toContain('rel="manifest" href="/manifest.webmanifest"')
        ->toContain('name="theme-color"')
        ->not->toContain('user-scalable=no')
        ->not->toContain('maximum-scale=1');
});

test('security headers permit same-origin service worker, manifest, and blob previews', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("worker-src 'self'")
        ->toContain("manifest-src 'self'")
        ->toContain("img-src 'self' data: blob: https:");
});

test('pwa manifest, icons, offline page, and secure service worker exist and exclude sensitive routes', function (): void {
    $manifestPath = public_path('manifest.webmanifest');
    $swPath = public_path('sw.js');
    $offlinePath = public_path('offline.html');

    expect(file_exists($manifestPath))->toBeTrue();
    expect(file_exists($swPath))->toBeTrue();
    expect(file_exists($offlinePath))->toBeTrue();
    expect(file_exists(public_path('icons/icon-192.png')))->toBeTrue();
    expect(file_exists(public_path('icons/icon-512.png')))->toBeTrue();
    expect(file_exists(public_path('icons/icon-maskable-512.png')))->toBeTrue();
    expect(file_exists(public_path('apple-touch-icon.png')))->toBeTrue();

    /** @var array{display: string, icons: list<array{sizes: string, purpose: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['display'])->toBe('standalone');
    expect(count($manifest['icons']))->toBeGreaterThanOrEqual(3);

    $sw = (string) file_get_contents($swPath);
    expect($sw)
        ->toContain("'/admin'")
        ->toContain("'/wallet'")
        ->toContain("'/payments'")
        ->toContain("'/webhooks'")
        ->toContain("'/messages'")
        ->toContain("request.headers.has('X-Inertia')")
        ->toContain("request.method !== 'GET'");
});

test('all 15 frontend locale bundles maintain 100 percent key parity including mobile and pwa keys', function (): void {
    $locales = ['en', 'vi', 'es', 'pt-BR', 'id', 'hi', 'th', 'fil', 'fr', 'de', 'ja', 'ko', 'zh-CN', 'ar', 'tr'];

    $flatten = static function (array $data, string $prefix = '') use (&$flatten): array {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $result = array_merge($result, $flatten($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    };

    $enFile = resource_path('js/i18n/locales/en.json');
    /** @var array<string, mixed> $enRaw */
    $enRaw = json_decode((string) file_get_contents($enFile), true, 512, JSON_THROW_ON_ERROR);
    $enKeys = array_keys($flatten($enRaw));

    expect($enKeys)->toContain('nav.explore', 'nav.createPostShort', 'pwa.installApp', 'common.offlineBanner');

    foreach ($locales as $code) {
        $path = resource_path("js/i18n/locales/{$code}.json");
        expect(file_exists($path))->toBeTrue();
        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $keys = array_keys($flatten($raw));
        expect(array_diff($enKeys, $keys))->toBeEmpty("Missing keys in {$code}");
    }
});
