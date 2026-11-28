<?php

namespace App\Services\Seo;

use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use Illuminate\Support\Str;

class StructuredDataService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $siteName = (string) config('app.name', 'SysNoxis');
        $baseUrl = $this->config->canonicalBaseUrl();

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $baseUrl.'/#organization',
            'name' => $siteName,
            'url' => $baseUrl.'/',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $this->config->defaultOgImageUrl(),
                'width' => 1200,
                'height' => 630,
            ],
        ];

        $sameAs = $this->config->jsonStringList('organization_same_as');
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function webSite(string $locale): array
    {
        $siteName = (string) config('app.name', 'SysNoxis');
        $baseUrl = $this->config->canonicalBaseUrl();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $baseUrl.'/#website',
            'name' => $siteName,
            'url' => $baseUrl.'/',
            'inLanguage' => $locale,
            'publisher' => ['@id' => $baseUrl.'/#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $baseUrl.'/search?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $items): array
    {
        $list = [];
        foreach ($items as $index => $item) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /**
     * Strictly factual DiscussionForumPosting / SocialMediaPosting schema (zero private fields, zero fake ratings).
     *
     * @return list<array<string, mixed>>
     */
    public function forPost(Post $post, string $canonicalUrl, string $locale, ?SeoContentTranslation $translation = null): array
    {
        $post->loadMissing(['author', 'community', 'media', 'comments.author']);

        $headline = $translation !== null && is_string($translation->translated_title) && trim($translation->translated_title) !== ''
            ? trim($translation->translated_title)
            : (is_string($post->title) && trim($post->title) !== ''
                ? trim($post->title)
                : Str::limit(trim(strip_tags($translation !== null ? $translation->translated_body : $post->content)), 110));

        $body = trim(strip_tags($translation !== null ? $translation->translated_body : $post->content));
        $authorName = $post->author !== null ? $post->author->name : (string) config('app.name', 'SysNoxis');
        $authorUrl = $post->author !== null ? $this->canonicalUrls->profileCanonicalUrl($post->author) : $this->config->canonicalBaseUrl().'/';

        $isLongForm = mb_strlen($body) >= 450 && is_string($post->title) && trim($post->title) !== '';
        $schemaType = $isLongForm ? 'Article' : 'DiscussionForumPosting';

        $article = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            '@id' => $canonicalUrl.'#post',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonicalUrl,
            ],
            'url' => $canonicalUrl,
            'headline' => Str::limit($headline, 110, ''),
            'text' => Str::limit($body, 2500),
            'inLanguage' => $locale,
            'datePublished' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'dateModified' => ($translation !== null ? $translation->updated_at : ($post->updated_at ?? $post->created_at))?->toIso8601String(),
            'author' => [
                '@type' => 'Person',
                'name' => $authorName,
                'url' => $authorUrl,
            ],
            'publisher' => $this->organization(),
            'interactionStatistic' => [
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/LikeAction',
                    'userInteractionCount' => max(0, $post->reactions_count),
                ],
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CommentAction',
                    'userInteractionCount' => max(0, $post->comments_count),
                ],
            ],
        ];

        $images = [];
        foreach ($post->media as $media) {
            $url = $media->url();
            if ($url !== '') {
                $images[] = [
                    '@type' => 'ImageObject',
                    'url' => str_starts_with($url, 'http') ? $url : $this->canonicalUrls->absoluteUrl($url),
                    'width' => $media->width,
                    'height' => $media->height,
                ];
            }
        }
        if ($images !== []) {
            $article['image'] = $images;
        }

        // Include up to 5 public approved comments only
        $publicComments = $post->comments
            ->filter(static fn ($c): bool => $c->deleted_at === null && $c->author !== null && $c->author->isActive())
            ->take(5);

        if ($publicComments->isNotEmpty()) {
            $article['comment'] = $publicComments->map(function ($comment): array {
                return [
                    '@type' => 'Comment',
                    'text' => Str::limit(trim(strip_tags((string) $comment->content)), 500),
                    'dateCreated' => $comment->created_at?->toIso8601String(),
                    'author' => [
                        '@type' => 'Person',
                        'name' => $comment->author->name,
                        'url' => $this->canonicalUrls->profileCanonicalUrl($comment->author),
                    ],
                ];
            })->values()->all();
        }

        $crumbs = [
            ['name' => (string) config('app.name', 'SysNoxis'), 'url' => $this->config->canonicalBaseUrl().'/'],
        ];
        if ($post->community !== null && $post->community->visibility === 'public') {
            $crumbs[] = [
                'name' => $post->community->name,
                'url' => $this->canonicalUrls->communityCanonicalUrl($post->community),
            ];
        }
        $crumbs[] = ['name' => Str::limit($headline, 60), 'url' => $canonicalUrl];

        return [$article, $this->breadcrumbs($crumbs)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCommunity(Community $community, string $canonicalUrl, string $locale): array
    {
        $page = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $community->name,
            'description' => Str::limit(trim(strip_tags((string) ($community->description ?? ''))), 300),
            'url' => $canonicalUrl,
            'inLanguage' => $locale,
            'isPartOf' => ['@id' => $this->config->canonicalBaseUrl().'/#website'],
        ];

        $crumbs = [
            ['name' => (string) config('app.name', 'SysNoxis'), 'url' => $this->config->canonicalBaseUrl().'/'],
            ['name' => 'Communities', 'url' => $this->canonicalUrls->absoluteUrl('/communities')],
            ['name' => $community->name, 'url' => $canonicalUrl],
        ];

        return [$page, $this->breadcrumbs($crumbs)];
    }

    /**
     * Strictly excludes email, phone, and private profile details.
     *
     * @return list<array<string, mixed>>
     */
    public function forProfile(User $user, string $canonicalUrl, string $locale): array
    {
        $user->loadMissing('profile');

        $person = [
            '@type' => 'Person',
            'name' => $user->name,
            'alternateName' => '@'.$user->username,
            'url' => $canonicalUrl,
        ];

        if ($user->profile !== null && is_string($user->profile->bio) && trim($user->profile->bio) !== '') {
            $person['description'] = Str::limit(trim(strip_tags($user->profile->bio)), 250);
        }

        $avatar = $user->avatarUrl();
        if (is_string($avatar) && $avatar !== '') {
            $person['image'] = str_starts_with($avatar, 'http') ? $avatar : $this->canonicalUrls->absoluteUrl($avatar);
        }

        $profilePage = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'url' => $canonicalUrl,
            'inLanguage' => $locale,
            'dateCreated' => $user->created_at?->toIso8601String(),
            'mainEntity' => $person,
        ];

        $crumbs = [
            ['name' => (string) config('app.name', 'SysNoxis'), 'url' => $this->config->canonicalBaseUrl().'/'],
            ['name' => $user->name, 'url' => $canonicalUrl],
        ];

        return [$profilePage, $this->breadcrumbs($crumbs)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTopic(Hashtag $hashtag, string $canonicalUrl, string $locale): array
    {
        $page = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => '#'.$hashtag->name,
            'url' => $canonicalUrl,
            'inLanguage' => $locale,
            'isPartOf' => ['@id' => $this->config->canonicalBaseUrl().'/#website'],
        ];

        $crumbs = [
            ['name' => (string) config('app.name', 'SysNoxis'), 'url' => $this->config->canonicalBaseUrl().'/'],
            ['name' => 'Topics', 'url' => $this->canonicalUrls->absoluteUrl('/topics')],
            ['name' => '#'.$hashtag->name, 'url' => $canonicalUrl],
        ];

        return [$page, $this->breadcrumbs($crumbs)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPolicy(PolicyPage $policy, string $canonicalUrl, string $locale): array
    {
        $title = $policy->titleForLocale($locale);

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'url' => $canonicalUrl,
                'inLanguage' => $locale,
                'dateModified' => $policy->updated_at?->toIso8601String(),
                'isPartOf' => ['@id' => $this->config->canonicalBaseUrl().'/#website'],
            ],
            $this->breadcrumbs([
                ['name' => (string) config('app.name', 'SysNoxis'), 'url' => $this->config->canonicalBaseUrl().'/'],
                ['name' => $title, 'url' => $canonicalUrl],
            ]),
        ];
    }
}
