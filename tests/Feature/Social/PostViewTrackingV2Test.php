<?php

use App\Enums\AccountType;
use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Enums\UserRole;
use App\Models\AdCampaign;
use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use App\Services\Admin\AdminAnalyticsService;
use App\Services\Ads\AdDeliveryService;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\Social\PostViewTrackingService;
use App\Services\System\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function makeApprovedPublicPost(User $author, string $title = 'V2 Tracking Post'): Post
{
    $post = new Post([
        'category' => PostCategory::Technology->value,
        'title' => $title,
        'content' => 'Detailed technical discussion for News Feed view tracking verification.',
        'visibility' => PostVisibility::Public->value,
        'published_at' => now(),
    ]);
    $post->user_id = $author->id;
    $post->moderation_status = ModerationStatus::Approved;
    $post->save();

    return $post;
}

const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';

test('counts view when displayed >= 500ms with >= 50% visibility and rejects < 500ms, < 50% ratio, or hidden tab', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $post = makeApprovedPublicPost($author);

    // 1. Under 500ms -> rejected
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $post->id,
                'source' => 'feed',
                'visible_ms' => 450,
                'visible_ratio' => 80,
                'is_tab_visible' => true,
            ]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    // 2. Under 50% visible (not a tall-post probe) -> rejected
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $post->id,
                'source' => 'feed',
                'visible_ms' => 700,
                'visible_ratio' => 35,
                'is_tall_post_probe' => false,
                'is_tab_visible' => true,
            ]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    // 3. Background tab (is_tab_visible = false) -> rejected
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $post->id,
                'source' => 'feed',
                'visible_ms' => 1200,
                'visible_ratio' => 90,
                'is_tab_visible' => false,
            ]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    expect($post->fresh()->views_count)->toBe(0);

    // 4. Exactly 500ms & 50% visible in active tab -> recorded + confirmed count returned
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $post->id,
                'source' => 'feed',
                'visible_ms' => 500,
                'visible_ratio' => 50,
                'is_tab_visible' => true,
            ]],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 1,
            'skipped' => 0,
            'counts' => [
                $post->id => ['views_count' => 1],
            ],
        ]);

    $post->refresh();
    expect($post->views_count)->toBe(1)
        ->and($post->human_views_count)->toBe(1)
        ->and($post->unique_human_viewers_count)->toBe(1)
        ->and($post->organic_views_count)->toBe(1);

    // 5. Tall-post representative probe counts for another post when card exceeds viewport
    $tallPost = makeApprovedPublicPost($author, 'Very Tall Article');
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $tallPost->id,
                'source' => 'feed',
                'visible_ms' => 520,
                'visible_ratio' => 25,
                'is_tall_post_probe' => true,
                'is_tab_visible' => true,
            ]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    expect($tallPost->fresh()->views_count)->toBe(1);
});

test('two posts qualifying simultaneously in viewport each receive +1 view independently', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $postA = makeApprovedPublicPost($author, 'Feed Post A');
    $postB = makeApprovedPublicPost($author, 'Feed Post B');

    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [
                ['post_id' => $postA->id, 'source' => 'feed', 'visible_ms' => 500, 'visible_ratio' => 70],
                ['post_id' => $postB->id, 'source' => 'feed', 'visible_ms' => 550, 'visible_ratio' => 65],
            ],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 2,
            'skipped' => 0,
            'counts' => [
                $postA->id => ['views_count' => 1],
                $postB->id => ['views_count' => 1],
            ],
        ]);

    expect($postA->fresh()->views_count)->toBe(1)
        ->and($postB->fresh()->views_count)->toBe(1);
});

test('enforces exact 5-minute sliding deduplication using simulated time without boundary double-counts and counts again after 5 minutes', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 11, 27, 8, 2, 30, 'UTC'));

    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $post = makeApprovedPublicPost($author);

    // 08:02:30 -> +1 View
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 520, 'visible_ratio' => 75]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    // 08:04:30 (2 min later, refresh) -> blocked
    $this->travel(2)->minutes();
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 600, 'visible_ratio' => 80]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    // 08:06:00 (3m30s later — crosses the 08:05:00 clock bucket boundary!) -> STILL blocked by sliding 5-minute window!
    $this->travel(90)->seconds();
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 600, 'visible_ratio' => 80]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    expect($post->fresh()->views_count)->toBe(1);

    // 08:08:30 (6 minutes after 08:02:30) -> qualifies again for +1 View; unique_human_viewers stays 1!
    $this->travel(150)->seconds();
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 550, 'visible_ratio' => 90]],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 1,
            'counts' => [
                $post->id => ['views_count' => 2],
            ],
        ]);

    $post->refresh();
    expect($post->views_count)->toBe(2)
        ->and($post->human_views_count)->toBe(2)
        ->and($post->unique_human_viewers_count)->toBe(1);

    Carbon::setTestNow();
});

test('counts guest views via anonymous session ID and admin/moderator views on other authors posts, while excluding author self-view by default', function (): void {
    $author = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $moderator = User::factory()->create(['role' => UserRole::Moderator]);
    $post = makeApprovedPublicPost($author);

    // 1. Author self-view -> excluded by default
    $this->actingAs($author)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 1000, 'visible_ratio' => 100]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    expect($post->fresh()->views_count)->toBe(0);

    // 2. Guest with persistent anonymous session cookie -> recorded as guest_views_count and deduplicated in 5m
    auth()->logout();
    $guestLanding = $this->get(route('home'))->assertOk();
    $sessionCookieName = (string) config('session.cookie');
    $guestSessionCookie = collect($guestLanding->headers->getCookies())
        ->first(static fn ($cookie): bool => $cookie->getName() === $sessionCookieName);
    expect($guestSessionCookie)->not->toBeNull();
    $guestCookieValue = (string) $guestSessionCookie->getValue();

    $this->withCredentials()
        ->withUnencryptedCookie($sessionCookieName, $guestCookieValue)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 500, 'visible_ratio' => 60]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    $this->withCredentials()
        ->withUnencryptedCookie($sessionCookieName, $guestCookieValue)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 500, 'visible_ratio' => 60]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    // 3. Admin viewing another user's post -> recorded as human_views_count
    $this->actingAs($admin)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 600, 'visible_ratio' => 85]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    // 4. Moderator viewing another user's post -> recorded as human_views_count
    $this->actingAs($moderator)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 510, 'visible_ratio' => 60]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    $post->refresh();
    expect($post->views_count)->toBe(3)
        ->and($post->guest_views_count)->toBe(1)
        ->and($post->human_views_count)->toBe(2)
        ->and($post->unique_human_viewers_count)->toBe(2)
        ->and($post->unique_viewers_count)->toBe(3);
});

test('tracks AI Agent web view and backend AI content access separately, never counts list-only fetches, and strictly excludes AI from human and ad metrics', function (): void {
    $author = User::factory()->create();
    $aiUser = User::factory()->create([
        'account_type' => AccountType::AiAgent,
        'role' => UserRole::User,
    ]);
    $agent = AiAgent::query()->create([
        'user_id' => $aiUser->id,
        'display_name' => 'SyncViet AI Researcher',
        'username' => 'syncviet_ai_researcher',
        'ai_disclosure' => 'Official SyncViet AI Agent',
        'default_language' => 'en',
        'system_prompt' => 'Provide objective summaries.',
        'is_enabled' => true,
    ]);

    $post = makeApprovedPublicPost($author);

    // 1. AI Agent fetching lists or post detail GET -> MUST NOT count views
    $this->actingAs($aiUser)->get(route('home'))->assertOk();
    $this->actingAs($aiUser)->get(route('feed.more'))->assertOk();
    $this->actingAs($aiUser)->get(route('posts.show', $post))->assertOk();
    expect($post->fresh()->views_count)->toBe(0);

    // 2. AI Agent genuine web UI view (even on a promoted post) -> counts ai_views_count, ZERO promoted/human impact!
    $this->actingAs($aiUser)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [[
                'post_id' => $post->id,
                'source' => 'feed',
                'is_promoted' => true,
                'visible_ms' => 650,
                'visible_ratio' => 75,
            ]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    $post->refresh();
    expect($post->views_count)->toBe(1)
        ->and($post->ai_views_count)->toBe(1)
        ->and($post->ai_content_accesses_count)->toBe(0)
        ->and($post->human_views_count)->toBe(0)
        ->and($post->unique_human_viewers_count)->toBe(0)
        ->and($post->promoted_views_count)->toBe(0)
        ->and($post->organic_views_count)->toBe(0);

    // 3. Backend AI Content Access within same 5-minute window for same AI Agent -> deduplicated!
    $tracker = app(PostViewTrackingService::class);
    expect($tracker->recordAiContentAccess($post, $agent, 'ai_reply_draft'))->toBeFalse();

    // 4. After 6 minutes, genuine backend AI content access -> recorded as ai_content_accesses_count!
    $this->travel(6)->minutes();
    expect($tracker->recordAiContentAccess($post, $agent, 'ai_reply_draft'))->toBeTrue();

    $post->refresh();
    expect($post->views_count)->toBe(2)
        ->and($post->ai_views_count)->toBe(1)
        ->and($post->ai_content_accesses_count)->toBe(1)
        ->and($post->unique_human_viewers_count)->toBe(0)
        ->and($post->promoted_views_count)->toBe(0);

    // 5. AI Agent strictly excluded from Paid Ad Campaign impressions & Organic Human Traffic & Human DAU
    $campaign = new AdCampaign;
    $campaign->forceFill([
        'user_id' => $author->id,
        'post_id' => $post->id,
        'name' => 'Promoted Tech Launch',
        'objective' => AdObjective::Reach,
        'billing_mode' => 'fixed_package',
        'budget_type' => 'total',
        'total_budget' => 200_000,
        'held_amount' => 0,
        'spent_amount' => 0,
        'status' => AdCampaignStatus::Active,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
    ])->save();

    $adRequest = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => BROWSER_UA]);
    app(AdDeliveryService::class)->recordImpression($campaign, $aiUser, $adRequest);
    expect($campaign->fresh()->impressions_count)->toBe(0);

    app(OrganicGrowthService::class)->recordEvent('post_view', $adRequest, $aiUser, 'post', $post->id);
    expect(DB::table('seo_organic_events')->where('user_id', $aiUser->id)->count())->toBe(0);

    $aiUser->forceFill(['last_activity_at' => now()])->save();
    $dashboard = app(AdminAnalyticsService::class)->dashboardPayload();
    expect($dashboard['metrics']['views_summary']['ai_views'])->toBe(1)
        ->and($dashboard['metrics']['views_summary']['ai_content_accesses'])->toBe(1)
        ->and($dashboard['metrics']['views_summary']['unique_human_viewers'])->toBe(0);
});

test('rejects bots, crawlers, prefetch, background jobs, automated test accounts, and system official accounts', function (): void {
    $author = User::factory()->create();
    $testAccount = User::factory()->create(['is_test_account' => true]);
    $systemAccount = User::factory()->create(['account_type' => AccountType::SystemOfficial]);
    $post = makeApprovedPublicPost($author);

    // Googlebot
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 1000, 'visible_ratio' => 100]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    // Background job header
    $this->flushHeaders();
    $this->withHeaders(['User-Agent' => BROWSER_UA, 'X-Background-Job' => '1'])
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 1000, 'visible_ratio' => 100]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    // Automated test account
    $this->flushHeaders();
    $this->actingAs($testAccount)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 1000, 'visible_ratio' => 100]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    // System Official account
    $this->actingAs($systemAccount)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 1000, 'visible_ratio' => 100]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'invalid_traffic' => true]);

    $post->refresh();
    expect($post->views_count)->toBe(0)
        ->and($post->invalid_views_count)->toBe(4);
});

test('handles API retry and concurrent race requests safely without duplicate views', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $post = makeApprovedPublicPost($author);

    // 1. API retry: identical request sent 3 times back-to-back -> only 1 counted
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($viewer)
            ->withHeader('User-Agent', BROWSER_UA)
            ->postJson(route('posts.views.batch'), [
                'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 510, 'visible_ratio' => 70]],
            ])
            ->assertOk();
    }

    expect($post->fresh()->views_count)->toBe(1)
        ->and(DB::table('post_view_events')->where('post_id', $post->id)->count())->toBe(1);

    // 2. Concurrent lock collision simulation: when another worker holds the per-(post, viewer) lock, second request is cleanly skipped
    $viewer2 = User::factory()->create();
    $viewer2Hash = hash_hmac('sha256', 'user:'.$viewer2->id, (string) config('app.key', 'syncviet'));
    $lock = Cache::lock("sv:view:{$post->id}:{$viewer2Hash}", 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($viewer2)
            ->withHeader('User-Agent', BROWSER_UA)
            ->postJson(route('posts.views.batch'), [
                'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 600, 'visible_ratio' => 70]],
            ])
            ->assertOk()
            ->assertJson(['recorded' => 0, 'skipped' => 1]);
    } finally {
        $lock->release();
    }

    expect($post->fresh()->views_count)->toBe(1);

    // 3. DB UNIQUE(post_id, viewer_hash, viewed_bucket) guarantees at most 1 row even if lock is bypassed
    $this->actingAs($viewer2)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 600, 'visible_ratio' => 70]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    expect($post->fresh()->views_count)->toBe(2)
        ->and(DB::table('post_view_events')->where('post_id', $post->id)->where('viewer_user_id', $viewer2->id)->count())->toBe(1);
});

test('keeps database and frontend Inertia views consistent, exposes breakdown only to author/staff, preserves legacy views, and enforces admin settings audit', function (): void {
    app(RbacService::class)->syncCatalog();

    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
    $admin->forceFill(['rbac_role_id' => $superAdminRoleId])->save();

    $post = makeApprovedPublicPost($author);

    // Simulate pre-V2 legacy views preserved honestly without guessing human source
    DB::table('posts')->where('id', $post->id)->update([
        'views_count' => 12,
        'legacy_views_count' => 12,
    ]);

    // Human viewer records a new view -> total becomes 13, legacy remains 12, human becomes 1
    $response = $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'feed', 'visible_ms' => 550, 'visible_ratio' => 65]],
        ])
        ->assertOk();

    // Non-owner viewer gets live views_count = 13 but NEVER gets private analytics breakdown
    $response->assertJson([
        'recorded' => 1,
        'counts' => [
            $post->id => [
                'views_count' => 13,
                'view_analytics' => null,
            ],
        ],
    ]);

    // Post Detail Inertia payload matches database for viewer (no analytics breakdown) and author (full breakdown, zero viewer PII)
    $this->actingAs($viewer)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('post.views_count', 13)
            ->where('post.view_analytics', null));

    $this->actingAs($author)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('post.views_count', 13)
            ->where('post.view_analytics.total_views', 13)
            ->where('post.view_analytics.human_views', 1)
            ->where('post.view_analytics.legacy_views', 12)
            ->missing('post.view_analytics.viewers'));

    // Admin Settings update for view tracking rules logs to AuditLog and takes effect immediately
    $settings = app(SystemSettingService::class);
    $settings->updateMany([
        'views_min_duration_ms' => 800,
        'views_track_author_self' => true,
        'views_dedup_window_seconds' => 120,
    ], $admin, app(AuditLogger::class));

    expect(AuditLog::query()->where('action', 'system.settings_updated')->where('user_id', $admin->id)->exists())->toBeTrue();

    // Author self-view below new 800ms threshold still rejected, >= 800ms now accepted!
    $this->actingAs($author)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 600, 'visible_ratio' => 90]],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'skipped' => 1]);

    $this->actingAs($author)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [['post_id' => $post->id, 'source' => 'detail', 'visible_ms' => 850, 'visible_ratio' => 90]],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 1,
            'dedup_window_seconds' => 120,
            'counts' => [
                $post->id => [
                    'views_count' => 14,
                    'view_analytics' => [
                        'total_views' => 14,
                        'human_views' => 2,
                        'legacy_views' => 12,
                    ],
                ],
            ],
        ]);
});

test('idle open page past 5 minutes never auto-increments views and private posts never leak counts to non-owners', function (): void {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $publicPost = makeApprovedPublicPost($author);

    $privatePost = new Post([
        'category' => PostCategory::Technology->value,
        'title' => 'Private Note',
        'content' => 'Only author can access this private post.',
        'visibility' => PostVisibility::Private->value,
        'published_at' => now(),
    ]);
    $privatePost->user_id = $author->id;
    $privatePost->moderation_status = ModerationStatus::Approved;
    $privatePost->save();

    // Record initial view
    $this->actingAs($viewer)
        ->withHeader('User-Agent', BROWSER_UA)
        ->postJson(route('posts.views.batch'), [
            'views' => [
                ['post_id' => $publicPost->id, 'source' => 'detail', 'visible_ms' => 520, 'visible_ratio' => 80],
                ['post_id' => $privatePost->id, 'source' => 'detail', 'visible_ms' => 520, 'visible_ratio' => 80],
            ],
        ])
        ->assertOk()
        ->assertJson([
            'recorded' => 1,
            'skipped' => 1,
        ])
        ->assertJsonMissingPath("counts.{$privatePost->id}");

    // Advance 10 minutes while user remains idle (no new qualifying view triggered)
    $this->travel(10)->minutes();
    expect($publicPost->fresh()->views_count)->toBe(1)
        ->and($privatePost->fresh()->views_count)->toBe(0);

    // Frontend composable explicitly locks recordedInCurrentStay until viewport exit/re-entry
    $composableSource = file_get_contents(resource_path('js/composables/usePostViewTracker.ts'));
    expect($composableSource)->toContain('recordedInCurrentStay')
        ->and($composableSource)->toContain('if (!entry.isIntersecting)');
});
