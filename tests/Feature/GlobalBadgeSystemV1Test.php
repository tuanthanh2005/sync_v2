<?php

use App\Enums\AccountType;
use App\Enums\MemberBadgeType;
use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Enums\PostVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Admin\AdminBadgeManagementService;
use App\Services\Admin\AdminUserService;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use App\Services\Social\FeedService;
use App\Services\Social\TrendingRankingService;
use App\Services\Social\UserBadgeResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
});

function createSuperAdminUser(): User
{
    $role = Role::query()->where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create([
        'role' => UserRole::Admin,
        'rbac_role_id' => $role->id,
        'status' => UserStatus::Active,
        'two_factor_secret' => encrypt('TESTSECRET123456'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

function createStandardAdminUser(): User
{
    $role = Role::query()->where('slug', 'admin')->firstOrFail();

    return User::factory()->create([
        'role' => UserRole::Admin,
        'rbac_role_id' => $role->id,
        'status' => UserStatus::Active,
        'two_factor_secret' => encrypt('TESTSECRET123456'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

test('standard user shows zero badges and legacy is_verified_badge never leaks official checkmark', function (): void {
    $member = User::factory()->create([
        'role' => UserRole::User,
        'status' => UserStatus::Active,
    ]);
    $member->profile()->create([
        'is_verified_badge' => true,
    ]);

    $resolved = app(UserBadgeResolverService::class)->resolveForUser($member->fresh(['profile', 'rbacRole']));

    expect($resolved['compact'])->toBe([])
        ->and($resolved['profile'])->toBe([])
        ->and($resolved['primary'])->toBeNull()
        ->and($resolved['ai'])->toBeNull();
});

test('only super admin can grant and revoke official, staff, or moderator badges with required audit log', function (): void {
    $superAdmin = createSuperAdminUser();
    $regularAdmin = createStandardAdminUser();
    $targetOfficial = User::factory()->create([
        'account_type' => AccountType::SystemOfficial,
        'role' => UserRole::User,
    ]);

    // Standard admin cannot grant official account badge via controller or service
    $this->actingAs($regularAdmin)
        ->post('/admin/badges/grant', [
            'username' => $targetOfficial->username,
            'badge_type' => MemberBadgeType::OfficialAccount->value,
            'reason' => 'Unauthorized escalation attempt',
        ])
        ->assertForbidden();

    // Super Admin successfully grants Official Account badge
    $this->actingAs($superAdmin)
        ->post('/admin/badges/grant', [
            'username' => $targetOfficial->username,
            'badge_type' => MemberBadgeType::OfficialAccount->value,
            'reason' => 'Verified SysNoxis official brand announcement account',
        ])
        ->assertRedirect();

    $badge = UserBadge::query()
        ->where('user_id', $targetOfficial->id)
        ->where('badge_type', MemberBadgeType::OfficialAccount->value)
        ->first();

    expect($badge)->not->toBeNull()
        ->and($badge->status)->toBe(UserBadge::STATUS_ACTIVE)
        ->and($badge->color)->toBe('#EAB308')
        ->and($badge->granted_by)->toBe($superAdmin->id);

    // Duplicate active badge grant is blocked cleanly
    expect(fn () => app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $targetOfficial,
        MemberBadgeType::OfficialAccount,
        'Duplicate check',
    ))->toThrow(ValidationException::class);

    // Official Account badge NEVER grants Admin Panel RBAC permissions
    expect($targetOfficial->fresh()->isAdmin())->toBeFalse()
        ->and($targetOfficial->fresh()->hasPermission('users.view'))->toBeFalse();

    $this->actingAs($targetOfficial)->get('/admin')->assertForbidden();

    // Audit log recorded
    expect(AuditLog::query()->where('action', 'admin.badge.granted')->where('auditable_id', $badge->id)->exists())->toBeTrue();

    // Revoking preserves row + history
    $this->actingAs($superAdmin)
        ->post("/admin/badges/{$badge->id}/revoke", [
            'reason' => 'Account retired cleanly',
        ])
        ->assertRedirect();

    $revoked = $badge->fresh();
    expect($revoked)->not->toBeNull()
        ->and($revoked->status)->toBe(UserBadge::STATUS_REVOKED)
        ->and($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->revoke_reason)->toBe('Account retired cleanly')
        ->and(AuditLog::query()->where('action', 'admin.badge.revoked')->where('auditable_id', $badge->id)->exists())->toBeTrue();
});

test('non-public staff accounts do not auto-show gold checkmark and regular members cannot receive staff or moderator badges', function (): void {
    $superAdmin = createSuperAdminUser();
    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $modRole = Role::query()->where('slug', 'moderator')->firstOrFail();

    $plainMember = User::factory()->create(['role' => UserRole::User]);
    $hiddenAdmin = User::factory()->create(['role' => UserRole::Admin, 'rbac_role_id' => $adminRole->id]);
    $moderator = User::factory()->create(['role' => UserRole::Moderator, 'rbac_role_id' => $modRole->id]);

    // Hidden internal admin without explicit official_staff badge shows zero staff badge publicly
    $hiddenResolved = app(UserBadgeResolverService::class)->resolveForUser($hiddenAdmin);
    expect($hiddenResolved['compact'])->toBe([]);

    // Regular member cannot be granted official_staff or moderator badge
    $service = app(AdminBadgeManagementService::class);
    expect(fn () => $service->grantBadge($superAdmin, $plainMember, MemberBadgeType::OfficialStaff, 'Invalid grant'))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->grantBadge($superAdmin, $plainMember, MemberBadgeType::Moderator, 'Invalid mod grant'))
        ->toThrow(ValidationException::class);

    // Moderator receives silver #94A3B8 Shield badge cleanly
    $service->grantBadge($superAdmin, $moderator, MemberBadgeType::Moderator, 'Platform safety moderator');
    $modResolved = app(UserBadgeResolverService::class)->resolveForUser($moderator);

    expect($modResolved['compact'])->toHaveCount(1)
        ->and($modResolved['compact'][0]['type'])->toBe('moderator')
        ->and($modResolved['compact'][0]['color'])->toBe('#94A3B8')
        ->and($modResolved['compact'][0]['icon'])->toBe('shield_check');
});

test('admin with active premium retains premium entitlements while showing only gold checkmark without blue collision', function (): void {
    $superAdmin = createSuperAdminUser();
    $adminWithPremium = User::factory()->create([
        'role' => UserRole::Admin,
        'rbac_role_id' => Role::query()->where('slug', 'admin')->value('id'),
        'premium_until' => now()->addDays(30),
    ]);

    app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $adminWithPremium,
        MemberBadgeType::OfficialStaff,
        'Approved public platform administrator',
    );

    expect($adminWithPremium->isPremium())->toBeTrue();

    $resolved = app(UserBadgeResolverService::class)->resolveForUser($adminWithPremium);
    $compactTypes = array_column($resolved['compact'], 'type');
    $profileTypes = array_column($resolved['profile'], 'type');

    expect($compactTypes)->toBe(['official_staff'])
        ->and($profileTypes)->toContain('official_staff')
        ->and($profileTypes)->not->toContain('premium_member');
});

test('official ai account displays both gold official checkmark and independent teal ai badge', function (): void {
    $superAdmin = createSuperAdminUser();
    $aiOfficial = User::factory()->create([
        'account_type' => AccountType::AiAgent,
        'ai_disclosure' => 'SysNoxis Official AI Assistant',
        'role' => UserRole::User,
    ]);

    app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $aiOfficial,
        MemberBadgeType::OfficialAccount,
        'Official SysNoxis AI assistant account',
    );

    $resolved = app(UserBadgeResolverService::class)->resolveForUser($aiOfficial);

    expect($resolved['compact'])->toHaveCount(2)
        ->and($resolved['compact'][0]['type'])->toBe('official_account')
        ->and($resolved['compact'][0]['color'])->toBe('#EAB308')
        ->and($resolved['compact'][1]['type'])->toBe('ai_account')
        ->and($resolved['compact'][1]['color'])->toBe('#14B8A6')
        ->and($resolved['compact'][1]['ai_disclosure'])->toBe('SysNoxis Official AI Assistant');
});

test('premium badge updates live on expiry and early member badge preserves star color without conflict', function (): void {
    $member = User::factory()->create([
        'premium_until' => now()->addDays(5),
    ]);
    UserBadge::query()->create([
        'user_id' => $member->id,
        'badge_type' => MemberBadgeType::EarlyMember->value,
        'status' => UserBadge::STATUS_ACTIVE,
        'label_key' => MemberBadgeType::EarlyMember->labelKey(),
        'icon' => MemberBadgeType::EarlyMember->iconKey(),
        'color' => MemberBadgeType::EarlyMember->colorHex(),
        'grant_source' => 'early_member_program',
        'granted_at' => now(),
        'is_public' => true,
        'metadata' => ['member_number' => 7],
    ]);

    $resolver = app(UserBadgeResolverService::class);
    $active = $resolver->resolveForUser($member);
    expect(array_column($active['compact'], 'type'))->toBe(['premium_member', 'early_member'])
        ->and($active['compact'][0]['color'])->toBe('#1D9BF0')
        ->and($active['compact'][1]['color'])->toBe('#A855F7')
        ->and($active['compact'][1]['member_number'])->toBe(7);

    // Expire Premium without touching user_badges cache -> live check hides Blue Checkmark immediately
    $member->forceFill(['premium_until' => now()->subMinute()])->save();
    $resolver->flushRequestMemo();
    $expired = $resolver->resolveForUser($member->fresh());

    expect(array_column($expired['compact'], 'type'))->toBe(['early_member'])
        ->and($expired['compact'][0]['color'])->toBe('#A855F7');
});

test('role removal and account ban automatically revoke authority badges and invalidate cache', function (): void {
    $superAdmin = createSuperAdminUser();
    $modRole = Role::query()->where('slug', 'moderator')->firstOrFail();
    $userRole = Role::query()->where('slug', 'user')->firstOrFail();

    $moderator = User::factory()->create([
        'role' => UserRole::Moderator,
        'rbac_role_id' => $modRole->id,
    ]);

    app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $moderator,
        MemberBadgeType::Moderator,
        'Moderator assignment',
    );

    // Warm cache
    $initial = app(UserBadgeResolverService::class)->resolveForUser($moderator);
    expect(array_column($initial['compact'], 'type'))->toBe(['moderator']);

    // Demote back to Member -> Moderator badge auto-revokes and cache flushes
    app(RbacService::class)->assignRole($superAdmin, $moderator, $userRole, app(AuditLogger::class));

    $afterDemotion = app(UserBadgeResolverService::class)->resolveForUser($moderator->fresh(['rbacRole']));
    expect($afterDemotion['compact'])->toBe([]);
    expect(UserBadge::query()->where('user_id', $moderator->id)->where('status', UserBadge::STATUS_REVOKED)->exists())->toBeTrue();

    // Grant Official Account to a user then ban them -> auto-revoked and hidden immediately
    $official = User::factory()->create(['account_type' => AccountType::SystemOfficial]);
    app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $official,
        MemberBadgeType::OfficialAccount,
        'Brand partner account',
    );

    app(AdminUserService::class)->applySanction($superAdmin, $official, 'ban', 'Compromised official partner');

    $afterBan = app(UserBadgeResolverService::class)->resolveForUser($official->fresh());
    expect($afterBan['compact'])->toBe([])
        ->and(UserBadge::query()->where('user_id', $official->id)->where('badge_type', MemberBadgeType::OfficialAccount->value)->where('status', UserBadge::STATUS_REVOKED)->exists())->toBeTrue();
});

test('badges never artificially boost post trending score and feed serialization executes zero N+1 queries', function (): void {
    $superAdmin = createSuperAdminUser();
    $authorWithGold = User::factory()->create([
        'role' => UserRole::Admin,
        'rbac_role_id' => Role::query()->where('slug', 'admin')->value('id'),
        'premium_until' => now()->addDays(30),
    ]);
    app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $authorWithGold,
        MemberBadgeType::OfficialStaff,
        'Official staff member',
    );

    $standardAuthor = User::factory()->create(['role' => UserRole::User]);

    $postAttributes = [
        'title' => 'Identical comparison post',
        'content' => 'Comparing trending score fairness across badge tiers without any bias.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'lifecycle_status' => PostLifecycleStatus::Published,
        'published_at' => now()->subHours(2),
        'human_views_count' => 100,
        'reactions_count' => 10,
        'comments_count' => 3,
        'bookmarks_count' => 2,
        'shares_count' => 1,
    ];

    $goldPost = new Post($postAttributes);
    $goldPost->user_id = $authorWithGold->id;
    $goldPost->save();

    $standardPost = new Post($postAttributes);
    $standardPost->user_id = $standardAuthor->id;
    $standardPost->save();

    $ranking = app(TrendingRankingService::class);
    $goldScore = $ranking->recalculatePost($goldPost->fresh());
    $standardScore = $ranking->recalculatePost($standardPost->fresh());

    expect($goldScore)->toBe($standardScore);

    // Create 8 more posts from distinct authors to verify zero N+1 queries during feed serialization
    for ($i = 0; $i < 8; $i++) {
        $u = User::factory()->create();
        $extraPost = new Post([
            ...$postAttributes,
            'title' => "Feed item {$i}",
        ]);
        $extraPost->user_id = $u->id;
        $extraPost->save();
    }

    Cache::flush();
    app(UserBadgeResolverService::class)->flushRequestMemo();

    // Warm schema check once before measuring per-request user_badges query count
    $feedService = app(FeedService::class);
    $feedService->publicFeed(null, 'latest', null, 1);
    Cache::flush();
    app(UserBadgeResolverService::class)->flushRequestMemo();

    DB::enableQueryLog();
    $feed = $feedService->publicFeed(null, 'latest', null, 20);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($feed['data'])->toHaveCount(10);

    // Ensure user_badges was queried once in batch across all 10 distinct authors (never N+1!)
    $userBadgeDataQueries = array_filter(
        $queries,
        static fn (array $q): bool => str_contains((string) ($q['query'] ?? ''), 'from "user_badges"')
            || str_contains((string) ($q['query'] ?? ''), 'from `user_badges`'),
    );
    expect(count($userBadgeDataQueries))->toBe(1);
});

test('non-super-admin growth manager cannot escalate privileges or revoke authority badges via growth endpoint', function (): void {
    $superAdmin = createSuperAdminUser();
    $contentRole = Role::query()->where('slug', 'content_manager')->firstOrFail();
    $growthManager = User::factory()->create([
        'role' => UserRole::Moderator,
        'rbac_role_id' => $contentRole->id,
        'two_factor_secret' => encrypt('TESTSECRET123456'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $target = User::factory()->create();

    $officialBadge = app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $target,
        MemberBadgeType::OfficialAccount,
        'Official SysNoxis account',
    );

    // Give content manager temporary early_member grant permission check via super_admin route test -> still blocked from granting/revoking official badges
    $this->actingAs($growthManager)
        ->post('/admin/growth/badges/grant', [
            'username' => $target->username,
            'badge_type' => MemberBadgeType::OfficialAccount->value,
        ])
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->post("/admin/growth/badges/{$officialBadge->id}/revoke", [
            'reason' => 'Bypass attempt via growth controller',
        ])
        ->assertForbidden();

    expect($officialBadge->fresh()->status)->toBe(UserBadge::STATUS_ACTIVE);
});

test('internal non-public staff visibility toggle hides public badge without revoking audit record', function (): void {
    $superAdmin = createSuperAdminUser();
    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $staffUser = User::factory()->create([
        'role' => UserRole::Admin,
        'rbac_role_id' => $adminRole->id,
    ]);

    $badge = app(AdminBadgeManagementService::class)->grantBadge(
        $superAdmin,
        $staffUser,
        MemberBadgeType::OfficialStaff,
        'Public official staff trial',
        isPublic: true,
    );

    expect(array_column(app(UserBadgeResolverService::class)->resolveForUser($staffUser)['compact'], 'type'))
        ->toBe(['official_staff']);

    $this->actingAs($superAdmin)
        ->post("/admin/badges/{$badge->id}/visibility", [
            'is_public' => false,
        ])
        ->assertRedirect();

    $hidden = app(UserBadgeResolverService::class)->resolveForUser($staffUser->fresh(['rbacRole']));
    expect($hidden['compact'])->toBe([])
        ->and($badge->fresh()->is_public)->toBeFalse()
        ->and($badge->fresh()->status)->toBe(UserBadge::STATUS_ACTIVE);
});
