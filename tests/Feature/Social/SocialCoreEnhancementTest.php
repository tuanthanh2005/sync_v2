<?php

use App\Enums\FooterGroup;
use App\Enums\ModerationStatus;
use App\Enums\PolicyStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Enums\ReactionType;
use App\Enums\SeoEntityType;
use App\Enums\SeoIndexEligibility;
use App\Models\AuditLog;
use App\Models\BannerPlacement;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\PolicyPageRevision;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Media\SafeImageUploadService;
use App\Services\Policy\FooterMenuService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoRegistryService;
use Database\Seeders\MonetizationSeeder;
use Database\Seeders\PolicyFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Helper to create a valid JPEG/PNG UploadedFile with real GD pixel data.
 */
function makeValidImageUpload(string $filename = 'photo.jpg', int $width = 320, int $height = 240): UploadedFile
{
    return UploadedFile::fake()->image($filename, $width, $height);
}

// ─── PHASE 1: GLOBAL IMAGE UPLOAD POLICY ─────────────────────────────────────

test('valid device image upload succeeds, converts to WebP, and stores alt text', function (): void {
    Storage::fake('public');
    config()->set('syncviet.media_disk', 'public');

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('posts.store'), [
        'title' => 'Global Image Upload Test',
        'content' => 'Sharing an authentic photo uploaded directly from my device #syncviet',
        'category' => PostCategory::Technology->value,
        'visibility' => PostVisibility::Public->value,
        'images' => [makeValidImageUpload('sunset.jpg', 640, 480)],
        'image_alts' => ['Golden sunset over the coast'],
    ]);

    $response->assertRedirect();

    $post = Post::query()->with('media')->where('user_id', $user->id)->latest('id')->firstOrFail();
    expect($post->media)->toHaveCount(1);

    $media = $post->media->first();
    expect($media)->not->toBeNull()
        ->and($media->alt_text)->toBe('Golden sunset over the coast')
        ->and($media->mime_type)->toBeIn(['image/webp', 'image/jpeg', 'image/png'])
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

test('image upload enforces strict < 40 MB ceiling (exact 40,000,000 bytes and above are rejected)', function (): void {
    $service = app(SafeImageUploadService::class);
    $exactPath = tempnam(sys_get_temp_dir(), 'sv40eq_');
    $overPath = tempnam(sys_get_temp_dir(), 'sv40ov_');
    expect($exactPath)->not->toBeFalse()
        ->and($overPath)->not->toBeFalse();

    try {
        $f1 = fopen((string) $exactPath, 'wb');
        fwrite($f1, "\xFF\xD8\xFF\xE0");
        ftruncate($f1, SafeImageUploadService::MAX_IMAGE_BYTES);
        fclose($f1);

        $f2 = fopen((string) $overPath, 'wb');
        fwrite($f2, "\xFF\xD8\xFF\xE0");
        ftruncate($f2, SafeImageUploadService::MAX_IMAGE_BYTES + 1_000_000);
        fclose($f2);

        $exactLimitFile = new UploadedFile((string) $exactPath, 'exact40mb.jpg', 'image/jpeg', null, true);
        $overLimitFile = new UploadedFile((string) $overPath, 'over40mb.jpg', 'image/jpeg', null, true);

        $exactError = $service->validateUploadedFile($exactLimitFile, 'post');
        $overError = $service->validateUploadedFile($overLimitFile, 'post');

        expect($exactError)->not->toBeNull()
            ->and($exactError)->toContain('40 MB')
            ->and($overError)->not->toBeNull()
            ->and($overError)->toContain('40 MB');
    } finally {
        @unlink((string) $exactPath);
        @unlink((string) $overPath);
    }
});

test('image upload rejects video files, SVGs, and PHP scripts disguised as JPG', function (): void {
    Storage::fake('public');
    config()->set('syncviet.media_disk', 'public');

    $user = User::factory()->create();

    $videoFile = UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4');
    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('posts.store'), [
            'content' => 'Trying to upload video',
            'category' => PostCategory::General->value,
            'visibility' => PostVisibility::Public->value,
            'images' => [$videoFile],
        ])
        ->assertSessionHasErrors('images.0');

    $svgFile = UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('posts.store'), [
            'content' => 'Trying to upload SVG',
            'category' => PostCategory::General->value,
            'visibility' => PostVisibility::Public->value,
            'images' => [$svgFile],
        ])
        ->assertSessionHasErrors('images.0');

    $fakePhpAsJpg = UploadedFile::fake()->createWithContent('avatar.jpg', '<?php system($_GET["cmd"]); ?>');
    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('posts.store'), [
            'content' => 'Trying to upload disguised PHP',
            'category' => PostCategory::General->value,
            'visibility' => PostVisibility::Public->value,
            'images' => [$fakePhpAsJpg],
        ])
        ->assertSessionHasErrors('images.0');
});

test('banner booking strictly requires device image upload and rejects external image URLs', function (): void {
    $this->seed(MonetizationSeeder::class);
    $user = User::factory()->create();
    $placement = BannerPlacement::query()->where('is_active', true)->firstOrFail();

    $this->actingAs($user)
        ->from(route('monetization.banner'))
        ->post(route('banners.campaigns.store'), [
            'banner_placement_id' => $placement->id,
            'name' => 'External URL Banner Attempt',
            'target_url' => 'https://syncviet.com',
            'alt_text' => 'Promo banner',
            'desktop_image_url' => 'https://external-site.example/hotlink.jpg',
            'duration_days' => 7,
            'starts_at' => now()->addDay()->toDateString(),
            'currency' => 'VND',
            'provider' => 'bank_transfer',
            'idempotency_key' => 'banner-test-'.str()->random(12),
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('desktop_image');
});

test('PostTooLargeException returns friendly localized HTTP 413 message without stack trace', function (): void {
    Route::post('/_test-post-too-large', static function (): never {
        throw new PostTooLargeException('Content length exceeded');
    })->middleware('web');

    $response = $this->postJson('/_test-post-too-large');

    $response->assertStatus(413)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonMissing(['exception', 'trace']);
});

// ─── PHASE 2: PROFILE EDIT & POST COMPOSER / REACTIONS ──────────────────────

test('member can upload and remove avatar and cover with audit logging and safe invalid-upload rollback', function (): void {
    Storage::fake('public');
    config()->set('syncviet.media_disk', 'public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.details.update'), [
            'name' => 'Updated Member',
            'headline' => 'Product Designer',
            'bio' => 'Building clean global communities.',
            'avatar' => makeValidImageUpload('avatar.png', 400, 400),
            'cover' => makeValidImageUpload('cover.jpg', 1200, 400),
        ])
        ->assertRedirect();

    $user->refresh()->load('profile');
    expect($user->profile?->headline)->toBe('Product Designer')
        ->and($user->profile?->bio)->toBe('Building clean global communities.')
        ->and($user->profile?->avatar_path)->not->toBeNull()
        ->and($user->profile?->cover_path)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'profile.avatar_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'profile.cover_updated')->exists())->toBeTrue();

    $savedAvatarPath = $user->profile->avatar_path;

    // Invalid upload must not clear existing avatar
    $badFile = UploadedFile::fake()->createWithContent('bad.jpg', 'not-an-image');
    $this->actingAs($user)
        ->from(route('profile.me'))
        ->patch(route('profile.details.update'), [
            'name' => 'Updated Member',
            'avatar' => $badFile,
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()->profile?->avatar_path)->toBe($savedAvatarPath);

    // Explicit remove clears avatar and cover
    $this->actingAs($user)
        ->patch(route('profile.details.update'), [
            'name' => 'Updated Member',
            'remove_avatar' => true,
            'remove_cover' => true,
        ])
        ->assertRedirect();

    $user->refresh()->load('profile');
    expect($user->profile?->avatar_path)->toBeNull()
        ->and($user->profile?->cover_path)->toBeNull();
});

test('post reactions toggle, switch, and remove cleanly without duplicate rows', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $post = new Post([
        'category' => PostCategory::General->value,
        'title' => 'Reaction Stability Check',
        'content' => 'Testing reaction switch and toggle idempotency.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $post->user_id = $author->id;
    $post->moderation_status = ModerationStatus::Approved;
    $post->save();

    // 1. Add Like
    $this->actingAs($viewer)
        ->post(route('posts.react', $post), ['type' => ReactionType::Like->value])
        ->assertRedirect();

    expect($post->fresh()->reactions_count)->toBe(1)
        ->and(PostReaction::query()->where('post_id', $post->id)->where('user_id', $viewer->id)->count())->toBe(1);

    // 2. Switch Like -> Insightful (no duplicate rows, total remains 1)
    $this->actingAs($viewer)
        ->post(route('posts.react', $post), ['type' => ReactionType::Insightful->value])
        ->assertRedirect();

    $reaction = PostReaction::query()->where('post_id', $post->id)->where('user_id', $viewer->id)->first();
    expect($post->fresh()->reactions_count)->toBe(1)
        ->and(PostReaction::query()->where('post_id', $post->id)->where('user_id', $viewer->id)->count())->toBe(1)
        ->and($reaction?->type)->toBe(ReactionType::Insightful);

    // 3. Click same reaction again -> removes reaction
    $this->actingAs($viewer)
        ->post(route('posts.react', $post), ['type' => ReactionType::Insightful->value])
        ->assertRedirect();

    expect($post->fresh()->reactions_count)->toBe(0)
        ->and(PostReaction::query()->where('post_id', $post->id)->where('user_id', $viewer->id)->count())->toBe(0);
});

// ─── PHASE 3: ACCURATE POST VIEW TRACKING V2.0 ───────────────────────────────

test('post views are never blindly incremented on GET, deduplicate per 5-minute window, classify AI separately, reject bots, and survive pruning', function (): void {
    $author = User::factory()->create();
    $human = User::factory()->create();
    $aiAgent = User::factory()->create(['account_type' => 'ai_agent']);

    $post = new Post([
        'category' => PostCategory::Technology->value,
        'title' => 'Accurate View Tracking Verification',
        'content' => 'Views must only count genuine viewport-qualified reads.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $post->user_id = $author->id;
    $post->moderation_status = ModerationStatus::Approved;
    $post->save();

    // GET feed & detail MUST NOT increment views_count
    $this->get(route('home'))->assertOk();
    $this->get(route('posts.show', $post))->assertOk();
    expect($post->fresh()->views_count)->toBe(0);

    // Bot user-agent rejected
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 500, 'visible_ratio' => 60]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    // Prefetch header rejected
    $this->flushHeaders();
    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/130.0',
        'Purpose' => 'prefetch',
    ])->postJson(route('posts.views.batch'), [
        'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 500, 'visible_ratio' => 60]],
    ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    expect($post->fresh()->views_count)->toBe(0)
        ->and($post->fresh()->invalid_views_count)->toBe(2);

    // AI Agent web view classified as ai_view and strictly excluded from human unique viewers & organic human reach
    $this->flushHeaders();
    $this->actingAs($aiAgent)
        ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/130.0')
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 600, 'visible_ratio' => 70]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1, 'invalid_traffic' => false]);

    $post->refresh();
    expect($post->views_count)->toBe(1)
        ->and($post->ai_views_count)->toBe(1)
        ->and($post->human_views_count)->toBe(0)
        ->and($post->unique_human_viewers_count)->toBe(0)
        ->and($post->organic_views_count)->toBe(0);

    // Genuine human view recorded once and returned in real-time counts map
    $this->flushHeaders();
    $this->actingAs($human)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0')
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 550, 'visible_ratio' => 65]],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 1,
            'invalid_traffic' => false,
            'counts' => [
                $post->id => ['views_count' => 2],
            ],
        ]);

    $post->refresh();
    expect($post->views_count)->toBe(2)
        ->and($post->human_views_count)->toBe(1)
        ->and($post->organic_views_count)->toBe(1)
        ->and($post->unique_human_viewers_count)->toBe(1);

    // Repeat batch within 5-minute deduplication window does NOT inflate
    $this->actingAs($human)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0')
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 800, 'visible_ratio' => 80]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    expect($post->fresh()->views_count)->toBe(2);

    // Old raw events are pruned cleanly while post counter cache stays intact
    DB::table('post_view_events')
        ->where('post_id', $post->id)
        ->update(['created_at' => now()->subDays(45)]);

    $this->artisan('app:views:prune')->assertSuccessful();

    expect(DB::table('post_view_events')->where('post_id', $post->id)->count())->toBe(0)
        ->and($post->fresh()->views_count)->toBe(2);
});

// ─── PHASE 4: INTELLIGENT SEARCH & EXPLORE ───────────────────────────────────

test('empty search renders Explore discovery mode and keyword search respects privacy, blocks, Unicode/Vietnamese, and noindex', function (): void {
    $viewer = User::factory()->create();
    $author = User::factory()->create(['name' => 'Minh Nguyen']);
    $blockedAuthor = User::factory()->create(['name' => 'Blocked Person']);

    $block = new UserBlock;
    $block->blocker_id = $viewer->id;
    $block->blocked_id = $blockedAuthor->id;
    $block->save();

    $publicPost = new Post([
        'category' => PostCategory::Finance->value,
        'title' => 'Đầu tư công nghệ dài hạn',
        'content' => 'Phân tích chi tiết về đầu tư hạ tầng phần mềm và cộng đồng công nghệ.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $publicPost->user_id = $author->id;
    $publicPost->moderation_status = ModerationStatus::Approved;
    $publicPost->save();

    // Private post should never leak in search
    $privatePost = new Post([
        'category' => PostCategory::Finance->value,
        'title' => 'Đầu tư bí mật riêng tư',
        'content' => 'Ghi chú đầu tư cá nhân ở chế độ riêng tư.',
        'visibility' => PostVisibility::Private->value,
        'published_at' => now(),
    ]);
    $privatePost->user_id = $author->id;
    $privatePost->moderation_status = ModerationStatus::Approved;
    $privatePost->save();

    // Blocked user post must be hidden from viewer
    $blockedPost = new Post([
        'category' => PostCategory::Finance->value,
        'title' => 'Đầu tư của người bị chặn',
        'content' => 'Bài viết đầu tư cần được ẩn khỏi người đã chặn.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $blockedPost->user_id = $blockedAuthor->id;
    $blockedPost->moderation_status = ModerationStatus::Approved;
    $blockedPost->save();

    $tag = new Hashtag([
        'name' => 'fintech',
        'slug' => 'fintech',
    ]);
    $tag->posts_count = 3;
    $tag->save();

    $community = new Community([
        'name' => 'Vietnam Developers',
        'slug' => 'vietnam-developers',
        'category' => PostCategory::Technology->value,
        'visibility' => 'public',
    ]);
    $community->owner_id = $author->id;
    $community->status = 'active';
    $community->members_count = 12;
    $community->save();

    // 1. Empty /search -> Explore mode (never empty error)
    $this->actingAs($viewer)
        ->get(route('search'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->where('mode', 'explore')
            ->has('discovery.latest_posts', 1)
            ->has('discovery.suggested_communities', 1),
        );

    // 2. Vietnamese Unicode query ("công nghệ") & `d` -> `đ` query ("dầu tư") match public post ONLY, exclude private & blocked
    $searchResponse = $this->actingAs($viewer)
        ->get(route('search', ['q' => 'đầu tư']));

    $searchResponse->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->where('mode', 'search')
            ->where('counts.posts', 1)
            ->where('posts.0.id', $publicPost->id),
        );

    expect($searchResponse->getContent())->toContain('noindex');

    // `d` -> `đ` folding variant also matches "đầu tư" directly
    $this->actingAs($viewer)
        ->get(route('search', ['q' => 'dầu tư']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.posts', 1)
            ->where('posts.0.id', $publicPost->id),
        );

    // 3. Suggestions endpoint returns JSON cleanly
    $this->actingAs($viewer)
        ->getJson(route('search.suggestions', ['q' => 'fin']))
        ->assertOk()
        ->assertJsonPath('topics.0.slug', 'fintech');
});

// ─── PHASE 5: MODERN FOOTER, ADMIN CMS, REVISIONS, MULTILINGUAL & SEO ────────

test('admin CMS supports create, revisions/restore, multilingual localized routes with hreflang, sanitizer internal images, and placeholder email filtering', function (): void {
    Storage::fake('public');
    config()->set('syncviet.media_disk', 'public');
    $this->seed(PolicyFoundationSeeder::class);

    $admin = User::factory()->admin()->withTwoFactor()->create();
    $member = User::factory()->create();

    // Non-admin strictly blocked
    $this->actingAs($member)
        ->post(route('admin.policies.store'), [
            'slug' => 'unauthorized-page',
            'page_type' => 'general',
            'title_en' => 'Unauthorized',
            'title_vi' => 'Không được phép',
            'status' => PolicyStatus::Draft->value,
            'is_visible' => false,
            'footer_group' => FooterGroup::Company->value,
            'sort_order' => 50,
        ])
        ->assertForbidden();

    // 1. Create a new CMS page as Admin
    $this->actingAs($admin)
        ->post(route('admin.policies.store'), [
            'slug' => 'security-practices',
            'page_type' => 'legal',
            'title_en' => 'Security Practices',
            'title_vi' => 'Thực hành bảo mật',
            'summary_en' => 'How SyncViet protects member accounts.',
            'summary_vi' => 'Cách SyncViet bảo vệ tài khoản thành viên.',
            'content_en' => '<h2>Overview</h2><p>Version one content.</p>',
            'content_vi' => '<h2>Tổng quan</h2><p>Nội dung phiên bản một.</p>',
            'status' => PolicyStatus::Published->value,
            'is_visible' => true,
            'index_eligible' => true,
            'footer_group' => FooterGroup::Legal->value,
            'placement' => 'main',
            'sort_order' => 15,
        ])
        ->assertRedirect();

    $policy = PolicyPage::query()->where('slug', 'security-practices')->firstOrFail();
    expect($policy->status)->toBe(PolicyStatus::Published)
        ->and($policy->revisions()->where('locale', 'en')->count())->toBe(1);

    // 2. Update content with safe internal image + dangerous external image & script (sanitizer check)
    $this->actingAs($admin)
        ->patch(route('admin.policies.update', $policy->slug), [
            'page_type' => 'legal',
            'title_en' => 'Security Practices Updated',
            'title_vi' => 'Thực hành bảo mật cập nhật',
            'content_en' => '<p>Version two content.</p><img src="/storage/cms/content/diagram.webp" alt="Architecture"><img src="https://evil.example/hotlink.png" alt="Blocked"><script>alert(1)</script>',
            'content_vi' => '<p>Nội dung phiên bản hai.</p>',
            'status' => PolicyStatus::Published->value,
            'is_visible' => true,
            'index_eligible' => true,
            'footer_group' => FooterGroup::Legal->value,
            'placement' => 'main',
            'sort_order' => 15,
            'change_note' => 'Added architecture diagram',
        ])
        ->assertRedirect();

    $policy->refresh();
    expect($policy->content_en)->toContain('/storage/cms/content/diagram.webp')
        ->and($policy->content_en)->not->toContain('evil.example')
        ->and($policy->content_en)->not->toContain('<script>')
        ->and($policy->revisions()->where('locale', 'en')->count())->toBe(2);

    // 3. Restore Version 1 revision
    /** @var PolicyPageRevision $v1 */
    $v1 = $policy->revisions()->where('locale', 'en')->where('version_number', 1)->firstOrFail();
    $this->actingAs($admin)
        ->post(route('admin.policies.revisions.restore', ['policyPage' => $policy->slug, 'revision' => $v1->id]))
        ->assertRedirect();

    expect($policy->fresh()->content_en)->toContain('Version one content.');

    // 4. Save genuine published French translation -> /fr/policies/{slug} works, untranslated /ja redirects 301
    $this->actingAs($admin)
        ->post(route('admin.policies.translations.save', $policy->slug), [
            'locale' => 'fr',
            'title' => 'Pratiques de sécurité',
            'summary' => 'Comment SyncViet protège vos données.',
            'content' => '<p>Contenu français authentique et publié.</p>',
            'status' => 'published',
        ])
        ->assertRedirect();

    // Public guest visit: /fr/policies/{slug} returns 200 with hreflang, untranslated /ja redirects 301
    auth()->logout();
    $frResponse = $this->get(route('policies.localized.show', ['locale' => 'fr', 'policyPage' => $policy->slug]));
    $frResponse->assertOk();
    expect($frResponse->getContent())->toContain('hreflang="fr"')
        ->and($frResponse->getContent())->toContain('hreflang="x-default"');

    $this->get(route('policies.localized.show', ['locale' => 'ja', 'policyPage' => $policy->slug]))
        ->assertStatus(301)
        ->assertRedirect(route('policies.show', ['policyPage' => $policy->slug]));

    // 5. Sync policy to SEO URL registry (canonical + localized fr)
    app(SeoConfigService::class)->set('allow_indexing_in_non_production', true, 'general', 'boolean');
    app(SeoRegistryService::class)->syncPolicy($policy->fresh());
    expect(
        SeoUrlRegistry::query()
            ->where('entity_type', SeoEntityType::Policy->value)
            ->where('entity_id', $policy->id)
            ->where('index_eligibility', SeoIndexEligibility::Eligible->value)
            ->count(),
    )->toBeGreaterThanOrEqual(2);

    // 6. Placeholder support email is strictly suppressed; real support email renders cleanly
    $footerMenu = app(FooterMenuService::class);
    expect($footerMenu->sanitizeSupportEmail('hello@example.com'))->toBeNull()
        ->and($footerMenu->sanitizeSupportEmail('support@syncviet.com'))->toBe('support@syncviet.com');

    $this->actingAs($admin)
        ->from(route('admin.policies.index'))
        ->post(route('admin.policies.footer-settings'), [
            'contact_email' => 'hello@example.com',
        ])
        ->assertSessionHasErrors('contact_email');

    $this->actingAs($admin)
        ->post(route('admin.policies.footer-settings'), [
            'contact_email' => 'support@syncviet.com',
            'support_contact_info' => 'Global Support',
            'footer_copyright_text' => '© 2026 SyncViet',
        ])
        ->assertRedirect();

    $sharedFooter = $footerMenu->shared('en');
    expect($sharedFooter['supportEmail'])->toBe('support@syncviet.com')
        ->and($sharedFooter['contactInfo'])->toBe('Global Support')
        ->and($sharedFooter['copyrightText'])->toBe('© 2026 SyncViet')
        ->and(count($sharedFooter['mainLinks']))->toBeGreaterThanOrEqual(1);

    // 7. Archiving a page immediately returns 404 publicly and hides it from footer
    $this->actingAs($admin)
        ->patch(route('admin.policies.update', $policy->slug), [
            'page_type' => 'legal',
            'title_en' => 'Security Practices',
            'title_vi' => 'Thực hành bảo mật',
            'content_en' => '<p>Archived content.</p>',
            'content_vi' => '<p>Nội dung lưu trữ.</p>',
            'status' => PolicyStatus::Archived->value,
            'is_visible' => true,
            'footer_group' => FooterGroup::Legal->value,
            'placement' => 'main',
            'sort_order' => 15,
        ])
        ->assertRedirect();

    auth()->logout();
    $this->get(route('policies.show', $policy->slug))->assertNotFound();
    $afterArchive = $footerMenu->shared('en');
    expect(collect($afterArchive['links'])->pluck('href'))->not->toContain(route('policies.show', $policy->slug));
});
