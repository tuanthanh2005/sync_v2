<?php

use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Enums\PostVisibility;
use App\Models\Bookmark;
use App\Models\Post;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('profile owner receives accurate post and bookmark counts synchronized after post and bookmark mutations', function (): void {
    $owner = User::factory()->create(['username' => 'profile_owner']);
    $otherAuthor = User::factory()->create(['username' => 'other_author']);

    $publicPost = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    $communityPost = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Community->value,
    ]);
    $privatePost = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Private->value,
    ]);
    $softDeletedPost = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    $softDeletedPost->delete();

    $foreignPublicPost = Post::factory()->for($otherAuthor, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    $deletedBookmarkedPost = Post::factory()->for($otherAuthor, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    $deletedBookmarkedPost->delete();

    // Soft-deleted bookmarked post must not inflate owner bookmarks_count.
    Bookmark::query()->create([
        'user_id' => $owner->id,
        'post_id' => $deletedBookmarkedPost->id,
    ]);

    $this->actingAs($owner)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile_user.is_owner', true)
            ->where('profile_user.posts_count', 3)
            ->where('profile_user.bookmarks_count', 0)
            ->has('posts', 3),
        );

    // Bookmarking a visible post increments owner bookmarks_count cleanly.
    $this->actingAs($owner)
        ->from(route('users.show', $owner->username))
        ->post(route('posts.bookmark', $foreignPublicPost))
        ->assertRedirect(route('users.show', $owner->username));

    $this->actingAs($owner)
        ->get(route('users.show', ['user' => $owner->username, 'tab' => 'bookmarks']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('tab', 'bookmarks')
            ->where('profile_user.posts_count', 3)
            ->where('profile_user.bookmarks_count', 1)
            ->has('bookmarks', 1)
            ->where('bookmarks.0.id', $foreignPublicPost->id),
        );

    // Unbookmarking decrements owner bookmarks_count back to zero.
    $this->actingAs($owner)
        ->from(route('users.show', $owner->username))
        ->post(route('posts.bookmark', $foreignPublicPost))
        ->assertRedirect(route('users.show', $owner->username));

    $this->actingAs($owner)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_user.bookmarks_count', 0),
        );

    // Creating then deleting an own post keeps posts_count accurate.
    $this->actingAs($owner)
        ->from(route('users.show', $owner->username))
        ->post(route('posts.store'), [
            'title' => 'Newly Published Note',
            'content' => 'Testing real-time profile post counter synchronization.',
            'category' => 'technology',
            'visibility' => PostVisibility::Public->value,
        ])
        ->assertRedirect(route('users.show', $owner->username));

    $createdPost = Post::query()->where('title', 'Newly Published Note')->firstOrFail();

    $this->actingAs($owner)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_user.posts_count', 4),
        );

    $this->actingAs($owner)
        ->from(route('users.show', $owner->username))
        ->delete(route('posts.destroy', $createdPost))
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_user.posts_count', 3),
        );

    expect([$publicPost->id, $communityPost->id, $privatePost->id])->toHaveCount(3);
});

test('non-owners and guests receive only public post count and never receive private bookmarks count or bookmarks tab access', function (): void {
    $owner = User::factory()->create(['username' => 'visibility_owner']);
    $viewer = User::factory()->create(['username' => 'curious_viewer']);

    $publicPost = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Private->value,
    ]);
    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Community->value,
    ]);
    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
        'moderation_status' => ModerationStatus::Pending,
    ]);
    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
        'is_spam' => true,
    ]);
    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
        'lifecycle_status' => PostLifecycleStatus::Hidden,
    ]);

    Bookmark::query()->create([
        'user_id' => $owner->id,
        'post_id' => $publicPost->id,
    ]);

    // Authenticated non-owner requesting ?tab=bookmarks
    $this->actingAs($viewer)
        ->get(route('users.show', ['user' => $owner->username, 'tab' => 'bookmarks']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('tab', 'posts')
            ->where('profile_user.is_owner', false)
            ->where('profile_user.posts_count', 1)
            ->missing('profile_user.bookmarks_count')
            ->has('posts', 1)
            ->has('bookmarks', 0),
        );

    // Guest requesting ?tab=bookmarks
    $this->get(route('users.show', ['user' => $owner->username, 'tab' => 'bookmarks']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('tab', 'posts')
            ->where('profile_user.is_owner', false)
            ->where('profile_user.posts_count', 1)
            ->missing('profile_user.bookmarks_count')
            ->has('posts', 1)
            ->has('bookmarks', 0),
        );
});

test('mutually blocked viewer receives zero profile posts count and no bookmarks leak', function (): void {
    $owner = User::factory()->create(['username' => 'blocked_target']);
    $viewer = User::factory()->create(['username' => 'blocking_viewer']);

    Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
    ]);
    UserBlock::query()->create([
        'blocker_id' => $viewer->id,
        'blocked_id' => $owner->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile_user.is_owner', false)
            ->where('profile_user.posts_count', 0)
            ->missing('profile_user.bookmarks_count')
            ->has('posts', 0),
        );
});
