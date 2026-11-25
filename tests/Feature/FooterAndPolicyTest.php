<?php

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\AuditLog;
use App\Models\FooterLink;
use App\Models\PolicyPage;
use App\Models\User;
use App\Services\Policy\FooterMenuService;
use Database\Seeders\PolicyFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('draft policies return 404 publicly and are omitted from shared footer links', function (): void {
    $this->seed(PolicyFoundationSeeder::class);

    $this->get(route('policies.show', 'privacy-policy'))
        ->assertNotFound();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('footer.links', [])
            ->where('footer.groups', []),
        );
});

test('admin with 2FA can publish policy pages with backend XSS sanitization and localized footer output', function (): void {
    $this->seed(PolicyFoundationSeeder::class);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(route('admin.policies.update', 'privacy-policy'), [
            'title_vi' => 'Chính sách bảo mật',
            'title_en' => 'Privacy Policy',
            'summary_vi' => 'Tóm tắt tiếng Việt',
            'summary_en' => 'English summary',
            'content_vi' => '<p>Nội dung an toàn</p><script>alert("xss")</script><a href="javascript:alert(1)" onclick="steal()">Link</a>',
            'content_en' => '<p>Safe content</p><iframe src="https://evil.example"></iframe>',
            'status' => PolicyStatus::Published->value,
            'is_visible' => true,
            'footer_group' => FooterGroup::Legal->value,
            'sort_order' => 5,
        ])
        ->assertRedirect();

    $policy = PolicyPage::query()->where('slug', 'privacy-policy')->firstOrFail();

    expect($policy->status)->toBe(PolicyStatus::Published)
        ->and($policy->content_vi)->not->toContain('<script>')
        ->and($policy->content_vi)->not->toContain('javascript:')
        ->and($policy->content_vi)->not->toContain('onclick')
        ->and($policy->content_en)->not->toContain('<iframe')
        ->and(AuditLog::query()->where('action', 'policy.published_or_updated')->exists())->toBeTrue();

    app(FooterMenuService::class)->flushCache();

    $this->get(route('policies.show', 'privacy-policy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('policies/Show')
            ->where('policy.slug', 'privacy-policy')
            ->where('policy.status', 'published')
            ->has('footer.links', 1),
        );
});

test('non-admin users cannot access policy management or modify footer links', function (): void {
    $this->seed(PolicyFoundationSeeder::class);
    $member = User::factory()->create();
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($member)
        ->get(route('admin.policies.index'))
        ->assertForbidden();

    $this->actingAs($moderator)
        ->get(route('admin.policies.index'))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.footer-links.store'), [
            'label_vi' => 'Hack',
            'label_en' => 'Hack',
            'url' => '/hack',
            'footer_group' => FooterGroup::Support->value,
            'sort_order' => 1,
            'is_visible' => true,
        ])
        ->assertForbidden();
});

test('admin can manage custom footer links and dangerous URLs are rejected', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->from(route('admin.policies.index'))
        ->post(route('admin.footer-links.store'), [
            'label_vi' => 'Liên kết độc hại',
            'label_en' => 'Malicious Link',
            'url' => 'javascript:alert(1)',
            'footer_group' => FooterGroup::Support->value,
            'sort_order' => 1,
            'is_visible' => true,
        ])
        ->assertSessionHasErrors('url');

    $this->actingAs($admin)
        ->post(route('admin.footer-links.store'), [
            'label_vi' => 'Trạng thái hệ thống',
            'label_en' => 'System Status',
            'url' => '/up',
            'footer_group' => FooterGroup::Support->value,
            'sort_order' => 15,
            'is_visible' => true,
            'open_in_new_tab' => false,
        ])
        ->assertRedirect();

    $link = FooterLink::query()->where('label_en', 'System Status')->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('admin.footer-links.update', $link), [
            'label_vi' => 'Trạng thái mới',
            'label_en' => 'Updated Status',
            'url' => '/up',
            'footer_group' => FooterGroup::Platform->value,
            'sort_order' => 2,
            'is_visible' => true,
        ])
        ->assertRedirect();

    expect($link->fresh()?->label_en)->toBe('Updated Status');

    $this->actingAs($admin)
        ->delete(route('admin.footer-links.destroy', $link))
        ->assertRedirect();

    expect(FooterLink::query()->find($link->id))->toBeNull();
});

test('locale switcher updates session and inertia shared locale between vi and en', function (): void {
    $this->from(route('home'))
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('locale', 'en');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en'),
        );
});
