<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\Post;
use App\Services\Seo\CanonicalUrlService;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\SeoConfigService;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\FeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TopicController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly SeoMetadataService $metadata,
        private readonly InternalLinkingService $internalLinking,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly SeoConfigService $seoConfig,
    ) {}

    public function index(Request $request, ?string $locale = null): Response
    {
        $activeLocale = $locale ?: app()->getLocale();
        $page = max(1, (int) $request->query('page', 1));

        $topics = Hashtag::query()
            ->where('is_restricted', false)
            ->where('is_spam', false)
            ->where('posts_count', '>', 0)
            ->orderByDesc('is_featured')
            ->orderByDesc('posts_count')
            ->paginate(24)
            ->withQueryString();

        $items = $topics->getCollection()->map(function (Hashtag $tag) use ($activeLocale): array {
            return [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'posts_count' => $tag->posts_count,
                'is_featured' => $tag->is_featured,
                'path' => $this->canonicalUrls->topicPath($tag, $activeLocale),
            ];
        })->values()->all();

        $path = $locale !== null && $locale !== $this->seoConfig->defaultLocale() ? "/{$locale}/topics" : '/topics';
        $seo = $this->metadata->forStaticPage(
            $path,
            'Topics & Discussions | '.config('app.name', 'SyncViet'),
            'Explore active topics, hashtags, and multilingual community discussions on '.config('app.name', 'SyncViet').'.',
            true,
            $activeLocale,
        );

        return Inertia::render('seo/TopicsIndex', [
            'topics' => $items,
            'locale' => $activeLocale,
            'pagination' => [
                'current_page' => $topics->currentPage(),
                'last_page' => $topics->lastPage(),
                'total' => $topics->total(),
                'prev_url' => $topics->previousPageUrl(),
                'next_url' => $topics->nextPageUrl(),
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'topics_index',
                'heading' => 'Topics & Discussions',
                'description' => $seo['description'],
                'links' => array_map(static fn (array $t): array => ['label' => '#'.$t['name'], 'href' => $t['path']], $items),
                'prev_url' => $topics->previousPageUrl(),
                'next_url' => $topics->nextPageUrl(),
            ],
        ]);
    }

    public function show(Request $request, string $slug, ?string $locale = null): Response
    {
        $activeLocale = $locale ?: app()->getLocale();
        $page = max(1, (int) $request->query('page', 1));

        /** @var Hashtag|null $hashtag */
        $hashtag = Hashtag::query()
            ->where('slug', Str::lower($slug))
            ->where('is_restricted', false)
            ->where('is_spam', false)
            ->first();

        if ($hashtag === null) {
            abort(404);
        }

        $postsPaginator = Post::query()
            ->publicVisible()
            ->where('is_spam', false)
            ->where('seo_noindex', false)
            ->whereHas('hashtags', static fn ($q) => $q->where('hashtags.id', $hashtag->id))
            ->with([
                'author:id,name,username,status',
                'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                'community:id,name,slug',
                'hashtags:id,name,slug',
                'media',
                'poll.options',
            ])
            ->orderByRaw('CASE WHEN language_code = ? THEN 0 ELSE 1 END', [$activeLocale])
            ->orderByDesc('published_at')
            ->paginate(15)
            ->withQueryString();

        $serializedPosts = $postsPaginator->getCollection()
            ->map(fn (Post $post): array => $this->feedService->serializeOne($post, $request->user()))
            ->values()
            ->all();

        $seo = $this->metadata->forTopic($hashtag, $activeLocale, $page);
        $linking = $this->internalLinking->forTopic($hashtag, $activeLocale);

        return Inertia::render('seo/TopicShow', [
            'topic' => [
                'id' => $hashtag->id,
                'name' => $hashtag->name,
                'slug' => $hashtag->slug,
                'posts_count' => $hashtag->posts_count,
                'is_featured' => $hashtag->is_featured,
            ],
            'locale' => $activeLocale,
            'posts' => $serializedPosts,
            'relatedTopics' => $linking['related_topics'],
            'relatedCommunities' => $linking['related_communities'],
            'pagination' => [
                'current_page' => $postsPaginator->currentPage(),
                'last_page' => $postsPaginator->lastPage(),
                'total' => $postsPaginator->total(),
                'prev_url' => $postsPaginator->previousPageUrl(),
                'next_url' => $postsPaginator->nextPageUrl(),
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'topic',
                'heading' => '#'.$hashtag->name,
                'description' => $seo['description'],
                'posts' => array_map(fn (Post $p): array => [
                    'title' => $p->title ?: Str::limit(strip_tags($p->content), 80),
                    'excerpt' => Str::limit(strip_tags($p->content), 220),
                    'href' => $this->canonicalUrls->postPath($p, $p->language_code ?: 'en'),
                    'author' => $p->author !== null ? $p->author->name : 'Member',
                ], $postsPaginator->getCollection()->all()),
                'links' => array_map(static fn (array $t): array => ['label' => '#'.$t['name'], 'href' => $t['path']], $linking['related_topics']),
                'prev_url' => $postsPaginator->previousPageUrl(),
                'next_url' => $postsPaginator->nextPageUrl(),
            ],
        ]);
    }
}
