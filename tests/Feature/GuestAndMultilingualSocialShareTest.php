<?php

use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Post;
use App\Models\User;
use App\Services\Growth\ReferralService;
use App\Services\Security\RbacService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoMetadataService;
use App\Services\System\LanguageService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RbacService::class)->syncCatalog();
    (new LanguageSeeder)->run();
    SeoSeeder::seedDefaultsIfEmpty();
    app(SeoConfigService::class)->set('allow_indexing_in_non_production', true, 'general', 'boolean');
});

test('raster og-default.png uses stylized S mark and serves HTTP 200 png with valid dimensions', function (): void {
    $file = public_path('og-default.png');
    expect(is_file($file))->toBeTrue();

    $info = @getimagesize($file);
    expect($info)->toBeArray()
        ->and($info[0])->toBe(1200)
        ->and($info[1])->toBe(630)
        ->and($info['mime'])->toBe('image/png');

    $response = $this->get('/og-default.png');
    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $defaultMeta = app(SeoConfigService::class)->defaultOgImageMetadata();
    expect($defaultMeta['url'])->toContain('/og-default.png')
        ->and($defaultMeta['type'])->toBe('image/png')
        ->and($defaultMeta['width'])->toBe(1200)
        ->and($defaultMeta['height'])->toBe(630);
});

test('guest home renders complete open graph and twitter metadata without duplicate og tags', function (): void {
    $response = $this->get('/');
    $response->assertOk();

    $html = (string) $response->getContent();
    expect($html)
        ->toContain('property="og:title"')
        ->toContain('property="og:description"')
        ->toContain('property="og:type" content="website"')
        ->toContain('property="og:site_name" content="SysNoxis"')
        ->toContain('property="og:locale" content="en_US"')
        ->toContain('property="og:locale:alternate" content="vi_VN"')
        ->toContain('property="og:image"')
        ->toContain('/og-default.png')
        ->toContain('property="og:image:type" content="image/png"')
        ->toContain('property="og:image:width" content="1200"')
        ->toContain('property="og:image:height" content="630"')
        ->toContain('property="og:image:alt"')
        ->toContain('name="twitter:card" content="summary_large_image"')
        ->toContain('name="twitter:title"')
        ->toContain('name="twitter:image"')
        ->toContain('name="twitter:image:alt"')
        ->not->toContain('name="twitter:site"')
        ->and(substr_count($html, 'property="og:title"'))->toBe(1)
        ->and(substr_count($html, 'property="og:description"'))->toBe(1)
        ->and(substr_count($html, 'property="og:image"'))->toBe(1);
});

test('all 15 localized home URLs return 200 for bots without cookies in target language', function (): void {
    $expectedOgLocale = [
        'en' => 'en_US',
        'vi' => 'vi_VN',
        'es' => 'es_ES',
        'pt-BR' => 'pt_BR',
        'id' => 'id_ID',
        'hi' => 'hi_IN',
        'th' => 'th_TH',
        'fil' => 'fil_PH',
        'fr' => 'fr_FR',
        'de' => 'de_DE',
        'ja' => 'ja_JP',
        'ko' => 'ko_KR',
        'zh-CN' => 'zh_CN',
        'ar' => 'ar_AR',
        'tr' => 'tr_TR',
    ];

    foreach (LanguageService::allCodes() as $locale) {
        $path = $locale === 'en' ? '/en' : '/'.$locale;
        $response = $this
            ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get($path);

        $response->assertOk();
        $html = (string) $response->getContent();
        $expectedDir = $locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"';

        expect($html)
            ->toContain('<html lang="'.$locale.'" '.$expectedDir)
            ->toContain('property="og:locale" content="'.$expectedOgLocale[$locale].'"');
    }

    // Explicit ?lang= query overrides Accept-Language for shared URLs
    $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
        ->get('/?lang=ja')
        ->assertOk()
        ->assertSee('<html lang="ja" dir="ltr"', false);
});

test('localized share routes work for posts, profiles, communities, premium and referral without losing locale or leaking private data', function (): void {
    $author = User::factory()->create([
        'name' => 'Kenji Sato',
        'username' => 'kenji_global',
        'email' => 'kenji-private@example.com',
        'email_verified_at' => now(),
    ]);

    $community = Community::factory()->create([
        'owner_id' => $author->id,
        'name' => 'Global Builders',
        'slug' => 'global-builders',
        'visibility' => 'public',
        'status' => 'active',
    ]);

    $publicPost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'Designing Resilient APIs',
        'content' => 'Sharing clean architecture notes for multi-region community applications with transparent error handling.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $privatePost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'SECRET_PRIVATE_TITLE_DO_NOT_LEAK',
        'content' => 'SECRET_PRIVATE_BODY_NEVER_EXPOSE_IN_OG_META',
        'visibility' => PostVisibility::Private,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $pendingPost = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'PENDING_MODERATION_TITLE_DO_NOT_LEAK',
        'content' => 'PENDING_MODERATION_BODY_NEVER_EXPOSE',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Pending,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $privateCommunity = Community::factory()->create([
        'owner_id' => $author->id,
        'name' => 'Secret Private Group',
        'slug' => 'secret-private-group',
        'description' => 'TOP_SECRET_COMMUNITY_DESCRIPTION',
        'visibility' => 'private',
        'status' => 'active',
    ]);

    // Untranslated public post shared with ?lang=vi renders honest original post title + vi OG locale
    $this->get('/posts/'.$publicPost->id.'?lang=vi')
        ->assertOk()
        ->assertSee('Designing Resilient APIs')
        ->assertSee('property="og:locale" content="vi_VN"', false)
        ->assertSee('rel="canonical"', false);

    // Localized profile, community, explore, premium routes
    $this->get('/ja/u/kenji_global')
        ->assertOk()
        ->assertSee('property="og:locale" content="ja_JP"', false)
        ->assertDontSee('kenji-private@example.com');

    $this->get('/vi/communities/global-builders')
        ->assertOk()
        ->assertSee('property="og:locale" content="vi_VN"', false);

    $this->get('/ko/communities')->assertOk();
    $this->get('/fr/search')->assertOk();
    $this->get('/zh-CN/premium')
        ->assertOk()
        ->assertSee('property="og:locale" content="zh_CN"', false);

    // Private post & private community MUST NOT leak sensitive details through SeoMetadataService
    $seoService = app(SeoMetadataService::class);
    $privateMeta = $seoService->forPost($privatePost, 'vi');
    $pendingMeta = $seoService->forPost($pendingPost, 'en');
    $privateCommunityMeta = $seoService->forCommunity($privateCommunity, 'en');

    expect($privateMeta['robots'])->toBe('noindex, nofollow')
        ->and($privateMeta['og_title'])->not->toContain('SECRET_PRIVATE_TITLE_DO_NOT_LEAK')
        ->and($privateMeta['og_description'])->not->toContain('SECRET_PRIVATE_BODY_NEVER_EXPOSE_IN_OG_META')
        ->and($pendingMeta['robots'])->toBe('noindex, nofollow')
        ->and($pendingMeta['og_title'])->not->toContain('PENDING_MODERATION_TITLE_DO_NOT_LEAK')
        ->and($privateCommunityMeta['robots'])->toBe('noindex, nofollow')
        ->and($privateCommunityMeta['og_description'])->not->toContain('TOP_SECRET_COMMUNITY_DESCRIPTION');

    // Guest visiting private post route gets 403 and no leak
    $this->get('/posts/'.$privatePost->id)
        ->assertForbidden()
        ->assertDontSee('SECRET_PRIVATE_BODY_NEVER_EXPOSE_IN_OG_META');

    // Localized referral link preserves referral attribution and locale
    $codeModel = app(ReferralService::class)->getOrCreateCode($author);
    $refResponse = $this->get('/ja/r/'.$codeModel->code);
    $refResponse->assertRedirect('/register?lang=ja')
        ->assertCookie(ReferralService::COOKIE_NAME, $codeModel->code);

    $this->get('/register?lang=ja')
        ->assertOk()
        ->assertSee('property="og:locale" content="ja_JP"', false);
});

test('xss payloads in post titles are escaped cleanly in server-side meta tags', function (): void {
    $author = User::factory()->create(['email_verified_at' => now()]);
    $post = Post::factory()->create([
        'user_id' => $author->id,
        'title' => 'Safety Check "><script>window.__xss=1</script>',
        'content' => 'Testing XSS escaping in Open Graph tags for secure link previews across social crawlers.',
        'visibility' => PostVisibility::Public,
        'moderation_status' => ModerationStatus::Approved,
        'language_code' => 'en',
        'published_at' => now(),
    ]);

    $response = $this->get('/posts/'.$post->id);
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)
        ->not->toContain('<script>window.__xss=1</script>')
        ->toContain('&quot;&gt;&lt;script&gt;');
});

test('admin seo social sharing settings and internal preview tester enforce permissions and return real og previews', function (): void {
    $member = User::factory()->create(['role' => UserRole::User, 'email_verified_at' => now()]);
    /** @var User $admin */
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('TESTSECRET123456'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($member)
        ->post('/admin/seo/social-sharing', ['home_overrides' => []])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post('/admin/seo/social-sharing', [
            'twitter_site_handle' => 'sysnoxis',
            'home_overrides' => [
                [
                    'code' => 'vi',
                    'title' => 'SysNoxis Việt Nam — Cộng đồng toàn cầu',
                    'description' => 'Mô tả chia sẻ tùy chỉnh tiếng Việt cho thẻ Open Graph.',
                ],
            ],
        ])
        ->assertRedirect();

    $homeVi = $this->get('/vi');
    $homeVi->assertOk()
        ->assertSee('SysNoxis Việt Nam — Cộng đồng toàn cầu')
        ->assertSee('name="twitter:site" content="@sysnoxis"', false);

    $privatePost = Post::factory()->create([
        'user_id' => $admin->id,
        'title' => 'PRIVATE_PREVIEW_SECRET',
        'content' => 'PRIVATE_PREVIEW_BODY',
        'visibility' => PostVisibility::Private,
        'moderation_status' => ModerationStatus::Approved,
        'published_at' => now(),
    ]);

    $previewHome = $this->actingAs($admin)
        ->postJson('/admin/seo/social-preview', [
            'page_type' => 'home',
            'locale' => 'vi',
        ]);
    $previewHome->assertOk()
        ->assertJsonPath('og_locale', 'vi_VN')
        ->assertJsonPath('og_title', 'SysNoxis Việt Nam — Cộng đồng toàn cầu')
        ->assertJsonPath('twitter_site', '@sysnoxis');

    $previewPrivate = $this->actingAs($admin)
        ->postJson('/admin/seo/social-preview', [
            'page_type' => 'post',
            'locale' => 'en',
            'entity_id' => $privatePost->id,
        ]);
    $previewPrivate->assertOk()
        ->assertJsonPath('privacy_redacted', true)
        ->assertJsonPath('robots', 'noindex, nofollow');
    expect((string) $previewPrivate->getContent())->not->toContain('PRIVATE_PREVIEW_SECRET');
});
