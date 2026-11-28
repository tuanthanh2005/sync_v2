<?php

namespace App\Services\Seo;

use App\Models\Community;
use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Support\Str;

class InternalLinkingService
{
    public function __construct(
        private readonly CanonicalUrlService $canonicalUrls,
    ) {}

    /**
     * Build crawlable internal links for a public Post without N+1 queries.
     *
     * @return array{
     *     related_posts: list<array{id: int, title: string, excerpt: string, url: string, path: string, author_name: string, created_at: string|null}>,
     *     more_from_author: list<array{id: int, title: string, excerpt: string, url: string, path: string, created_at: string|null}>,
     *     related_community: array{name: string, slug: string, path: string, url: string, members_count: int}|null,
     *     related_topics: list<array{name: string, slug: string, path: string, url: string, posts_count: int}>
     * }
     */
    public function forPost(Post $post, string $locale): array
    {
        $post->loadMissing(['community', 'hashtags']);

        $hashtagIds = $post->hashtags->pluck('id')->all();

        $relatedQuery = Post::query()
            ->publicVisible()
            ->where('is_spam', false)
            ->where('seo_noindex', false)
            ->where('id', '!=', $post->id)
            ->with(['author:id,name,username'])
            ->where(function ($q) use ($post, $hashtagIds): void {
                if ($post->community_id !== null) {
                    $q->where('community_id', $post->community_id);
                }
                if ($hashtagIds !== []) {
                    $q->orWhereHas('hashtags', static fn ($hq) => $hq->whereIn('hashtags.id', $hashtagIds));
                }
                $q->orWhere('category', $post->category->value);
            })
            ->orderByRaw('CASE WHEN language_code = ? THEN 0 ELSE 1 END', [$locale])
            ->orderByDesc('reactions_count')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        $moreFromAuthor = Post::query()
            ->publicVisible()
            ->where('is_spam', false)
            ->where('seo_noindex', false)
            ->where('user_id', $post->user_id)
            ->where('id', '!=', $post->id)
            ->orderByDesc('published_at')
            ->limit(4)
            ->get();

        $relatedCommunity = null;
        if ($post->community !== null && $post->community->visibility === 'public' && $post->community->status === 'active') {
            $commPath = $this->canonicalUrls->communityPath($post->community);
            $relatedCommunity = [
                'name' => $post->community->name,
                'slug' => $post->community->slug,
                'path' => $commPath,
                'url' => $this->canonicalUrls->absoluteUrl($commPath),
                'members_count' => $post->community->members_count,
            ];
        }

        $relatedTopics = array_values(
            $post->hashtags
                ->filter(static fn (Hashtag $tag): bool => ! $tag->is_restricted && ! $tag->is_spam)
                ->take(8)
                ->map(function (Hashtag $tag) use ($locale): array {
                    $path = $this->canonicalUrls->topicPath($tag, $locale);

                    return [
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'path' => $path,
                        'url' => $this->canonicalUrls->absoluteUrl($path),
                        'posts_count' => $tag->posts_count,
                    ];
                })
                ->all(),
        );

        return [
            'related_posts' => array_values($relatedQuery->map(function (Post $item): array {
                $path = $this->canonicalUrls->postPath($item, $item->language_code ?: 'en');

                return [
                    'id' => $item->id,
                    'title' => $item->title ?: Str::limit(trim(strip_tags($item->content)), 70),
                    'excerpt' => Str::limit(trim(strip_tags($item->content)), 120),
                    'path' => $path,
                    'url' => $this->canonicalUrls->absoluteUrl($path),
                    'author_name' => $item->author !== null ? $item->author->name : 'SysNoxis Member',
                    'created_at' => $item->published_at?->toIso8601String() ?? $item->created_at?->toIso8601String(),
                ];
            })->all()),
            'more_from_author' => array_values($moreFromAuthor->map(function (Post $item): array {
                $path = $this->canonicalUrls->postPath($item, $item->language_code ?: 'en');

                return [
                    'id' => $item->id,
                    'title' => $item->title ?: Str::limit(trim(strip_tags($item->content)), 70),
                    'excerpt' => Str::limit(trim(strip_tags($item->content)), 110),
                    'path' => $path,
                    'url' => $this->canonicalUrls->absoluteUrl($path),
                    'created_at' => $item->published_at?->toIso8601String() ?? $item->created_at?->toIso8601String(),
                ];
            })->all()),
            'related_community' => $relatedCommunity,
            'related_topics' => $relatedTopics,
        ];
    }

    /**
     * @return array{
     *     related_topics: list<array{name: string, slug: string, path: string, posts_count: int}>,
     *     related_communities: list<array{name: string, slug: string, path: string, members_count: int}>
     * }
     */
    public function forTopic(Hashtag $hashtag, string $locale): array
    {
        $relatedTopics = array_values(
            Hashtag::query()
                ->where('id', '!=', $hashtag->id)
                ->where('is_restricted', false)
                ->where('is_spam', false)
                ->where('posts_count', '>', 0)
                ->orderByDesc('posts_count')
                ->limit(10)
                ->get()
                ->map(function (Hashtag $tag) use ($locale): array {
                    return [
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'path' => $this->canonicalUrls->topicPath($tag, $locale),
                        'posts_count' => $tag->posts_count,
                    ];
                })
                ->all(),
        );

        $communityIds = $hashtag->posts()
            ->publicVisible()
            ->whereNotNull('community_id')
            ->distinct()
            ->limit(6)
            ->pluck('community_id')
            ->all();

        $relatedCommunities = array_values(
            Community::query()
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->when($communityIds !== [], static fn ($q) => $q->whereIn('id', $communityIds))
                ->orderByDesc('members_count')
                ->limit(6)
                ->get()
                ->map(function (Community $comm): array {
                    return [
                        'name' => $comm->name,
                        'slug' => $comm->slug,
                        'path' => $this->canonicalUrls->communityPath($comm),
                        'members_count' => $comm->members_count,
                    ];
                })
                ->all(),
        );

        return [
            'related_topics' => $relatedTopics,
            'related_communities' => $relatedCommunities,
        ];
    }
}
