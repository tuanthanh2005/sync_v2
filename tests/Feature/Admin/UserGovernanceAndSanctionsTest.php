<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSanction;
use App\Services\Security\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
});

test('admin can ban and unban users, revoking active sessions immediately on ban', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'active-member-session-001',
        'user_id' => $member->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'TestBrowser/1.0',
        'payload' => 'serialized',
        'last_activity' => time(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.sanction', $member->id), [
            'action' => 'ban',
            'reason' => 'Repeated severe policy violations',
            'duration' => 'permanent',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $member->refresh();
    expect($member->status)->toBe(UserStatus::Banned)
        ->and($member->ban_reason)->toBe('Repeated severe policy violations')
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);

    // Banned user cannot access protected dashboard
    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    // Unban restores active status and revokes active ban sanction
    $this->actingAs($admin)
        ->post(route('admin.users.sanction', $member->id), [
            'action' => 'unban',
            'reason' => 'Approved after manual review',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $member->refresh();
    expect($member->status)->toBe(UserStatus::Active)
        ->and($member->ban_reason)->toBeNull()
        ->and(UserSanction::query()->where('user_id', $member->id)->where('type', 'ban')->where('is_active', true)->count())->toBe(0);
});

test('timed suspension and capability restrictions auto-expire via scheduler and middleware', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();

    // Suspend for 1 hour
    $this->actingAs($admin)
        ->post(route('admin.users.sanction', $member->id), [
            'action' => 'suspend',
            'reason' => 'Temporary cooldown for spamming',
            'duration' => '1h',
        ])
        ->assertSessionHasNoErrors();

    $member->refresh();
    expect($member->status)->toBe(UserStatus::Suspended)
        ->and($member->suspended_until)->not->toBeNull()
        ->and($member->isActive())->toBeFalse();

    // Travel 2 hours ahead and run scheduler expiry command
    $this->travel(2)->hours();
    $this->artisan('app:sanctions:expire')->assertSuccessful();

    $member->refresh();
    expect($member->status)->toBe(UserStatus::Active)
        ->and($member->suspended_until)->toBeNull()
        ->and($member->isActive())->toBeTrue();
});

test('backend enforces granular posting, commenting, community, and messaging restrictions', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->for($other, 'author')->create(['visibility' => 'public']);

    // Restrict posting
    $this->actingAs($admin)->post(route('admin.users.sanction', $member->id), [
        'action' => 'restrict_post',
        'reason' => 'Spam posting',
        'duration' => '24h',
    ])->assertSessionHasNoErrors();

    $this->actingAs($member->fresh())
        ->post(route('posts.store'), [
            'content' => 'Blocked post attempt while restricted',
            'category' => 'general',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('content');

    // Restrict commenting
    $this->actingAs($admin)->post(route('admin.users.sanction', $member->id), [
        'action' => 'restrict_comment',
        'reason' => 'Abusive comments',
        'duration' => '24h',
    ])->assertSessionHasNoErrors();

    $this->actingAs($member->fresh())
        ->post(route('comments.store', $post), ['content' => 'Blocked comment'])
        ->assertSessionHasErrors('content');

    // Restrict community creation
    $this->actingAs($admin)->post(route('admin.users.sanction', $member->id), [
        'action' => 'restrict_community',
        'reason' => 'Spam communities',
        'duration' => '24h',
    ])->assertSessionHasNoErrors();

    $this->actingAs($member->fresh())
        ->post(route('communities.store'), [
            'name' => 'Blocked Community',
            'category' => 'general',
        ])
        ->assertSessionHasErrors('name');

    // Restrict messaging
    $this->actingAs($admin)->post(route('admin.users.sanction', $member->id), [
        'action' => 'restrict_message',
        'reason' => 'Unsolicited DMs',
        'duration' => '24h',
    ])->assertSessionHasNoErrors();

    $this->actingAs($member->fresh())
        ->post(route('messages.start', $other->username))
        ->assertSessionHasErrors('recipient');

    // Lift all restrictions and verify posting succeeds again
    $this->actingAs($admin)->post(route('admin.users.sanction', $member->id), [
        'action' => 'lift_restriction',
        'reason' => 'Cooldown finished',
    ])->assertSessionHasNoErrors();

    $this->actingAs($member->fresh())
        ->post(route('posts.store'), [
            'content' => 'Allowed post after restriction lifted',
            'category' => 'general',
            'visibility' => 'public',
        ])
        ->assertSessionHasNoErrors();
});

test('prevents privilege escalation and protects the last super administrator', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $modRole = Role::query()->where('slug', 'moderator')->firstOrFail();

    $superAdmin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $adminRole->id]);
    $moderator = User::factory()->moderator()->create(['rbac_role_id' => $modRole->id]);
    $member = User::factory()->create();

    // Moderator cannot access role management or assign roles
    $this->actingAs($moderator)
        ->post(route('admin.users.role', $member->id), ['role' => 'admin'])
        ->assertForbidden();

    // Moderator cannot ban users or suspend an admin
    $this->actingAs($moderator)
        ->post(route('admin.users.sanction', $member->id), [
            'action' => 'ban',
            'reason' => 'Unauthorized ban attempt',
        ])
        ->assertForbidden();

    $this->actingAs($moderator)
        ->post(route('admin.users.sanction', $admin->id), [
            'action' => 'suspend',
            'reason' => 'Trying to suspend higher rank',
            'duration' => '1h',
        ])
        ->assertSessionHasErrors('user');

    // Regular Admin cannot promote someone to Super Admin or demote Super Admin
    $this->actingAs($admin)
        ->post(route('admin.users.role', $member->id), ['role' => 'super_admin'])
        ->assertForbidden();

    // Even Super Admin cannot self-demote or remove the last super_admin
    $this->actingAs($superAdmin)
        ->post(route('admin.users.role', $superAdmin->id), ['role' => 'user'])
        ->assertSessionHasErrors('role');

    expect($superAdmin->fresh()->isSuperAdmin())->toBeTrue()
        ->and($member->fresh()->role)->toBe(UserRole::User);
});
