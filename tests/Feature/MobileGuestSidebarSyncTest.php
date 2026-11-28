<?php

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Models\SystemSetting;
use App\Services\Policy\FooterMenuService;
use App\Services\Security\RbacService;
use App\Services\System\SystemSettingService;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    (new LanguageSeeder)->run();
});

test('guests receive public footer help links and support email while preserving strict auth/admin boundaries', function (): void {
    SystemSetting::query()->updateOrCreate(
        ['key' => 'contact_email'],
        [
            'group' => 'general',
            'value' => 'support@sysnoxis.com',
            'type' => 'string',
            'is_public' => true,
        ],
    );
    app(SystemSettingService::class)->flushCache();

    $helpPolicy = PolicyPage::factory()->create([
        'slug' => 'help-center',
        'title_en' => 'Help Center',
        'title_vi' => 'Trung tâm trợ giúp',
        'status' => PolicyStatus::Published,
        'is_visible' => true,
    ]);

    FooterLink::query()->create([
        'policy_page_id' => $helpPolicy->id,
        'label_en' => 'Help Center',
        'label_vi' => 'Trung tâm trợ giúp',
        'url' => '/policies/help-center',
        'footer_group' => FooterGroup::Legal->value,
        'placement' => 'both',
        'sort_order' => 1,
        'is_visible' => true,
        'open_in_new_tab' => false,
    ]);
    app(FooterMenuService::class)->flushCache();

    $guestHome = $this->get('/');
    $guestHome->assertOk();
    $pageProp = $guestHome->viewData('page');

    expect($pageProp['props']['auth']['user'])->toBeNull()
        ->and($pageProp['props']['footer']['supportEmail'])->toBe('support@sysnoxis.com')
        ->and($pageProp['props']['footer']['links'])->not->toBeEmpty()
        ->and($pageProp['props']['footer']['links'][0]['href'])->toContain('/policies/help-center');

    // All public guest destinations reachable from synchronized Mobile & Desktop Sidebars return 200
    $this->get('/premium')->assertOk();
    $this->get('/advertising')->assertOk();
    $this->get('/advertising/boost')->assertOk();
    $this->get('/advertising/banner')->assertOk();
    $this->get('/services')->assertOk();
    $this->get('/policies/help-center')->assertOk();

    // Private member & admin destinations remain blocked for Guests
    $this->get('/wallet')->assertRedirect('/login');
    $this->get('/payments')->assertRedirect('/login');
    $this->get('/settings/profile')->assertRedirect('/login');
    $this->get('/settings/security')->assertRedirect('/login');
    $this->get('/admin')->assertRedirect('/login');
});
