<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Enums\SeoIndexEligibility;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\SeoUrlRegistry;

class HreflangService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly LanguageDetectionService $languageDetection,
    ) {}

    /**
     * Build valid reciprocal hreflang tags for a Post (original + actually published translations ONLY).
     * Never generates empty locale URLs and never points x-default to homepage.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function forPost(Post $post): array
    {
        $enabled = $this->config->enabledLocales();
        $sourceLocale = in_array($post->language_code, $enabled, true)
            ? $post->language_code
            : $this->config->defaultLocale();

        $translations = SeoContentTranslation::query()
            ->where('entity_type', SeoEntityType::Post->value)
            ->where('entity_id', $post->id)
            ->publishedApproved()
            ->whereIn('target_locale', $enabled)
            ->get();

        $alternates = [];
        $sourceUrl = $this->canonicalUrls->postCanonicalUrl($post, $sourceLocale);
        $alternates[$sourceLocale] = $sourceUrl;

        foreach ($translations as $translation) {
            if ($translation->target_locale === $sourceLocale) {
                continue;
            }
            $alternates[$translation->target_locale] = $this->canonicalUrls->postCanonicalUrl(
                $post,
                $translation->target_locale,
                $translation,
            );
        }

        // Only emit hreflang links when there are multiple real published language equivalents (or self + x-default).
        $links = [];
        foreach ($alternates as $lang => $href) {
            if (! $this->languageDetection->isValidBcp47($lang)) {
                continue;
            }
            $links[] = ['hreflang' => $lang, 'href' => $href];
        }

        // x-default points to English translation if published, otherwise to the canonical original resource.
        $xDefault = $alternates['en'] ?? $sourceUrl;
        $links[] = ['hreflang' => 'x-default', 'href' => $xDefault];

        return $links;
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public function forTopic(Hashtag $hashtag, string $currentLocale): array
    {
        $enabled = $this->config->enabledLocales();
        $links = [];

        // Only link locales where this topic actually has public visible posts or is the default locale.
        $localesWithPosts = $hashtag->posts()
            ->publicVisible()
            ->select('language_code')
            ->distinct()
            ->pluck('language_code')
            ->filter(static fn (mixed $l): bool => is_string($l) && in_array($l, $enabled, true))
            ->values()
            ->all();

        $locales = array_values(array_unique(array_merge([$this->config->defaultLocale(), $currentLocale], $localesWithPosts)));

        foreach ($locales as $locale) {
            if (! in_array($locale, $enabled, true) || ! $this->languageDetection->isValidBcp47($locale)) {
                continue;
            }
            $links[] = [
                'hreflang' => $locale,
                'href' => $this->canonicalUrls->topicCanonicalUrl($hashtag, $locale),
            ];
        }

        $links[] = [
            'hreflang' => 'x-default',
            'href' => $this->canonicalUrls->topicCanonicalUrl($hashtag, $this->config->defaultLocale()),
        ];

        return $links;
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public function forCommunity(Community $community): array
    {
        $locale = $community->language_code ?: $this->config->defaultLocale();
        $canonical = $this->canonicalUrls->communityCanonicalUrl($community);

        return [
            ['hreflang' => $locale, 'href' => $canonical],
            ['hreflang' => 'x-default', 'href' => $canonical],
        ];
    }

    /**
     * Emit reciprocal hreflang tags ONLY for locales that have genuine published CMS translations.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function forPolicy(PolicyPage $policy): array
    {
        if (! $policy->isPubliclyAccessible()) {
            return [];
        }

        $enabled = $this->config->enabledLocales();
        $defaultLocale = $this->config->defaultLocale();
        $publishedLocales = array_values(array_intersect($policy->availablePublishedLocales(), $enabled));

        $defaultUrl = $this->canonicalUrls->policyCanonicalUrl($policy);
        $links = [];
        $seen = [];

        foreach ($publishedLocales as $locale) {
            if (! $this->languageDetection->isValidBcp47($locale)) {
                continue;
            }

            $href = $locale === $defaultLocale
                ? $defaultUrl
                : $this->canonicalUrls->policyCanonicalUrl($policy, $locale);

            $links[] = ['hreflang' => $locale, 'href' => $href];
            $seen[$locale] = true;
        }

        if ($links === []) {
            return [];
        }

        $links[] = ['hreflang' => 'x-default', 'href' => $defaultUrl];

        return $links;
    }

    /**
     * Validate all published multilingual translation groups for hreflang compliance.
     *
     * @return array{
     *     checked_groups: int,
     *     valid_groups: int,
     *     issues: list<array{severity: string, code: string, locale: string, url: string, message: string}>
     * }
     */
    public function validateAll(): array
    {
        $enabled = $this->config->enabledLocales();
        $translations = SeoContentTranslation::query()
            ->publishedApproved()
            ->get()
            ->groupBy(static fn (SeoContentTranslation $t): string => "{$t->entity_type}:{$t->entity_id}");

        $issues = [];
        $checked = 0;
        $valid = 0;

        foreach ($translations as $groupKey => $items) {
            $checked++;
            $groupValid = true;
            /** @var SeoContentTranslation $first */
            $first = $items->first();

            if ($first->entity_type === SeoEntityType::Post->value) {
                $post = Post::query()->find($first->entity_id);
                if ($post === null || $post->deleted_at !== null) {
                    $groupValid = false;
                    $issues[] = [
                        'severity' => 'high',
                        'code' => 'hreflang_orphan_translation',
                        'locale' => $first->target_locale,
                        'url' => '/'.$first->target_locale.'/posts/'.$first->entity_id.'-'.$first->localized_slug,
                        'message' => "Published translation {$groupKey} points to a deleted or missing source post.",
                    ];

                    continue;
                }

                $links = $this->forPost($post);
                $seenLangs = [];

                foreach ($links as $link) {
                    $lang = $link['hreflang'];
                    $href = $link['href'];

                    if ($lang !== 'x-default' && ! $this->languageDetection->isValidBcp47($lang)) {
                        $groupValid = false;
                        $issues[] = [
                            'severity' => 'high',
                            'code' => 'hreflang_invalid_code',
                            'locale' => $lang,
                            'url' => $href,
                            'message' => "Invalid BCP-47 hreflang code: {$lang}.",
                        ];
                    }

                    if (isset($seenLangs[$lang])) {
                        $groupValid = false;
                        $issues[] = [
                            'severity' => 'medium',
                            'code' => 'hreflang_duplicate_locale',
                            'locale' => $lang,
                            'url' => $href,
                            'message' => "Duplicate hreflang declaration for locale {$lang}.",
                        ];
                    }
                    $seenLangs[$lang] = $href;

                    if (! str_starts_with($href, 'https://') && ! str_starts_with($href, 'http://')) {
                        $groupValid = false;
                        $issues[] = [
                            'severity' => 'high',
                            'code' => 'hreflang_relative_url',
                            'locale' => $lang,
                            'url' => $href,
                            'message' => __('Hreflang alternate URL must be an absolute URL.'),
                        ];
                    }

                    if ($lang !== 'x-default' && ! in_array($lang, $enabled, true)) {
                        $groupValid = false;
                        $issues[] = [
                            'severity' => 'medium',
                            'code' => 'hreflang_disabled_locale',
                            'locale' => $lang,
                            'url' => $href,
                            'message' => "Hreflang references a disabled locale ({$lang}).",
                        ];
                    }

                    // Check registry status for non-indexable alternates
                    $reg = SeoUrlRegistry::query()
                        ->where('url_hash', $this->canonicalUrls->urlHash($href))
                        ->first();
                    if ($reg !== null && $reg->index_eligibility !== SeoIndexEligibility::Eligible) {
                        $groupValid = false;
                        $issues[] = [
                            'severity' => 'high',
                            'code' => 'hreflang_points_to_noindex',
                            'locale' => $lang,
                            'url' => $href,
                            'message' => "Hreflang target is marked {$reg->index_eligibility->value} ({$reg->exclusion_reason}).",
                        ];
                    }
                }
            }

            if ($groupValid) {
                $valid++;
            }
        }

        return [
            'checked_groups' => $checked,
            'valid_groups' => $valid,
            'issues' => $issues,
        ];
    }
}
