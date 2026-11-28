<?php

use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Models\AdCampaign;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('unified edit endpoint updates text, category, visibility, media order, removals and additions while preserving counters', function (): void {
    Storage::fake('public');

    $owner = User::factory()->create([
        'username' => 'unified_owner',
        'created_at' => now()->subDays(14),
    ]);
    $publishedAt = now()->subHours(2)->startOfSecond();

    $post = Post::factory()->for($owner, 'author')->create([
        'title' => 'Initial Headline',
        'content' => 'Initial body copy before modal edit.',
        'category' => PostCategory::General->value,
        'visibility' => PostVisibility::Public->value,
        'published_at' => $publishedAt,
        'reactions_count' => 19,
        'comments_count' => 7,
        'shares_count' => 4,
        'bookmarks_count' => 3,
        'human_views_count' => 240,
    ]);

    Storage::disk('public')->put('posts/existing-1.jpg', 'img1');
    Storage::disk('public')->put('posts/existing-2.jpg', 'img2');

    $mediaOne = new PostMedia([
        'disk' => 'public',
        'path' => 'posts/existing-1.jpg',
        'mime_type' => 'image/jpeg',
        'width' => 800,
        'height' => 600,
        'size_bytes' => 1024,
        'alt_text' => 'First photo',
        'sort_order' => 0,
    ]);
    $mediaOne->post_id = $post->id;
    $mediaOne->user_id = $owner->id;
    $mediaOne->save();

    $mediaTwo = new PostMedia([
        'disk' => 'public',
        'path' => 'posts/existing-2.jpg',
        'mime_type' => 'image/jpeg',
        'width' => 800,
        'height' => 600,
        'size_bytes' => 1024,
        'alt_text' => 'Second photo',
        'sort_order' => 1,
    ]);
    $mediaTwo->post_id = $post->id;
    $mediaTwo->user_id = $owner->id;
    $mediaTwo->save();

    $response = $this->actingAs($owner)
        ->from(route('posts.show', $post))
        ->post(route('posts.update', $post), [
            '_method' => 'patch',
            'title' => 'Updated Modal Headline',
            'content' => 'Updated full post content edited from unified modal.',
            'category' => PostCategory::Technology->value,
            'visibility' => PostVisibility::Private->value,
            'link_synced' => '1',
            'link_url' => 'https://sysnoxis.com/docs',
            'media_synced' => '1',
            'existing_media' => [
                ['id' => $mediaTwo->id, 'alt_text' => 'Updated second photo'],
            ],
            'images' => [
                UploadedFile::fake()->image('replacement.jpg', 900, 700),
            ],
            'image_alts' => ['Fresh uploaded photo'],
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ]);

    $response->assertRedirect(route('posts.show', $post));

    $post->refresh();
    $freshMedia = $post->media()->orderBy('sort_order')->get();

    expect($post->title)->toBe('Updated Modal Headline')
        ->and($post->content)->toBe('Updated full post content edited from unified modal.')
        ->and($post->category)->toBe(PostCategory::Technology)
        ->and($post->visibility)->toBe(PostVisibility::Private)
        ->and($post->link_url)->toBe('https://sysnoxis.com/docs')
        ->and($post->published_at?->toIso8601String())->toBe($publishedAt->toIso8601String())
        ->and($post->edit_count)->toBe(1)
        ->and($post->edited_at)->not->toBeNull()
        ->and($post->reactions_count)->toBe(19)
        ->and($post->comments_count)->toBe(7)
        ->and($post->shares_count)->toBe(4)
        ->and($post->bookmarks_count)->toBe(3)
        ->and($post->human_views_count)->toBe(240)
        ->and($freshMedia)->toHaveCount(2)
        ->and($freshMedia[0]->id)->toBe($mediaTwo->id)
        ->and($freshMedia[0]->alt_text)->toBe('Updated second photo')
        ->and($freshMedia[0]->sort_order)->toBe(0)
        ->and($freshMedia[1]->alt_text)->toBe('Fresh uploaded photo')
        ->and($freshMedia[1]->sort_order)->toBe(1);

    Storage::disk('public')->assertMissing('posts/existing-1.jpg');
    Storage::disk('public')->assertExists('posts/existing-2.jpg');
    Storage::disk('public')->assertExists($freshMedia[1]->path);
});

test('edit conflict detection blocks stale writes and prevents cross-post media idor', function (): void {
    Storage::fake('public');

    $owner = User::factory()->create(['username' => 'conflict_owner']);
    $otherUser = User::factory()->create(['username' => 'other_member']);

    $post = Post::factory()->for($owner, 'author')->create([
        'title' => 'Original Title',
        'content' => 'Latest saved body copy.',
        'updated_at' => now(),
    ]);

    $foreignPost = Post::factory()->for($otherUser, 'author')->create();
    $foreignMedia = new PostMedia([
        'disk' => 'public',
        'path' => 'posts/foreign.jpg',
        'mime_type' => 'image/jpeg',
        'width' => 400,
        'height' => 300,
        'size_bytes' => 512,
        'sort_order' => 0,
    ]);
    $foreignMedia->post_id = $foreignPost->id;
    $foreignMedia->user_id = $otherUser->id;
    $foreignMedia->save();

    // 1. Stale expected_updated_at fails safely without overwriting or keeping uploaded orphan.
    $this->actingAs($owner)
        ->post(route('posts.update', $post), [
            '_method' => 'patch',
            'content' => 'Stale tab overwrite attempt.',
            'expected_updated_at' => now()->subMinutes(10)->toIso8601String(),
        ])
        ->assertSessionHasErrors('content');

    expect($post->fresh()->content)->toBe('Latest saved body copy.');

    // 2. Foreign media ID cannot be attached into owner's post.
    $this->actingAs($owner)
        ->post(route('posts.update', $post), [
            '_method' => 'patch',
            'content' => 'Attempt media IDOR.',
            'media_synced' => '1',
            'existing_media' => [
                ['id' => $foreignMedia->id],
            ],
        ])
        ->assertSessionHasErrors('images');

    // 3. Another user cannot update someone else's post.
    $this->actingAs($otherUser)
        ->patch(route('posts.update', $post), [
            'content' => 'Unauthorized edit.',
        ])
        ->assertForbidden();
});

test('promoted posts cannot switch visibility to private during active campaign and shared postCategories are present on detail and profile views', function (): void {
    $owner = User::factory()->create(['username' => 'promoted_owner']);
    $post = Post::factory()->for($owner, 'author')->create([
        'visibility' => PostVisibility::Public->value,
        'content' => 'Promoted announcement post.',
    ]);

    AdCampaign::query()->forceCreate([
        'user_id' => $owner->id,
        'post_id' => $post->id,
        'name' => 'Test Boost',
        'objective' => AdObjective::Reach,
        'status' => AdCampaignStatus::Active,
        'billing_mode' => 'fixed_package',
        'budget_type' => 'total',
        'daily_budget' => 100_000,
        'total_budget' => 500_000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->patch(route('posts.update', $post), [
            'content' => 'Trying to privatize boosted post.',
            'visibility' => PostVisibility::Private->value,
        ])
        ->assertSessionHasErrors('visibility');

    expect($post->fresh()->visibility)->toBe(PostVisibility::Public);

    $this->actingAs($owner)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Show')
            ->has('postCategories', count(PostCategory::cases()))
            ->where('post.updated_at', $post->updated_at?->toIso8601String()),
        );

    $this->actingAs($owner)
        ->get(route('users.show', $owner->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->has('postCategories', count(PostCategory::cases())),
        );
});
