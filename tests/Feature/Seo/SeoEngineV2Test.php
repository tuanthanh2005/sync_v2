<?php

use App\Enums\ModerationStatus;
use App\Enums\PolicyStatus;
use App\Enums\PostVisibility;
use App\Enums\SeoEntityType;
use App\Enums\SeoIndexEligibility;
use App\Enums\SeoVerificationMethod;
use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoApiConnection;
use App\Models\SeoAuditRun;
use App\Models\SeoOrganicEvent;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use App\Services\Security\RbacService;
use App\Services\Seo\ContentTranslationService;
use App\Services\Seo\GoogleSearchConsoleService;
use App\Services\Seo\GoogleSiteVerificationService;
use App\Services\Seo\HreflangService;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\RobotsAndRedirectService;
use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoRegistryService;
use App\Services\Seo\SitemapEngineService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    (new LanguageSeeder)->run();
    SeoSeeder::seedDefaultsIfEmpty();

    // Enable indexing inside test environment when verifying indexable flows
    app(SeoConfigService::class)->set('allow_indexing_in_non_production', true, 'general', 'boolean');
});

function createSeoAdmin(): User
{
    /** @var User $admin */
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('TESTSECRET123456'),
        'two_factor_confirmed_at' => now(),
    ]);

    return $admin;
}

it('automatically registers eligible public posts into URL registry and sharded XML sitemaps with ETag and excludes private or deleted posts', function (): void {
    $author = User::factory()->create(['email_verified_at' => now()]);

    $publicPost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'How to build resilient international communities on SyncViet',
        'content' => 'This comprehensive discussion explores practical ways to grow multilingual developer communities across 15 languages with transparent moderation and fast mobile performance.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $privatePost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'Private internal draft discussion',
        'content' => 'This content is strictly private and must never be exposed in public XML sitemaps or search metadata.',
        'visibility' => PostVisibility::Private,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $thinPost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => null,
        'content' => 'Hello!',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $registry = app(SeoRegistryService::class);
    $pubReg = $registry->syncPost($publicPost);
    $privReg = $registry->syncPost($privatePost);
    $thinReg = $registry->syncPost($thinPost);

    expect($pubReg->index_eligibility)->toBe(SeoIndexEligibility::Eligible)
        ->and($privReg->index_eligibility)->toBe(SeoIndexEligibility::Excluded)
        ->and($thinReg->index_eligibility)->toBe(SeoIndexEligibility::PendingQualityReview);

    $sitemapEngine = app(SitemapEngineService::class);
    $result = $sitemapEngine->rebuildAll();

    expect($result['urls'])->toBeGreaterThanOrEqual(1);

    $indexResponse = $this->get('/sitemap.xml');
    $indexResponse->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<sitemapindex', false)
        ->assertSee('sitemap-posts-en-0001.xml', false);

    $etag = $indexResponse->headers->get('ETag');
    expect($etag)->not->toBeEmpty();

    $this->withHeaders(['If-None-Match' => (string) $etag])
        ->get('/sitemap.xml')
        ->assertStatus(304);

    $shardResponse = $this->get('/sitemap-posts-en-0001.xml');
    $shardResponse->assertOk()
        ->assertSee('/en/posts/'.$publicPost->id.'-', false)
        ->assertDontSee('/posts/'.$privatePost->id, false)
        ->assertDontSee('/posts/'.$thinPost->id, false);

    // Deleting the public post removes it from sitemap & localized route returns 410 Gone
    $publicPost->delete();
    $registry->markEntityRemoved(SeoEntityType::Post, $publicPost->id, 410);
    $sitemapEngine->rebuildAll();

    $freshPub = SeoUrlRegistry::query()->whereKey($pubReg->id)->first();
    expect($freshPub?->http_status)->toBe(410)
        ->and($freshPub?->is_in_sitemap)->toBeFalse();

    $this->get('/en/posts/'.$publicPost->id)->assertStatus(410);
});

it('renders server-side public HTML without JS, factual JSON-LD without private fields, canonical URL, and internal links', function (): void {
    $author = User::factory()->create([
        'name' => 'Elena Nguyen',
        'username' => 'elena_seo',
        'email' => 'private-elena-secret@example.com',
        'email_verified_at' => now(),
    ]);

    $tag = Hashtag::query()->create([
        'name' => 'webdev',
        'slug' => 'webdev',
        'posts_count' => 3,
    ]);

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'Laravel 13 Shared Hosting Optimization Guide',
        'content' => 'Running modern Laravel applications smoothly on shared hosting requires database queues, clean indexing, and low memory usage across scheduled tasks.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);
    $post->hashtags()->attach($tag->id);

    $response = $this->get('/posts/'.$post->id);
    $response->assertOk()
        ->assertSee('<link rel="canonical"', false)
        ->assertSee('application/ld+json', false)
        ->assertSee('DiscussionForumPosting', false)
        ->assertSee('BreadcrumbList', false)
        ->assertSee('<noscript>', false)
        ->assertSee('Laravel 13 Shared Hosting Optimization Guide')
        ->assertDontSee('private-elena-secret@example.com');

    // Profile JSON-LD strictly omits user email
    $profileRes = $this->get('/u/elena_seo');
    $profileRes->assertOk()
        ->assertSee('ProfilePage', false)
        ->assertSee('Elena Nguyen')
        ->assertDontSee('private-elena-secret@example.com');
});

it('enforces multilingual translation approval workflow and valid reciprocal hreflang without homepage x-default misuse', function (): void {
    $admin = createSeoAdmin();
    $post = Post::factory()->create([
        'user_id' => $admin->id,
        'title' => 'Global Engineering Standards',
        'content' => 'Building software for 15 languages requires clean canonical rules, reciprocal hreflang tags, and editorial review before international publication.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    app(SeoRegistryService::class)->syncPost($post);

    $translationService = app(ContentTranslationService::class);
    $draft = $translationService->upsertPostTranslation($post, [
        'target_locale' => 'vi',
        'translated_title' => 'Tiêu chuẩn kỹ thuật toàn cầu',
        'translated_body' => 'Xây dựng phần mềm cho 15 ngôn ngữ đòi hỏi quy tắc canonical sạch, thẻ hreflang đối xứng và kiểm duyệt nội dung trước khi xuất bản quốc tế.',
        'localized_slug' => 'tieu-chuan-ky-thuat-toan-cau',
    ], $admin);

    // Unapproved draft must NOT be served as indexable localized page (301 to original canonical)
    $this->get('/vi/posts/'.$post->id.'-tieu-chuan-ky-thuat-toan-cau')
        ->assertStatus(301);

    // Approve & publish translation
    $published = $translationService->approveAndPublish($draft, $admin);
    expect($published->isPublishedAndApproved())->toBeTrue();

    $localizedRes = $this->get('/vi/posts/'.$post->id.'-tieu-chuan-ky-thuat-toan-cau');
    $localizedRes->assertOk()
        ->assertSee('hreflang="en"', false)
        ->assertSee('hreflang="vi"', false)
        ->assertSee('hreflang="x-default"', false)
        ->assertSee('Tiêu chuẩn kỹ thuật toàn cầu');

    $validation = app(HreflangService::class)->validateAll();
    expect($validation['checked_groups'])->toBeGreaterThanOrEqual(1)
        ->and($validation['issues'])->toBeEmpty();
});

it('serves environment-aware dynamic robots.txt and X-Robots-Tag on sensitive routes', function (): void {
    // Indexing enabled -> allows crawl + lists sitemap
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Allow: /')
        ->assertSee('Disallow: /admin')
        ->assertSee('Sitemap:');

    // Guard indexing in non-production -> disallows all
    app(SeoConfigService::class)->set('allow_indexing_in_non_production', false, 'general', 'boolean');
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /')
        ->assertDontSee('Allow: /');

    // Parameterized search receives X-Robots-Tag noindex
    app(SeoConfigService::class)->set('allow_indexing_in_non_production', true, 'general', 'boolean');
    $this->get('/search?q=laravel')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('manages SEO redirects safely, tracks hit counts, blocks open redirects and prevents loops', function (): void {
    $admin = createSeoAdmin();
    $service = app(RobotsAndRedirectService::class);

    $redirect = $service->createOrUpdateRedirect('/legacy-community-guide', '/topics', 301, 'Migration', $admin);

    $this->get('/legacy-community-guide')
        ->assertRedirect('/topics')
        ->assertStatus(301);

    expect($redirect->fresh()?->hit_count)->toBe(1);

    // Open redirect to external origin MUST be rejected
    expect(fn () => $service->createOrUpdateRedirect('/evil-out', 'https://evil.example.com/phish', 301, null, $admin))
        ->toThrow(ValidationException::class);

    // Direct self-loop MUST be rejected
    expect(fn () => $service->createOrUpdateRedirect('/same-path', '/same-path', 301, null, $admin))
        ->toThrow(ValidationException::class);

    // Multi-hop loop MUST be rejected
    $service->createOrUpdateRedirect('/hop-a', '/hop-b', 301, null, $admin);
    expect(fn () => $service->createOrUpdateRedirect('/hop-b', '/hop-a', 301, null, $admin))
        ->toThrow(ValidationException::class);
});

it('protects Google verification honesty, encrypts GSC credentials, enforces RBAC, and supports IndexNow deduplication', function (): void {
    $admin = createSeoAdmin();
    $member = User::factory()->create(['role' => UserRole::User, 'email_verified_at' => now()]);

    // Non-admin cannot access Admin SEO Center
    $this->actingAs($member)->get('/admin/seo')->assertStatus(403);

    // Google Site Verification NEVER becomes verified just from entering a token
    $verifyService = app(GoogleSiteVerificationService::class);
    $conn = $verifyService->configure(SeoVerificationMethod::HtmlFile, 'googleabc1234567890.html', 'sc-domain:syncviet.com', $admin);
    expect($conn->status)->toBe('pending_verification')
        ->and($conn->verified_at)->toBeNull();

    // Configured HTML verification file is dynamically served
    $this->get('/googleabc1234567890.html')
        ->assertOk()
        ->assertSee('google-site-verification: googleabc1234567890.html');

    // Service Account JSON is stored encrypted & never leaked to Admin Inertia payload
    $gsc = app(GoogleSearchConsoleService::class);
    $fakeKey = "-----BEGIN PRIVATE KEY-----\nTEST_ONLY_SECRET_PAYLOAD_DO_NOT_LEAK\n-----END PRIVATE KEY-----";
    $gsc->saveServiceAccount(json_encode([
        'type' => 'service_account',
        'client_email' => 'seo-bot@syncviet.iam.gserviceaccount.com',
        'private_key' => $fakeKey,
    ]) ?: '{}', 'sc-domain:syncviet.com', $admin);

    $rawConn = SeoApiConnection::query()->where('provider', GoogleSearchConsoleService::PROVIDER)->first();
    expect($rawConn?->credentials_encrypted)->not->toContain('TEST_ONLY_SECRET_PAYLOAD_DO_NOT_LEAK');

    $this->actingAs($admin)
        ->get('/admin/seo/integrations')
        ->assertOk()
        ->assertDontSee('TEST_ONLY_SECRET_PAYLOAD_DO_NOT_LEAK');

    // IndexNow key file & deduplication
    $indexNow = app(IndexNowService::class);
    $indexNow->setEnabled(true, $admin);
    $key = $indexNow->getKey();

    $this->get("/{$key}.txt")
        ->assertOk()
        ->assertSee($key);

    $post = Post::factory()->create([
        'user_id' => $admin->id,
        'title' => 'IndexNow Deduplication Verification Post',
        'content' => 'IndexNow submissions must be deduplicated by content hash so repeated saves without content changes do not spam search engines.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);
    $reg = app(SeoRegistryService::class)->syncPost($post);
    $firstQueue = $indexNow->queueUrl($reg);
    $secondQueue = $indexNow->queueUrl($reg);

    expect($firstQueue)->not->toBeNull()
        ->and($secondQueue)->toBeNull();

    Http::fake(['https://api.indexnow.org/indexnow' => Http::response('OK', 200)]);
    $batchRes = $indexNow->submitQueuedBatch(10);
    expect($batchRes['submitted'])->toBe(1)
        ->and($batchRes['failed'])->toBe(0);
});

it('renders public topic clusters, runs automated SEO audit, and tracks privacy-safe organic growth', function (): void {
    $admin = createSeoAdmin();
    $tag = Hashtag::query()->create(['name' => 'laravel', 'slug' => 'laravel']);
    $tag->forceFill(['posts_count' => 2])->save();
    $community = Community::factory()->create(['owner_id' => $admin->id, 'visibility' => 'public', 'status' => 'active']);
    PolicyPage::factory()->create(['slug' => 'privacy-test', 'status' => PolicyStatus::Published, 'is_visible' => true]);

    Post::factory()->count(2)->create([
        'user_id' => $admin->id,
        'community_id' => $community->id,
        'title' => 'Scaling Laravel on Shared Hosting',
        'content' => 'Practical performance engineering guide covering database indexes, queue workers, and clean SEO rendering on resource-constrained shared servers.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ])->each(static fn (Post $p) => $p->hashtags()->attach($tag->id));

    // Topic pages return 200 with crawlable HTML & JSON-LD
    $this->get('/topics')->assertOk()->assertSee('#laravel');
    $this->get('/topics/laravel')->assertOk()->assertSee('CollectionPage', false);

    // Organic visit attribution without PII
    $this->withHeaders(['Referer' => 'https://www.google.com/search?q=syncviet+laravel'])
        ->get('/topics/laravel')
        ->assertOk();

    expect(SeoOrganicEvent::query()->where('event_type', 'organic_visit')->where('search_engine', 'google')->exists())
        ->toBeTrue();

    // Automated SEO Audit Engine
    app(SeoRegistryService::class)->backfillAll();
    app(SitemapEngineService::class)->rebuildAll();

    $run = app(SeoAuditService::class)->runAudit('manual', $admin);
    expect($run)->toBeInstanceOf(SeoAuditRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->technical_health_score)->toBeGreaterThanOrEqual(0)
        ->and($run->technical_health_score)->toBeLessThanOrEqual(100);

    // Admin SEO Center pages render cleanly
    $this->actingAs($admin)->get('/admin/seo')->assertOk();
    $this->actingAs($admin)->get('/admin/seo/urls')->assertOk();
    $this->actingAs($admin)->get('/admin/seo/international')->assertOk();
    $this->actingAs($admin)->get('/admin/seo/audit')->assertOk();
});
