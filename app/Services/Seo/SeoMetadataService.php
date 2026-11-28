<?php

namespace App\Services\Seo;

use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Enums\SeoEntityType;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\SeoMetadata;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SeoMetadataService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly SeoQualityGateService $qualityGate,
        private readonly HreflangService $hreflang,
        private readonly StructuredDataService $structuredData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forHome(?string $locale = null, ?string $requestPath = null): array
    {
        $lang = $locale ?: app()->getLocale();
        $base = $this->config->canonicalBaseUrl();
        $normalizedPath = $this->canonicalUrls->normalizePath($requestPath ?? '/');
        $isLocalizedSubpath = $normalizedPath === '/'.$lang && $lang !== $this->config->defaultLocale();

        $canonical = $isLocalizedSubpath ? $base.'/'.$lang : $base.'/';
        $ogUrl = $isLocalizedSubpath ? $canonical : ($lang !== $this->config->defaultLocale() && $normalizedPath === '/' ? $base.'/'.$lang : $base.'/');
        $cacheKey = 'sysnoxis.seo.home.v2.'.$lang.'.'.md5($canonical.'|'.$ogUrl).'.'.$this->config->cacheVersionToken();

        /** @var array<string, mixed> */
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($lang, $canonical, $ogUrl): array {
            $override = $this->findOverride(SeoEntityType::Page->value, null, $lang, parse_url($canonical, PHP_URL_PATH) ?: '/');
            $homeCopy = $this->config->homeSeoCopy($lang);

            $title = $override?->title ?: $homeCopy['title'];
            $description = $override?->description ?: $homeCopy['description'];
            $defaultOg = $this->config->defaultOgImageMetadata();

            $hreflang = [];
            foreach ($this->config->enabledLocales() as $code) {
                $hreflang[] = [
                    'hreflang' => $code,
                    'href' => $code === $this->config->defaultLocale() ? $this->config->canonicalBaseUrl().'/' : $this->config->canonicalBaseUrl().'/'.$code,
                ];
            }
            $hreflang[] = ['hreflang' => 'x-default', 'href' => $this->config->canonicalBaseUrl().'/'];

            return $this->assemble([
                'title' => $title,
                'description' => $description,
                'url' => $override?->canonical_url ?: $ogUrl,
                'canonical' => $override?->canonical_url ?: $canonical,
                'robots' => $override?->robots ?: ($this->config->isIndexingAllowed() ? 'index, follow, max-image-preview:large' : 'noindex, nofollow'),
                'og_type' => 'website',
                'og_image' => $override?->og_image ?: $defaultOg['url'],
                'og_image_type' => $override?->og_image ? null : $defaultOg['type'],
                'og_image_width' => $override?->og_image ? null : $defaultOg['width'],
                'og_image_height' => $override?->og_image ? null : $defaultOg['height'],
                'locale' => $lang,
                'hreflang' => $hreflang,
                'schema' => [
                    $this->structuredData->organization(),
                    $this->structuredData->webSite($lang),
                ],
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function forPost(
        Post $post,
        ?string $locale = null,
        ?SeoContentTranslation $translation = null,
        bool $untranslatedShareLocale = false,
    ): array {
        $post->loadMissing(['author', 'community', 'media']);
        $postLanguage = $post->language_code ?: $this->config->defaultLocale();
        $lang = $locale ?: ($translation?->target_locale ?: $postLanguage);
        $siteName = (string) config('app.name', 'SysNoxis');
        $defaultOg = $this->config->defaultOgImageMetadata();

        $isPubliclyShareable = $post->deleted_at === null
            && $post->visibility === PostVisibility::Public
            && $post->moderation_status === ModerationStatus::Approved
            && ! $post->is_spam
            && $post->published_at !== null
            && $post->author !== null
            && $post->author->isActive()
            && ($post->community === null || ($post->community->visibility === 'public' && $post->community->status === 'active'));

        // Strictly prevent exposing private, pending, hidden, spam, deleted, or closed-community content to OG tags.
        if (! $isPubliclyShareable) {
            return $this->assemble([
                'title' => __('Content unavailable | :site', ['site' => $siteName], $lang),
                'description' => __('Sign in or visit :site to explore public discussions and community conversations.', ['site' => $siteName], $lang),
                'url' => $this->config->canonicalBaseUrl().'/',
                'canonical' => $this->config->canonicalBaseUrl().'/',
                'robots' => 'noindex, nofollow',
                'og_type' => 'website',
                'og_image' => $defaultOg['url'],
                'og_image_type' => $defaultOg['type'],
                'og_image_width' => $defaultOg['width'],
                'og_image_height' => $defaultOg['height'],
                'locale' => $lang,
                'hreflang' => [],
                'schema' => [],
                'eligibility' => 'excluded',
                'exclusion_reason' => 'private_or_restricted_post',
            ]);
        }

        $eval = $translation !== null
            ? $this->qualityGate->evaluateTranslation($translation, $post)
            : $this->qualityGate->evaluatePost($post);

        $canonicalLocale = $translation !== null ? $translation->target_locale : $postLanguage;
        $canonical = $this->canonicalUrls->postCanonicalUrl($post, $canonicalLocale, $translation);
        $ogUrl = ($untranslatedShareLocale && $lang !== $canonicalLocale)
            ? $this->config->canonicalBaseUrl().'/posts/'.$post->id.'?lang='.rawurlencode($lang)
            : $canonical;

        $override = $this->findOverride(SeoEntityType::Post->value, $post->id, $lang);

        $rawTitle = $translation !== null && is_string($translation->translated_title) && trim($translation->translated_title) !== ''
            ? trim($translation->translated_title)
            : (is_string($post->title) && trim($post->title) !== ''
                ? trim($post->title)
                : Str::limit(trim(strip_tags($translation !== null ? $translation->translated_body : $post->content)), 70));

        $rawBody = trim(strip_tags($translation !== null ? $translation->translated_body : $post->content));
        $rawDescription = $translation !== null && is_string($translation->translated_description) && trim($translation->translated_description) !== ''
            ? trim($translation->translated_description)
            : Str::limit(preg_replace('/\s+/u', ' ', $rawBody) ?? $rawBody, 160);

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_post', '{title} | {site}'),
            ['title' => $rawTitle, 'site' => $siteName],
        );
        $description = $override?->description ?: $rawDescription;

        $firstMedia = $post->media->first();
        $mediaUrl = $firstMedia !== null ? $firstMedia->url() : '';
        $ogImage = $override?->og_image ?: ($mediaUrl !== ''
            ? (str_starts_with($mediaUrl, 'http') ? $mediaUrl : $this->canonicalUrls->absoluteUrl($mediaUrl))
            : $defaultOg['url']);

        $useDefaultMeta = $override?->og_image === null && $mediaUrl === '';

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'url' => $override?->canonical_url ?: $ogUrl,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: ($untranslatedShareLocale ? 'noindex, follow' : $eval['robots']),
            'og_type' => 'article',
            'og_image' => $ogImage,
            'og_image_type' => $useDefaultMeta ? $defaultOg['type'] : ($firstMedia?->mime_type ?: null),
            'og_image_width' => $useDefaultMeta ? $defaultOg['width'] : ($firstMedia?->width ?: null),
            'og_image_height' => $useDefaultMeta ? $defaultOg['height'] : ($firstMedia?->height ?: null),
            'og_image_alt' => $title,
            'locale' => $lang,
            'published_time' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'modified_time' => ($translation !== null ? $translation->updated_at : ($post->updated_at ?? $post->created_at))?->toIso8601String(),
            'author_name' => $post->author->name,
            'hreflang' => $this->hreflang->forPost($post),
            'schema' => $this->structuredData->forPost($post, $override?->canonical_url ?: $canonical, $canonicalLocale, $translation),
            'eligibility' => $eval['eligibility']->value,
            'exclusion_reason' => $eval['reason'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forCommunity(Community $community, ?string $locale = null, bool $isLocalizedRoute = false): array
    {
        $lang = $locale ?: ($community->language_code ?: $this->config->defaultLocale());
        $siteName = (string) config('app.name', 'SysNoxis');
        $defaultOg = $this->config->defaultOgImageMetadata();

        if ($community->visibility !== 'public' || $community->status !== 'active') {
            return $this->assemble([
                'title' => __('Private Community | :site', ['site' => $siteName], $lang),
                'description' => __('Sign in or visit :site to explore public discussions and community conversations.', ['site' => $siteName], $lang),
                'url' => $this->config->canonicalBaseUrl().'/communities',
                'canonical' => $this->config->canonicalBaseUrl().'/communities',
                'robots' => 'noindex, nofollow',
                'og_type' => 'website',
                'og_image' => $defaultOg['url'],
                'og_image_type' => $defaultOg['type'],
                'og_image_width' => $defaultOg['width'],
                'og_image_height' => $defaultOg['height'],
                'locale' => $lang,
                'hreflang' => [],
                'schema' => [],
            ]);
        }

        $eval = $this->qualityGate->evaluateCommunity($community);
        $canonical = $this->canonicalUrls->communityCanonicalUrl($community);
        $ogUrl = $isLocalizedRoute && $lang !== $this->config->defaultLocale()
            ? $this->canonicalUrls->communityCanonicalUrl($community, $lang)
            : $canonical;
        $override = $this->findOverride(SeoEntityType::Community->value, $community->id, $lang);

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_community', '{name} Community | {site}'),
            ['name' => $community->name, 'site' => $siteName],
        );
        $fallbackDesc = __('Explore :name discussions and members on :site.', ['name' => $community->name, 'site' => $siteName], $lang);
        $description = $override?->description ?: Str::limit(
            trim(strip_tags((string) ($community->description ?: $fallbackDesc))),
            160,
        );
        $cover = $community->coverUrl() ?? $community->avatarUrl();
        $ogImage = $override?->og_image ?: ($cover !== null ? (str_starts_with($cover, 'http') ? $cover : $this->canonicalUrls->absoluteUrl($cover)) : $defaultOg['url']);
        $useDefaultMeta = $override?->og_image === null && $cover === null;

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'url' => $override?->canonical_url ?: $ogUrl,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'website',
            'og_image' => $ogImage,
            'og_image_type' => $useDefaultMeta ? $defaultOg['type'] : null,
            'og_image_width' => $useDefaultMeta ? $defaultOg['width'] : null,
            'og_image_height' => $useDefaultMeta ? $defaultOg['height'] : null,
            'og_image_alt' => $title,
            'locale' => $lang,
            'hreflang' => $this->hreflang->forCommunity($community),
            'schema' => $this->structuredData->forCommunity($community, $canonical, $lang),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forProfile(User $user, ?string $locale = null, bool $isLocalizedRoute = false): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SysNoxis');
        $defaultOg = $this->config->defaultOgImageMetadata();

        if (! $user->isActive() || ! $user->hasVerifiedEmail()) {
            return $this->assemble([
                'title' => $siteName,
                'description' => __('Sign in or visit :site to explore public discussions and community conversations.', ['site' => $siteName], $lang),
                'url' => $this->config->canonicalBaseUrl().'/',
                'canonical' => $this->config->canonicalBaseUrl().'/',
                'robots' => 'noindex, nofollow',
                'og_type' => 'website',
                'og_image' => $defaultOg['url'],
                'og_image_type' => $defaultOg['type'],
                'og_image_width' => $defaultOg['width'],
                'og_image_height' => $defaultOg['height'],
                'locale' => $lang,
                'hreflang' => [],
                'schema' => [],
            ]);
        }

        $eval = $this->qualityGate->evaluateProfile($user);
        $canonical = $this->canonicalUrls->profileCanonicalUrl($user);
        $ogUrl = $isLocalizedRoute && $lang !== $this->config->defaultLocale()
            ? $this->config->canonicalBaseUrl().'/'.$lang.'/u/'.rawurlencode($user->username)
            : $canonical;
        $override = $this->findOverride(SeoEntityType::Profile->value, $user->id, $lang);

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_profile', '{name} (@{username}) | {site}'),
            ['name' => $user->name, 'username' => $user->username, 'site' => $siteName],
        );
        $fallbackBio = __(':name (:handle) on :site.', ['name' => $user->name, 'handle' => '@'.$user->username, 'site' => $siteName], $lang);
        $bio = $user->profile !== null && is_string($user->profile->bio) && trim($user->profile->bio) !== ''
            ? trim(strip_tags($user->profile->bio))
            : $fallbackBio;
        $avatar = $user->avatarUrl();
        $ogImage = $override?->og_image ?: ($avatar !== null ? (str_starts_with($avatar, 'http') ? $avatar : $this->canonicalUrls->absoluteUrl($avatar)) : $defaultOg['url']);
        $useDefaultMeta = $override?->og_image === null && $avatar === null;

        return $this->assemble([
            'title' => $title,
            'description' => $override?->description ?: Str::limit($bio, 160),
            'url' => $override?->canonical_url ?: $ogUrl,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'profile',
            'og_image' => $ogImage,
            'og_image_type' => $useDefaultMeta ? $defaultOg['type'] : null,
            'og_image_width' => $useDefaultMeta ? $defaultOg['width'] : null,
            'og_image_height' => $useDefaultMeta ? $defaultOg['height'] : null,
            'og_image_alt' => $title,
            'locale' => $lang,
            'hreflang' => [],
            'schema' => $this->structuredData->forProfile($user, $canonical, $lang),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forTopic(Hashtag $hashtag, ?string $locale = null, int $page = 1): array
    {
        $lang = $locale ?: $this->config->defaultLocale();
        $siteName = (string) config('app.name', 'SysNoxis');
        $eval = $this->qualityGate->evaluateTopic($hashtag);
        $canonical = $this->canonicalUrls->topicCanonicalUrl($hashtag, $lang, $page);
        $override = $this->findOverride(SeoEntityType::Topic->value, $hashtag->id, $lang);

        $pageSuffix = $page > 1 ? " — Page {$page}" : '';
        $title = ($override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_topic', '#{topic} Discussions & Insights | {site}'),
            ['topic' => $hashtag->name, 'site' => $siteName],
        )).$pageSuffix;

        $description = $override?->description ?: Str::limit(
            "Browse public discussions, tutorials, and community posts tagged #{$hashtag->name} on {$siteName}.",
            160,
        );

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'website',
            'og_image' => $override?->og_image ?: $this->config->defaultOgImageUrl(),
            'locale' => $lang,
            'hreflang' => $this->hreflang->forTopic($hashtag, $lang),
            'schema' => $this->structuredData->forTopic($hashtag, $canonical, $lang),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPolicy(PolicyPage $policy, ?string $locale = null, bool $isLocalizedRoute = false): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SysNoxis');
        $eval = $this->qualityGate->evaluatePolicy($policy);

        $defaultCanonical = $this->canonicalUrls->policyCanonicalUrl(
            $policy,
            $isLocalizedRoute ? $lang : null,
        );
        $customCanonical = is_string($policy->canonical_url) && trim($policy->canonical_url) !== '' && ! $isLocalizedRoute
            ? ($this->canonicalUrls->isSafeRedirectTarget($policy->canonical_url)
                ? (str_starts_with($policy->canonical_url, '/') ? $this->canonicalUrls->absoluteUrl($policy->canonical_url) : $policy->canonical_url)
                : $defaultCanonical)
            : $defaultCanonical;

        $title = $policy->titleForLocale($lang);
        $customSeoTitle = $policy->seoTitleForLocale($lang);
        $resolvedTitle = $customSeoTitle !== null ? $customSeoTitle : "{$title} | {$siteName}";

        $summary = $policy->metaDescriptionForLocale($lang)
            ?: $policy->summaryForLocale($lang)
            ?: $policy->contentForLocale($lang)
            ?: $title;

        $ogImage = $policy->ogImageUrl();
        $resolvedOgImage = $ogImage !== null
            ? (str_starts_with($ogImage, 'http') ? $ogImage : $this->canonicalUrls->absoluteUrl($ogImage))
            : $this->config->defaultOgImageUrl();

        return $this->assemble([
            'title' => $resolvedTitle,
            'description' => Str::limit(trim(strip_tags($summary)), 160),
            'canonical' => $customCanonical,
            'robots' => $eval['robots'],
            'og_type' => 'website',
            'og_image' => $resolvedOgImage,
            'locale' => $lang,
            'modified_time' => $policy->updated_at?->toIso8601String(),
            'hreflang' => $this->hreflang->forPolicy($policy),
            'schema' => $this->structuredData->forPolicy($policy, $customCanonical, $lang),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forStaticPage(string $path, string $title, string $description, bool $indexable = true, ?string $locale = null): array
    {
        $lang = $locale ?: app()->getLocale();
        $canonical = $this->canonicalUrls->absoluteUrl($path);
        $robots = ($indexable && $this->config->isIndexingAllowed())
            ? 'index, follow, max-image-preview:large'
            : 'noindex, nofollow';
        $defaultOg = $this->config->defaultOgImageMetadata();

        return $this->assemble([
            'title' => $title,
            'description' => Str::limit($description, 160),
            'url' => $canonical,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => 'website',
            'og_image' => $defaultOg['url'],
            'og_image_type' => $defaultOg['type'],
            'og_image_width' => $defaultOg['width'],
            'og_image_height' => $defaultOg['height'],
            'locale' => $lang,
            'hreflang' => [],
            'schema' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'name' => $title,
                    'description' => Str::limit($description, 160),
                    'url' => $canonical,
                    'inLanguage' => $lang,
                ],
            ],
        ]);
    }

    /**
     * Localized Open Graph + SEO metadata for SysNoxis Premium across all 15 locales.
     *
     * @return array<string, mixed>
     */
    public function forPremium(?string $locale = null, bool $isLocalizedRoute = false): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SysNoxis');
        $canonical = $this->canonicalUrls->absoluteUrl('/premium');
        $ogUrl = $isLocalizedRoute && $lang !== $this->config->defaultLocale()
            ? $this->canonicalUrls->absoluteUrl('/'.$lang.'/premium')
            : $canonical;
        $defaultOg = $this->config->defaultOgImageMetadata();

        $title = __('SysNoxis Premium | :site', ['site' => $siteName], $lang);
        $description = __('Upgrade to :site Premium for a blue member badge, reduced ads, and creator analytics.', ['site' => $siteName], $lang);

        $hreflang = [];
        foreach ($this->config->enabledLocales() as $code) {
            $hreflang[] = [
                'hreflang' => $code,
                'href' => $code === $this->config->defaultLocale()
                    ? $canonical
                    : $this->canonicalUrls->absoluteUrl('/'.$code.'/premium'),
            ];
        }
        $hreflang[] = ['hreflang' => 'x-default', 'href' => $canonical];

        return $this->assemble([
            'title' => $title,
            'description' => Str::limit($description, 160),
            'url' => $ogUrl,
            'canonical' => $canonical,
            'robots' => $this->config->isIndexingAllowed() ? 'index, follow, max-image-preview:large' : 'noindex, nofollow',
            'og_type' => 'website',
            'og_image' => $defaultOg['url'],
            'og_image_type' => $defaultOg['type'],
            'og_image_width' => $defaultOg['width'],
            'og_image_height' => $defaultOg['height'],
            'locale' => $lang,
            'hreflang' => $hreflang,
            'schema' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $siteName.' Premium',
                    'description' => $description,
                    'brand' => ['@type' => 'Brand', 'name' => $siteName],
                ],
            ],
        ]);
    }

    /**
     * Localized Open Graph metadata for Referral / Registration invitation links.
     *
     * @return array<string, mixed>
     */
    public function forRegisterLanding(?string $locale = null): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SysNoxis');
        $defaultOg = $this->config->defaultOgImageMetadata();
        $url = $lang !== $this->config->defaultLocale()
            ? $this->canonicalUrls->absoluteUrl('/register').'?lang='.rawurlencode($lang)
            : $this->canonicalUrls->absoluteUrl('/register');

        return $this->assemble([
            'title' => __('Join :site', ['site' => $siteName], $lang),
            'description' => __('Create your free account on :site to connect, share ideas, and join global communities.', ['site' => $siteName], $lang),
            'url' => $url,
            'canonical' => $this->canonicalUrls->absoluteUrl('/register'),
            'robots' => 'noindex, nofollow',
            'og_type' => 'website',
            'og_image' => $defaultOg['url'],
            'og_image_type' => $defaultOg['type'],
            'og_image_width' => $defaultOg['width'],
            'og_image_height' => $defaultOg['height'],
            'locale' => $lang,
            'hreflang' => [],
            'schema' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assemble(array $data): array
    {
        $siteName = (string) config('app.name', 'SysNoxis');
        $title = Str::limit(trim((string) ($data['title'] ?? $siteName)), 110, '');
        $description = Str::limit(trim((string) ($data['description'] ?? '')), 170, '');
        $canonical = (string) ($data['canonical'] ?? $this->config->canonicalBaseUrl().'/');
        $url = (string) ($data['url'] ?? $canonical);
        $robots = (string) ($data['robots'] ?? 'noindex, nofollow');
        $defaultOg = $this->config->defaultOgImageMetadata();
        $ogImage = trim((string) ($data['og_image'] ?? $defaultOg['url']));
        if ($ogImage === '') {
            $ogImage = $defaultOg['url'];
        }
        $locale = (string) ($data['locale'] ?? 'en');
        $ogLocale = $this->config->ogLocaleCode($locale);
        $ogImageSecure = str_starts_with($ogImage, 'https://') ? $ogImage : null;
        $ogImageAlt = Str::limit(trim((string) ($data['og_image_alt'] ?? $title ?: $siteName)), 140, '');

        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => (string) ($data['og_type'] ?? 'website'),
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $ogImage,
            'og_image_secure_url' => $ogImageSecure,
            'og_image_type' => $data['og_image_type'] ?? null,
            'og_image_width' => $data['og_image_width'] ?? null,
            'og_image_height' => $data['og_image_height'] ?? null,
            'og_image_alt' => $ogImageAlt,
            'og_locale' => $ogLocale,
            'og_locale_alternates' => $this->config->ogLocaleAlternates($locale),
            'twitter_card' => 'summary_large_image',
            'twitter_site' => $this->config->twitterSiteHandle(),
            'twitter_image_alt' => $ogImageAlt,
            'published_time' => $data['published_time'] ?? null,
            'modified_time' => $data['modified_time'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'hreflang' => $data['hreflang'] ?? [],
            'schema' => $data['schema'] ?? [],
            'eligibility' => $data['eligibility'] ?? null,
            'exclusion_reason' => $data['exclusion_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function renderTemplate(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{'.$k.'}', $v, $out);
        }

        return trim($out);
    }

    private function findOverride(string $entityType, ?int $entityId, string $locale, ?string $path = null): ?SeoMetadata
    {
        if ($path !== null) {
            $byPath = SeoMetadata::query()
                ->where('path_hash', $this->canonicalUrls->pathHash($path))
                ->where('locale', $locale)
                ->first();
            if ($byPath !== null) {
                return $byPath;
            }
        }

        if ($entityId === null) {
            return null;
        }

        return SeoMetadata::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('locale', $locale)
            ->first();
    }
}
