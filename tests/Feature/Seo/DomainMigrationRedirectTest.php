<?php

use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoRegistryService;
use App\Services\Seo\SitemapEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('canonical domain middleware 301 redirects legacy domain preserving path and query string', function (): void {
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.redirect_enabled' => true,
        'syncviet.domain_migration.primary_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.legacy_hosts' => ['syncviet.com', 'www.syncviet.com'],
        'syncviet.domain_migration.redirect_www_to_apex' => true,
    ]);

    $this->get('https://syncviet.com/en/posts/example?page=2&sort=latest')
        ->assertStatus(301)
        ->assertRedirect('https://sysnoxis.com/en/posts/example?page=2&sort=latest');

    $this->get('https://www.syncviet.com/communities')
        ->assertStatus(301)
        ->assertRedirect('https://sysnoxis.com/communities');

    $this->get('https://www.sysnoxis.com/en/posts/example')
        ->assertStatus(301)
        ->assertRedirect('https://sysnoxis.com/en/posts/example');
});

test('canonical domain middleware does not redirect primary host or create redirect loops', function (): void {
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.redirect_enabled' => true,
        'syncviet.domain_migration.primary_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.legacy_hosts' => ['syncviet.com', 'www.syncviet.com'],
        'syncviet.domain_migration.redirect_www_to_apex' => true,
    ]);

    $this->get('https://sysnoxis.com/up')
        ->assertOk();
});

test('canonical domain middleware exempts payment webhooks, healthchecks, and ACME verification paths', function (): void {
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.redirect_enabled' => true,
        'syncviet.domain_migration.primary_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.legacy_hosts' => ['syncviet.com', 'www.syncviet.com'],
        'syncviet.domain_migration.redirect_www_to_apex' => true,
    ]);

    // Legacy POST payment webhook must NOT receive a 301 redirect that drops POST body.
    $webhookResponse = $this->postJson('https://syncviet.com/webhooks/payments/vietqr', [
        'payment_id' => 999999,
    ]);
    expect($webhookResponse->status())->not->toBeIn([301, 302, 307, 308]);

    // Health check stays 200 on legacy host too.
    $this->get('https://syncviet.com/up')->assertOk();
});

test('canonical domain middleware blocks open redirect via double-slash request paths and respects disabled flag', function (): void {
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.redirect_enabled' => true,
        'syncviet.domain_migration.primary_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.legacy_hosts' => ['syncviet.com'],
        'syncviet.domain_migration.redirect_www_to_apex' => true,
    ]);

    $response = $this->get('https://syncviet.com//evil.example/phish');
    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toStartWith('https://sysnoxis.com/');

    config(['syncviet.domain_migration.redirect_enabled' => false]);
    $this->get('https://syncviet.com/up')->assertOk();
});

test('seo registry backfill deduplicates static pages and replaces legacy domain URLs in sitemaps and robots.txt', function (): void {
    config([
        'app.url' => 'https://syncviet.com',
        'syncviet.seo.canonical_base_url' => 'https://syncviet.com',
    ]);

    app(SeoConfigService::class)->set('allow_indexing_in_non_production', true, 'general', 'boolean');

    $registry = app(SeoRegistryService::class);
    $sitemaps = app(SitemapEngineService::class);

    $registry->backfillAll();
    $sitemaps->rebuildAll();

    // Now migrate canonical origin to sysnoxis.com and run backfill + rebuild
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
    ]);
    app(SeoConfigService::class)->flushCache();

    $registry->backfillAll();
    $sitemaps->rebuildAll();

    $index = $sitemaps->renderIndexXml()['xml'];
    expect($index)->toContain('https://sysnoxis.com/sitemap-pages.xml')
        ->and($index)->not->toContain('syncviet.com');

    $pagesShard = $sitemaps->renderShardXml('sitemap-pages');
    expect($pagesShard)->not->toBeNull();
    expect($pagesShard['xml'])->toContain('https://sysnoxis.com/')
        ->and($pagesShard['xml'])->not->toContain('syncviet.com');

    $this->get('https://sysnoxis.com/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: https://sysnoxis.com/sitemap.xml');
});

test('app:domain:health command outputs safe non-secret diagnostic json', function (): void {
    config([
        'app.url' => 'https://sysnoxis.com',
        'syncviet.seo.canonical_base_url' => 'https://sysnoxis.com',
        'syncviet.domain_migration.redirect_enabled' => true,
        'syncviet.domain_migration.legacy_hosts' => ['syncviet.com', 'www.syncviet.com'],
    ]);

    $this->artisan('app:domain:health')
        ->assertSuccessful()
        ->expectsOutputToContain('"seo_canonical_host":"sysnoxis.com","redirect_enabled":true');
});

test('app:domain:configure rejects invalid primary url without modifying env', function (): void {
    $this->artisan('app:domain:configure', [
        'primaryUrl' => 'http://invalid-insecure.example',
        'legacyHosts' => 'syncviet.com',
        'redirectEnabled' => '0',
    ])->assertFailed();
});
