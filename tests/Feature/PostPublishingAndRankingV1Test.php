<?php

use App\Enums\AccountType;
use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Enums\ReactionType;
use App\Enums\TrustTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\Social\RecalculateTrendingScoresJob;
use App\Models\AuditLog;
use App\Models\BlockedKeyword;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostIdempotency;
use App\Models\PostMedia;
use App\Models\PostShare;
use App\Models\PublishingQuotaEvent;
use App\Models\SpamEvent;
use App\Models\User;
use App\Services\Media\OrphanMediaCleanupService;
use App\Services\Security\AuditLogger;
use App\Services\Social\FeedService;
use App\Services\Social\PostingPolicyConfigService;
use App\Services\Social\PostService;
use App\Services\Social\TrendingRankingService;
use App\Services\Social\TrustLevelResolver;
use App\Services\System\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function setSystemSettings(array $pairs, ?User $actor = null): void
{
    $admin = $actor ?? User::factory()->create([
        'role' => UserRole::Admin,
        'account_type' => AccountType::Human,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'two_factor_secret' => 'test-secret',
        'two_factor_confirmed_at' => now(),
    ]);

    app(SystemSettingService::class)->updateMany($pairs, $admin, app(AuditLogger::class));
}

function makeHumanMember(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::User,
        'account_type' => AccountType::Human,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'created_at' => now()->subDays(30),
        'can_post' => true,
        'can_comment' => true,
        'is_test_account' => false,
    ], $overrides));
}

test('trust tiers enforce new member safety guard even with premium and allow higher limits for established premium', function () {
    $resolver = app(TrustLevelResolver::class);

    $brandNewPremium = makeHumanMember([
        'created_at' => now()->subDays(2),
        'premium_until' => now()->addMonth(),
    ]);
    $establishedStandard = makeHumanMember([
        'created_at' => now()->subDays(30),
    ]);
    $establishedPremium = makeHumanMember([
        'created_at' => now()->subDays(30),
        'premium_until' => now()->addMonth(),
    ]);
    $restricted = makeHumanMember([
        'can_post' => false,
    ]);

    expect($resolver->resolve($brandNewPremium))->toBe(TrustTier::NewMember)
        ->and($resolver->limitsFor($brandNewPremium)['max_images_per_post'])->toBe(4)
        ->and($resolver->resolve($establishedStandard))->toBe(TrustTier::StandardMember)
        ->and($resolver->resolve($establishedPremium))->toBe(TrustTier::PremiumMember)
        ->and($resolver->limitsFor($establishedPremium)['max_images_per_post'])->toBe(8)
        ->and($resolver->resolve($restricted))->toBe(TrustTier::RestrictedMember);

    $fiveImages = [
        UploadedFile::fake()->image('a.jpg', 400, 400),
        UploadedFile::fake()->image('b.jpg', 400, 400),
        UploadedFile::fake()->image('c.jpg', 400, 400),
        UploadedFile::fake()->image('d.jpg', 400, 400),
        UploadedFile::fake()->image('e.jpg', 400, 400),
    ];

    $this->actingAs($brandNewPremium)
        ->post(route('posts.store'), [
            'content' => 'Trying to upload too many images on a new account.',
            'images' => $fiveImages,
        ])
        ->assertSessionHasErrors('images');
});

test('posting rejects invisible-only whitespace without consuming quota', function () {
    $user = makeHumanMember();

    $this->actingAs($user)
        ->post(route('posts.store'), [
            'content' => "\u{200B}  \u{200C}\u{FEFF} ",
        ])
        ->assertSessionHasErrors('content');

    expect(Post::query()->count())->toBe(0)
        ->and(PublishingQuotaEvent::query()->count())->toBe(0);
});

test('rolling window rate limit returns http 429 with retry-after and deleting post never resets quota', function () {
    setSystemSettings([
        'posting_min_interval_standard' => 60,
        'posting_limit_60m_standard' => 2,
    ]);

    $user = makeHumanMember(['created_at' => now()->subDays(30)]);

    $this->actingAs($user)
        ->post(route('posts.store'), [
            'content' => 'First legitimate engineering post on SysNoxis.',
        ])
        ->assertRedirect();

    $post = Post::query()->firstOrFail();

    // Soft-delete post: rolling quota event MUST remain intact.
    $this->actingAs($user)
        ->delete(route('posts.destroy', $post))
        ->assertRedirect();

    expect(PublishingQuotaEvent::query()->where('user_id', $user->id)->where('action_type', 'post')->count())->toBe(1);

    // Rapid second attempt inside min interval returns HTTP 429 + Retry-After header.
    $response = $this->actingAs($user)
        ->postJson(route('posts.store'), [
            'content' => 'Second rapid post immediately after deleting first post.',
        ]);

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message', 'retry_after', 'limit_type']);
});

test('idempotency key prevents duplicate posts and does not charge double quota on retry', function () {
    $user = makeHumanMember(['created_at' => now()->subDays(30)]);
    $key = 'idem-double-click-check-001';

    $payload = [
        'title' => 'Global Launch Notes',
        'content' => 'Idempotent post submission payload verified cleanly across retries.',
        'category' => 'technology',
        'visibility' => 'public',
        'idempotency_key' => $key,
    ];

    $this->actingAs($user)->post(route('posts.store'), $payload)->assertRedirect();
    $this->actingAs($user)->post(route('posts.store'), $payload)->assertRedirect();

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PublishingQuotaEvent::query()->where('user_id', $user->id)->where('action_type', 'post')->count())->toBe(1)
        ->and(PostIdempotency::query()->where('idempotency_key', $key)->value('status'))->toBe('completed');
});

test('anti-spam engine blocks exact duplicates and routes high-risk posts to private pending review', function () {
    $author = makeHumanMember(['created_at' => now()->subDays(30)]);
    $otherUser = makeHumanMember(['created_at' => now()->subDays(30)]);

    BlockedKeyword::query()->create([
        'keyword' => 'scam-drop-domain.example',
        'match_type' => 'contains',
        'severity' => 'block',
    ]);

    $this->actingAs($author)
        ->post(route('posts.store'), [
            'content' => 'Visit scam-drop-domain.example right now!',
        ])
        ->assertSessionHasErrors('content');

    expect(SpamEvent::query()->where('event_type', 'keyword_blocked')->count())->toBe(1);

    // Identical duplicate within window blocked cleanly.
    $this->actingAs($author)
        ->post(route('posts.store'), [
            'content' => 'Unique system design update about edge caching and MySQL indexing.',
        ])
        ->assertRedirect();

    setSystemSettings(['posting_min_interval_standard' => 0]);

    $this->actingAs($author)
        ->post(route('posts.store'), [
            'content' => 'Unique system design update about edge caching and MySQL indexing.',
        ])
        ->assertSessionHasErrors('content');

    expect(SpamEvent::query()->where('event_type', 'duplicate_blocked')->count())->toBe(1);

    // Single shortened link on new account yields high-risk score (60..84) -> private Pending Review.
    $newAuthor = makeHumanMember(['created_at' => now()->subDays(1)]);
    $this->actingAs($newAuthor)
        ->post(route('posts.store'), [
            'content' => 'Sharing an external resource link for review.',
            'link_url' => 'https://bit.ly/review-link',
        ])
        ->assertRedirect();

    $pendingPost = Post::query()->where('user_id', $newAuthor->id)->firstOrFail();
    expect($pendingPost->moderation_status)->toBe(ModerationStatus::Pending)
        ->and($pendingPost->lifecycle_status)->toBe(PostLifecycleStatus::PendingReview)
        ->and($pendingPost->moderation_reason)->not->toBeNull();

    // Public feed & direct detail route hide Pending Review post from other users.
    expect(Post::query()->publicVisible()->whereKey($pendingPost->id)->exists())->toBeFalse();
    $this->actingAs($otherUser)->get(route('posts.show', $pendingPost))->assertForbidden();

    // Author can view own Pending Review post with private moderation banner/reason.
    $this->actingAs($newAuthor)
        ->get(route('posts.show', $pendingPost))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('post.moderation_status', 'pending')
            ->where('post.moderation_reason', $pendingPost->moderation_reason));
});

test('editing post preserves published_at increments edit_count and enforces daily edit limit', function () {
    $author = makeHumanMember();
    $publishedAt = now()->subHours(6)->startOfSecond();

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'published_at' => $publishedAt,
        'human_views_count' => 150,
    ]);

    $this->actingAs($author)
        ->patch(route('posts.update', $post), [
            'title' => 'Updated Architecture Title',
            'content' => 'Updated architectural write-up with clearer explanation.',
            'category' => 'technology',
        ])
        ->assertRedirect();

    $post->refresh();
    expect($post->published_at?->toIso8601String())->toBe($publishedAt->toIso8601String())
        ->and($post->edited_at)->not->toBeNull()
        ->and($post->edit_count)->toBe(1)
        ->and($post->human_views_count)->toBe(150);

    setSystemSettings(['post_edits_per_day_limit' => 1]);

    $this->actingAs($author)
        ->patchJson(route('posts.update', $post), [
            'content' => 'Second edit exceeding daily edit cap.',
        ])
        ->assertStatus(429);
});

test('trending formula applies time decay excludes author and ai engagement separates boosted views and deduplicates shares', function () {
    $ranking = app(TrendingRankingService::class);
    $postService = app(PostService::class);

    // Newer post outscores equally engaged older post.
    $freshSim = $ranking->simulate([
        'human_views' => 200,
        'reactions' => 20,
        'comments' => 10,
        'bookmarks' => 5,
        'shares' => 3,
        'age_hours' => 2,
    ]);
    $olderSim = $ranking->simulate([
        'human_views' => 200,
        'reactions' => 20,
        'comments' => 10,
        'bookmarks' => 5,
        'shares' => 3,
        'age_hours' => 72,
    ]);

    expect($freshSim['trending_score'])->toBeGreaterThan($olderSim['trending_score']);

    // Abnormal view stuffing with zero engagements triggers dampening.
    $stuffed = $ranking->simulate([
        'human_views' => 50000,
        'reactions' => 0,
        'comments' => 0,
        'bookmarks' => 0,
        'shares' => 0,
        'age_hours' => 1,
    ]);
    expect($stuffed['dampening_applied'])->toBeTrue()
        ->and($stuffed['effective_human_views'])->toBeLessThan(1000.0);

    // Self-engagement, AI agent engagement, and promoted views do not inflate valid ranking signals.
    $author = makeHumanMember();
    $aiUser = makeHumanMember(['account_type' => AccountType::AiAgent]);
    $realPeer = makeHumanMember();

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'human_views_count' => 100,
        'promoted_views_count' => 80, // only 20 organic views count!
        'published_at' => now()->subHour(),
    ]);

    expect($ranking->resolveOrganicHumanViews($post))->toBe(20);

    $postService->toggleReaction($author, $post, ReactionType::Like);
    $postService->toggleReaction($aiUser, $post, ReactionType::Love);
    $postService->toggleBookmark($author, $post);
    $postService->toggleBookmark($aiUser, $post);

    $post->refresh();
    expect($post->reactions_count)->toBe(2)
        ->and($post->valid_reactions_count)->toBe(0)
        ->and($post->valid_bookmarks_count)->toBe(0);

    // Genuine peer contribution counts and share deduplicates per actor.
    $postService->toggleReaction($realPeer, $post, ReactionType::Insightful);
    $postService->toggleBookmark($realPeer, $post);

    $this->actingAs($realPeer)->post(route('posts.share', $post), ['channel' => 'copy_link'])->assertRedirect();
    $this->actingAs($realPeer)->post(route('posts.share', $post), ['channel' => 'copy_link'])->assertRedirect();

    $post->refresh();
    expect($post->valid_reactions_count)->toBe(1)
        ->and($post->valid_bookmarks_count)->toBe(1)
        ->and(PostShare::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and($post->shares_count)->toBe(1)
        ->and($post->valid_shares_count)->toBe(1);

    (new RecalculateTrendingScoresJob(100))->handle($ranking);
    $post->refresh();
    expect((float) $post->trending_score)->toBeGreaterThan(0.0);

    // Moderation reject/spam zeroes trending score cleanly on batch run.
    $post->forceFill(['is_spam' => true])->save();
    $ranking->recalculateCandidateWindow(100);
    $post->refresh();
    expect((float) $post->trending_score)->toBe(0.0);
});

test('following and recommended feeds handle diversity cold start and per-user hide-from-feed', function () {
    setSystemSettings(['feed_author_diversity_max' => 2]);

    $viewer = makeHumanMember();
    $followedAuthor = makeHumanMember();
    $spammyHighVolumeAuthor = makeHumanMember();
    $freshCreator = makeHumanMember();

    Follow::query()->create([
        'follower_id' => $viewer->id,
        'following_id' => $followedAuthor->id,
        'created_at' => now(),
    ]);

    $followedPost = Post::factory()->create([
        'user_id' => $followedAuthor->id,
        'content' => 'Post from followed creator.',
        'published_at' => now()->subMinutes(10),
    ]);

    foreach (range(1, 4) as $i) {
        Post::factory()->create([
            'user_id' => $spammyHighVolumeAuthor->id,
            'content' => "Popular post {$i} by prolific author",
            'trending_score' => 100 - $i,
            'published_at' => now()->subHours(2),
        ]);
    }

    foreach (range(1, 3) as $j) {
        $otherCreator = makeHumanMember();
        Post::factory()->create([
            'user_id' => $otherCreator->id,
            'content' => "Diverse creator insight {$j}",
            'trending_score' => 50 - $j,
            'published_at' => now()->subHours(3),
        ]);
    }

    $freshPost = Post::factory()->create([
        'user_id' => $freshCreator->id,
        'content' => 'Brand new creator post with zero initial views.',
        'trending_score' => 0,
        'published_at' => now()->subMinutes(5),
    ]);

    $feedService = app(FeedService::class);

    // Following feed strictly contains only followed authors.
    $followingFeed = $feedService->publicFeed($viewer, 'following', null, 10);
    $followingIds = collect($followingFeed['data'])->pluck('id')->all();
    expect($followingIds)->toBe([$followedPost->id]);

    // Recommended feed gives fresh zero-view post fair visibility while respecting per-page author cap <= 2.
    $recommendedFeed = $feedService->publicFeed($viewer, 'recommended', null, 5);
    $firstFiveAuthors = collect($recommendedFeed['data'])->pluck('author.id');
    expect($firstFiveAuthors->filter(fn ($id) => $id === $spammyHighVolumeAuthor->id)->count())->toBeLessThanOrEqual(2);
    expect(collect($recommendedFeed['data'])->pluck('id')->all())->toContain($freshPost->id);

    // Hide from my feed removes post for viewer only, leaving global visibility intact.
    $this->actingAs($viewer)->post(route('posts.hide-from-feed', $followedPost))->assertRedirect();

    $afterHideFollowing = $feedService->publicFeed($viewer, 'following', null, 10);
    expect(collect($afterHideFollowing['data'])->pluck('id')->all())->not->toContain($followedPost->id);
    expect($feedService->publicFeed($freshCreator, 'latest', null, 20)['data'])->not->toBeEmpty();
});

test('admin controls settings reset audit log rbac rejection and pure simulator isolation', function () {
    $member = makeHumanMember();
    $admin = makeHumanMember([
        'role' => UserRole::Admin,
        'two_factor_secret' => 'test-secret',
        'two_factor_confirmed_at' => now(),
    ]);
    $targetPost = Post::factory()->create([
        'user_id' => $member->id,
        'moderation_status' => ModerationStatus::Pending,
        'lifecycle_status' => PostLifecycleStatus::PendingReview,
        'trending_score' => 42.5,
    ]);

    $this->actingAs($member)->get(route('admin.content.settings'))->assertForbidden();
    $this->actingAs($member)->postJson(route('admin.content.settings.simulate'), [
        'human_views' => 100,
        'reactions' => 10,
        'comments' => 5,
        'bookmarks' => 2,
        'shares' => 1,
        'age_hours' => 3,
    ])->assertForbidden();

    // Admin views settings dashboard & runs pure simulation without touching any Post row.
    $this->actingAs($admin)->get(route('admin.content.settings'))->assertOk();
    $simResponse = $this->actingAs($admin)->postJson(route('admin.content.settings.simulate'), [
        'human_views' => 1000,
        'reactions' => 25,
        'comments' => 10,
        'bookmarks' => 5,
        'shares' => 4,
        'age_hours' => 0,
    ]);

    $simResponse->assertOk()->assertJsonPath('simulation.raw_score', 239);
    expect((float) $targetPost->fresh()->trending_score)->toBe(42.5);

    // Admin updates settings, creates AuditLog, then resets trending section to defaults.
    $this->actingAs($admin)->post(route('admin.content.settings.update'), [
        'trending_weight_bookmark' => 9.5,
        'posting_limit_24h_standard' => 45,
    ])->assertRedirect();

    expect((float) app(PostingPolicyConfigService::class)->trendingWeights()['bookmark'])->toBe(9.5)
        ->and(AuditLog::query()->where('action', 'system.settings_updated')->exists())->toBeTrue();

    $this->actingAs($admin)->post(route('admin.content.settings.reset'), [
        'section' => 'trending',
    ])->assertRedirect();

    expect((float) app(PostingPolicyConfigService::class)->trendingWeights()['bookmark'])->toBe(5.0);

    // Admin rejects pending post, zeroes trending score, and sends private notification to author.
    $this->actingAs($admin)->post(route('admin.content.posts.moderate', $targetPost->id), [
        'action' => 'reject',
        'reason' => 'Violates spam linking rules.',
    ])->assertRedirect();

    $targetPost->refresh();
    expect($targetPost->moderation_status)->toBe(ModerationStatus::Rejected)
        ->and($targetPost->lifecycle_status)->toBe(PostLifecycleStatus::Rejected)
        ->and((float) $targetPost->trending_score)->toBe(0.0)
        ->and($member->notifications()->count())->toBe(1);
});

test('orphan media cleanup preserves referenced files and deletes unreferenced files past grace period', function () {
    Storage::fake('public');

    $post = Post::factory()->create();
    $media = new PostMedia([
        'disk' => 'public',
        'path' => 'posts/2026/11/referenced.webp',
        'mime_type' => 'image/webp',
        'size_bytes' => 1024,
        'sort_order' => 0,
    ]);
    $media->post_id = $post->id;
    $media->user_id = $post->user_id;
    $media->save();

    Storage::disk('public')->put('posts/2026/11/referenced.webp', 'active');
    Storage::disk('public')->put('posts/2026/11/thumbs/referenced.webp', 'active-thumb');
    Storage::disk('public')->put('posts/2026/11/orphan-old.webp', 'orphan');

    $cleanup = app(OrphanMediaCleanupService::class)->pruneOrphanPostMedia(0, 'posts');

    expect($cleanup['deleted'])->toBe(1);
    Storage::disk('public')->assertExists('posts/2026/11/referenced.webp');
    Storage::disk('public')->assertExists('posts/2026/11/thumbs/referenced.webp');
    Storage::disk('public')->assertMissing('posts/2026/11/orphan-old.webp');
});
