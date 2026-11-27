<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\SeoMetadata;
use App\Models\User;
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
    public function forHome(?string $locale = null): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SyncViet');
        $canonical = $this->config->canonicalBaseUrl().'/';
        $override = $this->findOverride(SeoEntityType::Page->value, null, $lang, '/');

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_home', '{site} — Global Community & Discussions'),
            ['site' => $siteName],
        );
        $description = $override?->description ?: $this->renderTemplate(
            $this->config->string('description_template_home', 'Join {site} to discover multilingual discussions, global communities, and real-time insights across 15 languages.'),
            ['site' => $siteName],
        );

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: ($this->config->isIndexingAllowed() ? 'index, follow, max-image-preview:large' : 'noindex, nofollow'),
            'og_type' => 'website',
            'og_image' => $override?->og_image ?: $this->config->defaultOgImageUrl(),
            'locale' => $lang,
            'hreflang' => [],
            'schema' => [
                $this->structuredData->organization(),
                $this->structuredData->webSite($lang),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPost(Post $post, ?string $locale = null, ?SeoContentTranslation $translation = null): array
    {
        $post->loadMissing(['author', 'community', 'media']);
        $lang = $locale ?: ($translation?->target_locale ?: ($post->language_code ?: $this->config->defaultLocale()));
        $siteName = (string) config('app.name', 'SyncViet');

        $eval = $translation !== null
            ? $this->qualityGate->evaluateTranslation($translation, $post)
            : $this->qualityGate->evaluatePost($post);

        $canonical = $this->canonicalUrls->postCanonicalUrl($post, $lang, $translation);
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
            : $this->config->defaultOgImageUrl());

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'article',
            'og_image' => $ogImage,
            'locale' => $lang,
            'published_time' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'modified_time' => ($translation !== null ? $translation->updated_at : ($post->updated_at ?? $post->created_at))?->toIso8601String(),
            'author_name' => $post->author?->name,
            'hreflang' => $this->hreflang->forPost($post),
            'schema' => $this->structuredData->forPost($post, $override?->canonical_url ?: $canonical, $lang, $translation),
            'eligibility' => $eval['eligibility']->value,
            'exclusion_reason' => $eval['reason'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forCommunity(Community $community, ?string $locale = null): array
    {
        $lang = $locale ?: ($community->language_code ?: $this->config->defaultLocale());
        $siteName = (string) config('app.name', 'SyncViet');
        $eval = $this->qualityGate->evaluateCommunity($community);
        $canonical = $this->canonicalUrls->communityCanonicalUrl($community);
        $override = $this->findOverride(SeoEntityType::Community->value, $community->id, $lang);

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_community', '{name} Community | {site}'),
            ['name' => $community->name, 'site' => $siteName],
        );
        $description = $override?->description ?: Str::limit(
            trim(strip_tags((string) ($community->description ?: "Explore {$community->name} discussions and members on {$siteName}."))),
            160,
        );
        $cover = $community->coverUrl() ?? $community->avatarUrl();

        return $this->assemble([
            'title' => $title,
            'description' => $description,
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'website',
            'og_image' => $override?->og_image ?: ($cover !== null ? (str_starts_with($cover, 'http') ? $cover : $this->canonicalUrls->absoluteUrl($cover)) : $this->config->defaultOgImageUrl()),
            'locale' => $lang,
            'hreflang' => $this->hreflang->forCommunity($community),
            'schema' => $this->structuredData->forCommunity($community, $canonical, $lang),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forProfile(User $user, ?string $locale = null): array
    {
        $lang = $locale ?: app()->getLocale();
        $siteName = (string) config('app.name', 'SyncViet');
        $eval = $this->qualityGate->evaluateProfile($user);
        $canonical = $this->canonicalUrls->profileCanonicalUrl($user);
        $override = $this->findOverride(SeoEntityType::Profile->value, $user->id, $lang);

        $title = $override?->title ?: $this->renderTemplate(
            $this->config->string('title_template_profile', '{name} (@{username}) | {site}'),
            ['name' => $user->name, 'username' => $user->username, 'site' => $siteName],
        );
        $bio = $user->profile !== null && is_string($user->profile->bio) && trim($user->profile->bio) !== ''
            ? trim(strip_tags($user->profile->bio))
            : "{$user->name} (@{$user->username}) on {$siteName}.";
        $avatar = $user->avatarUrl();

        return $this->assemble([
            'title' => $title,
            'description' => $override?->description ?: Str::limit($bio, 160),
            'canonical' => $override?->canonical_url ?: $canonical,
            'robots' => $override?->robots ?: $eval['robots'],
            'og_type' => 'profile',
            'og_image' => $override?->og_image ?: ($avatar !== null ? (str_starts_with($avatar, 'http') ? $avatar : $this->canonicalUrls->absoluteUrl($avatar)) : $this->config->defaultOgImageUrl()),
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
        $siteName = (string) config('app.name', 'SyncViet');
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
        $siteName = (string) config('app.name', 'SyncViet');
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

        return $this->assemble([
            'title' => $title,
            'description' => Str::limit($description, 160),
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => 'website',
            'og_image' => $this->config->defaultOgImageUrl(),
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assemble(array $data): array
    {
        $title = Str::limit(trim((string) ($data['title'] ?? config('app.name', 'SyncViet'))), 110, '');
        $description = Str::limit(trim((string) ($data['description'] ?? '')), 170, '');
        $canonical = (string) ($data['canonical'] ?? $this->config->canonicalBaseUrl().'/');
        $robots = (string) ($data['robots'] ?? 'noindex, nofollow');
        $ogImage = (string) ($data['og_image'] ?? $this->config->defaultOgImageUrl());
        $locale = (string) ($data['locale'] ?? 'en');

        return [
            'title' => $title,
            'description' => $description,
            'url' => $canonical,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => (string) ($data['og_type'] ?? 'website'),
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $ogImage,
            'og_locale' => str_replace('-', '_', $locale),
            'twitter_card' => 'summary_large_image',
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
