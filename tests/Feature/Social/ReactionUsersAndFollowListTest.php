<?php

use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Enums\ReactionType;
use App\Enums\UserStatus;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function createPublicApprovedPostForDirectory(User $author): Post
{
    $post = new Post([
        'category' => PostCategory::Technology->value,
        'title' => 'Social Directory Test Post',
        'content' => 'Testing reaction user directory and profile follow modals.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $post->user_id = $author->id;
    $post->moderation_status = ModerationStatus::Approved;
    $post->save();

    return $post;
}

test('reaction users endpoint returns accurate counts by type, filters by reaction type, marks self and follow status, excludes banned/blocked/unreacted users, and supports pagination and search', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create(['name' => 'Current Viewer', 'username' => 'current_viewer']);
    $alice = User::factory()->create(['name' => 'Alice Nguyen', 'username' => 'alice_ng']);
    $bob = User::factory()->create(['name' => 'Bob Tran', 'username' => 'bob_tr']);
    $charlie = User::factory()->create(['name' => 'Charlie Pham', 'username' => 'charlie_ph']);
    $bannedUser = User::factory()->create(['status' => UserStatus::Banned]);
    $blockedUser = User::factory()->create(['name' => 'Blocked Member']);

    (new Profile)->forceFill(['user_id' => $alice->id, 'headline' => 'Product Designer', 'is_verified_badge' => true])->save();
    Follow::query()->create(['follower_id' => $viewer->id, 'following_id' => $alice->id]);
    UserBlock::query()->create(['blocker_id' => $viewer->id, 'blocked_id' => $blockedUser->id]);

    $post = createPublicApprovedPostForDirectory($author);

    // Seed reactions across all 3 SyncViet reaction types + banned + blocked
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $viewer->id, 'type' => ReactionType::Like]);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $alice->id, 'type' => ReactionType::Love]);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $bob->id, 'type' => ReactionType::Insightful]);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $charlie->id, 'type' => ReactionType::Like]);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $bannedUser->id, 'type' => ReactionType::Like]);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $blockedUser->id, 'type' => ReactionType::Love]);

    // 1. All reactions: excludes banned & blocked -> total 4 (2 like, 1 love, 1 insightful)
    $response = $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', $post))
        ->assertOk()
        ->assertJson([
            'total_count' => 4,
            'counts_by_type' => [
                'like' => 2,
                'love' => 1,
                'insightful' => 1,
            ],
            'active_type' => null,
        ]);

    $items = collect($response->json('items'));
    expect($items->pluck('id')->all())->not->toContain($bannedUser->id)
        ->and($items->pluck('id')->all())->not->toContain($blockedUser->id);

    // Self row has is_self = true & can_follow = false
    $selfRow = $items->firstWhere('id', $viewer->id);
    expect($selfRow['is_self'])->toBeTrue()
        ->and($selfRow['can_follow'])->toBeFalse()
        ->and($selfRow['reaction_type'])->toBe('like');

    // Alice row shows followed state, verified badge & headline
    $aliceRow = $items->firstWhere('id', $alice->id);
    expect($aliceRow['is_following'])->toBeTrue()
        ->and($aliceRow['is_verified'])->toBeTrue()
        ->and($aliceRow['headline'])->toBe('Product Designer')
        ->and($aliceRow['reaction_type'])->toBe('love');

    // Bob row shows unfollowed state
    $bobRow = $items->firstWhere('id', $bob->id);
    expect($bobRow['is_following'])->toBeFalse()
        ->and($bobRow['reaction_type'])->toBe('insightful');

    // 2. Filter by reaction type = love
    $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', ['post' => $post->id, 'type' => 'love']))
        ->assertOk()
        ->assertJsonPath('active_type', 'love')
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.username', 'alice_ng');

    // 3. Search by name / username
    $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', ['post' => $post->id, 'search' => 'bob_tr']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $bob->id);

    // 4. Pagination (per_page = 5 with 7 active reactions -> page 1 has_more = true, page 2 has remaining)
    $extras = User::factory()->count(4)->create();
    foreach ($extras as $extra) {
        PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $extra->id, 'type' => ReactionType::Like]);
    }

    $page1 = $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', ['post' => $post->id, 'per_page' => 5, 'page' => 1]))
        ->assertOk()
        ->assertJsonCount(5, 'items')
        ->assertJsonPath('pagination.has_more', true)
        ->assertJsonPath('pagination.next_page', 2);

    $page2 = $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', ['post' => $post->id, 'per_page' => 5, 'page' => 2]))
        ->assertOk()
        ->assertJsonCount(3, 'items')
        ->assertJsonPath('pagination.has_more', false)
        ->assertJsonPath('pagination.next_page', null);

    expect(collect($page1->json('items'))->pluck('id')->intersect(collect($page2->json('items'))->pluck('id'))->count())
        ->toBe(0);

    // 5. Removing reaction immediately removes user from reaction list
    PostReaction::query()->where('post_id', $post->id)->where('user_id', $bob->id)->delete();
    $this->actingAs($viewer)
        ->getJson(route('posts.reactions.index', ['post' => $post->id, 'type' => 'insightful']))
        ->assertOk()
        ->assertJsonCount(0, 'items')
        ->assertJsonPath('counts_by_type.insightful', 0);
});

test('reaction users endpoint enforces post visibility policy and returns clean empty state when post has no reactions', function (): void {
    $author = User::factory()->create();
    $other = User::factory()->create();

    $publicPost = createPublicApprovedPostForDirectory($author);
    $this->getJson(route('posts.reactions.index', $publicPost))
        ->assertOk()
        ->assertJson([
            'total_count' => 0,
            'items' => [],
            'pagination' => ['has_more' => false],
        ]);

    $privatePost = new Post([
        'category' => PostCategory::Technology->value,
        'title' => 'Private Draft',
        'content' => 'Private content.',
        'visibility' => PostVisibility::Private->value,
        'published_at' => now(),
    ]);
    $privatePost->user_id = $author->id;
    $privatePost->moderation_status = ModerationStatus::Approved;
    $privatePost->save();

    // Non-owner cannot inspect private post reactions
    $this->actingAs($other)
        ->getJson(route('posts.reactions.index', $privatePost))
        ->assertForbidden();

    // Owner can inspect own private post reactions
    $this->actingAs($author)
        ->getJson(route('posts.reactions.index', $privatePost))
        ->assertOk();
});

test('followers and following endpoints return paginated searchable lists, enforce moderation/block privacy, and sync realtime JSON follow toggles', function (): void {
    $profileOwner = User::factory()->create(['name' => 'Owner Dev', 'username' => 'owner_dev']);
    $viewer = User::factory()->create(['name' => 'Viewer Member', 'username' => 'viewer_mem']);
    $follower1 = User::factory()->create(['name' => 'Lan Engineer', 'username' => 'lan_eng']);
    $follower2 = User::factory()->create(['name' => 'Minh Architect', 'username' => 'minh_arch']);
    $following1 = User::factory()->create(['name' => 'Tuan Founder', 'username' => 'tuan_fdr']);
    $bannedFollower = User::factory()->create(['status' => UserStatus::Banned]);

    (new Profile)->forceFill(['user_id' => $profileOwner->id, 'followers_count' => 2, 'following_count' => 1])->save();
    (new Profile)->forceFill(['user_id' => $follower1->id, 'headline' => 'Fullstack Engineer', 'location' => 'Hanoi'])->save();

    // Followers of profileOwner: follower1, follower2, bannedFollower
    Follow::query()->create(['follower_id' => $follower1->id, 'following_id' => $profileOwner->id]);
    Follow::query()->create(['follower_id' => $follower2->id, 'following_id' => $profileOwner->id]);
    Follow::query()->create(['follower_id' => $bannedFollower->id, 'following_id' => $profileOwner->id]);

    // Following of profileOwner: following1
    Follow::query()->create(['follower_id' => $profileOwner->id, 'following_id' => $following1->id]);

    // Viewer already follows follower1
    Follow::query()->create(['follower_id' => $viewer->id, 'following_id' => $follower1->id]);

    // 1. Followers endpoint excludes banned accounts & returns accurate counts + follow states
    $followersRes = $this->actingAs($viewer)
        ->getJson(route('users.followers', $profileOwner->username))
        ->assertOk()
        ->assertJson([
            'tab' => 'followers',
            'restricted' => false,
            'counts' => [
                'followers' => 2,
                'following' => 1,
            ],
        ])
        ->assertJsonCount(2, 'items');

    $followerItems = collect($followersRes->json('items'));
    expect($followerItems->firstWhere('id', $follower1->id)['is_following'])->toBeTrue()
        ->and($followerItems->firstWhere('id', $follower1->id)['headline'])->toBe('Fullstack Engineer')
        ->and($followerItems->firstWhere('id', $follower1->id)['location'])->toBe('Hanoi')
        ->and($followerItems->firstWhere('id', $follower2->id)['is_following'])->toBeFalse();

    // 2. Following endpoint
    $this->actingAs($viewer)
        ->getJson(route('users.following', $profileOwner->username))
        ->assertOk()
        ->assertJsonPath('tab', 'following')
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.username', 'tuan_fdr');

    // 3. Search inside followers by name & headline
    $this->actingAs($viewer)
        ->getJson(route('users.followers', ['user' => $profileOwner->username, 'search' => 'Fullstack']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $follower1->id);

    // 4. Realtime JSON Follow / Unfollow inside modal updates DB + returns synced counts without page reload
    $this->actingAs($viewer)
        ->postJson(route('users.follow', $follower2->username))
        ->assertOk()
        ->assertJson([
            'following' => true,
            'followers_count' => 1,
            'viewer_following_count' => 2,
            'target_user_id' => $follower2->id,
        ]);

    // Subsequent fetch reflects updated is_following = true
    $updatedFollowers = $this->actingAs($viewer)
        ->getJson(route('users.followers', $profileOwner->username))
        ->assertOk();
    expect(collect($updatedFollowers->json('items'))->firstWhere('id', $follower2->id)['is_following'])->toBeTrue();

    // Toggle back to unfollow
    $this->actingAs($viewer)
        ->postJson(route('users.follow', $follower2->username))
        ->assertOk()
        ->assertJson([
            'following' => false,
            'followers_count' => 0,
            'viewer_following_count' => 1,
        ]);

    // 5. Blocked viewer sees clean restricted state instead of leaking follow graph
    UserBlock::query()->create(['blocker_id' => $profileOwner->id, 'blocked_id' => $viewer->id]);
    $this->actingAs($viewer)
        ->getJson(route('users.followers', $profileOwner->username))
        ->assertOk()
        ->assertJson([
            'restricted' => true,
            'items' => [],
        ]);

    // 6. Empty profile returns clean empty lists
    $newUser = User::factory()->create();
    $this->getJson(route('users.followers', $newUser->username))
        ->assertOk()
        ->assertJson(['items' => [], 'counts' => ['followers' => 0, 'following' => 0]]);
    $this->getJson(route('users.following', $newUser->username))
        ->assertOk()
        ->assertJson(['items' => [], 'counts' => ['followers' => 0, 'following' => 0]]);

    // 7. Profile Inertia page includes reaction_summary on posts and follow counts
    $post = createPublicApprovedPostForDirectory($newUser);
    PostReaction::query()->create(['post_id' => $post->id, 'user_id' => $follower1->id, 'type' => ReactionType::Love]);
    $post->forceFill(['reactions_count' => 1])->save();

    $this->get(route('users.show', $newUser->username))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('profile_user.followers_count', 0)
            ->where('profile_user.following_count', 0)
            ->where('posts.0.reaction_summary.love', 1));
});
