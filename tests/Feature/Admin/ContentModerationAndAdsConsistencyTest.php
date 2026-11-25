<?php

use App\Enums\AdCampaignStatus;
use App\Enums\ModerationStatus;
use App\Enums\UserStatus;
use App\Models\AdCreditAccount;
use App\Models\Appeal;
use App\Models\BlockedKeyword;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSanction;
use App\Services\Ads\AdCampaignService;
use App\Services\Billing\WalletService;
use App\Services\Community\CommunityService;
use App\Services\Security\RbacService;
use App\Services\Social\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
});

test('hiding a promoted post stops its active ad campaign and refunds remaining held budget', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $advertiser = User::factory()->create();

    $post = Post::factory()->for($advertiser, 'author')->create(['visibility' => 'public']);

    // Fund wallet via ledger adjustment and launch an approved ad campaign holding 200,000 VND
    $walletService = app(WalletService::class);
    $walletService->adminAdjustLedger($admin, $advertiser, 500_000, 'paid', 'Initial test credit', 'test-seed-500k');

    $adService = app(AdCampaignService::class);
    $campaign = $adService->createCampaign($advertiser, [
        'post_id' => $post->id,
        'name' => 'Launch Promo',
        'objective' => 'reach',
        'schedule_mode' => 'immediate',
        'budget_type' => 'total',
        'total_budget' => 200_000,
        'duration_days' => 5,
    ]);
    $adService->reviewCampaign($admin, $campaign, 'approve');

    $account = AdCreditAccount::query()->where('user_id', $advertiser->id)->firstOrFail();
    expect($account->balance)->toBe(300_000)
        ->and($account->held_balance)->toBe(200_000);

    // Admin hides the promoted post -> campaign must cancel and refund 200,000 VND automatically
    $this->actingAs($admin)
        ->post(route('admin.content.posts.moderate', $post->id), [
            'action' => 'hide',
            'reason' => 'Violates advertising transparency policy',
        ])
        ->assertSessionHasNoErrors();

    $campaign->refresh();
    $account->refresh();
    $post->refresh();

    expect($post->moderation_status)->toBe(ModerationStatus::Hidden)
        ->and($campaign->status)->toBe(AdCampaignStatus::Cancelled)
        ->and($campaign->held_amount)->toBe(0)
        ->and($account->balance)->toBe(500_000)
        ->and($account->held_balance)->toBe(0);
});

test('comment moderation keeps post comment counters accurate and respects locked comments', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $member = User::factory()->create();
    $post = Post::factory()->for($member, 'author')->create(['visibility' => 'public']);

    $commentService = app(CommentService::class);
    $c1 = $commentService->create($member, $post, ['content' => 'First discussion comment']);
    $c2 = $commentService->create($member, $post, ['content' => 'Second spam comment']);

    expect($post->fresh()->comments_count)->toBe(2);

    // Hide second comment via admin moderation
    $this->actingAs($admin)
        ->post(route('admin.content.comments.moderate', $c2->id), [
            'action' => 'hide',
            'reason' => 'Spam comment',
        ])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->comments_count)->toBe(1)
        ->and($c2->fresh()->moderation_status)->toBe(ModerationStatus::Hidden);

    // Lock comments on the post and ensure new comments are blocked at backend
    $this->actingAs($admin)
        ->post(route('admin.content.posts.moderate', $post->id), [
            'action' => 'lock_comments',
            'reason' => 'Off-topic flamewar',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->post(route('comments.store', $post), ['content' => 'Trying to comment while locked'])
        ->assertSessionHasErrors('content');
});

test('blocked keywords filter blocks restricted content and community moderation controls joining', function (): void {
    $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create(['rbac_role_id' => $superAdminRole->id]);
    $owner = User::factory()->create();
    $joiner = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.content.keywords.store'), [
            'keyword' => 'forbidden-scam-site.xyz',
            'match_type' => 'domain',
            'severity' => 'block',
        ])
        ->assertSessionHasNoErrors();

    expect(BlockedKeyword::query()->where('keyword', 'forbidden-scam-site.xyz')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->post(route('posts.store'), [
            'content' => 'Visit forbidden-scam-site.xyz right now!',
            'category' => 'general',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('content');

    // Create community then restrict new members
    $community = app(CommunityService::class)->create($owner, [
        'name' => 'Security Engineers',
        'category' => 'technology',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.content.communities.moderate', $community->id), [
            'action' => 'toggle_new_members',
            'reason' => 'Temporarily pausing joins',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($joiner)
        ->post(route('communities.membership', $community->slug))
        ->assertSessionHasErrors('community');

    // Appeal approval reverses linked sanction and restores account
    $joiner->forceFill(['status' => UserStatus::Suspended])->save();
    $sanction = new UserSanction([
        'type' => 'suspend',
        'reason' => 'Mistaken suspension',
        'starts_at' => now(),
        'is_active' => true,
    ]);
    $sanction->user_id = $joiner->id;
    $sanction->actor_id = $admin->id;
    $sanction->save();

    $appeal = new Appeal([
        'sanction_id' => $sanction->id,
        'reason' => 'Please review my account activity.',
    ]);
    $appeal->user_id = $joiner->id;
    $appeal->status = 'pending';
    $appeal->save();

    $this->actingAs($admin)
        ->post(route('admin.appeals.resolve', $appeal->id), [
            'decision' => 'approved',
            'resolution_note' => 'Verified false positive, lifting sanction.',
        ])
        ->assertSessionHasNoErrors();

    expect($joiner->fresh()->status)->toBe(UserStatus::Active)
        ->and($sanction->fresh()->is_active)->toBeFalse();
});
