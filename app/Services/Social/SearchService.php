<?php

namespace App\Services\Social;

use App\Enums\PostCategory;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\User;
use App\Services\Growth\EarlyMemberAndBadgeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SearchService
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly HashtagService $hashtagService,
    ) {}

    /**
     * Execute full search or genuine Explore discovery payload (zero fake trending/popular data).
     *
     * @return array{
     *     mode: 'explore'|'search'|'hashtag',
     *     query: string,
     *     hashtag: string,
     *     active_type: string,
     *     posts: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     communities: list<array<string, mixed>>,
     *     hashtags: list<array{id: int, name: string, slug: string, posts_count: int}>,
     *     counts: array{all: int, posts: int, users: int, communities: int, topics: int},
     *     pagination: array{current_page: int, last_page: int, total: int, has_more: bool},
     *     discovery: array{
     *         has_real_trending: bool,
     *         topics: list<array{id: int, name: string, slug: string, posts_count: int}>,
     *         categories: list<array{value: string, label: string, icon: string}>,
     *         featured_posts: list<array<string, mixed>>,
     *         latest_posts: list<array<string, mixed>>,
     *         suggested_communities: list<array<string, mixed>>,
     *         notable_accounts: list<array<string, mixed>>
     *     }
     * }
     */
    public function search(
        string $rawQuery,
        ?User $viewer,
        string $rawHashtag = '',
        string $type = 'all',
        int $page = 1,
    ): array {
        $cleanQuery = trim($rawQuery);
        $cleanHashtag = Str::lower(ltrim(trim($rawHashtag), '#'));
        $activeType = in_array($type, ['all', 'posts', 'users', 'communities', 'topics'], true) ? $type : 'all';
        $safePage = max(1, min(50, $page));

        if ($cleanHashtag !== '') {
            $paginator = $this->feedService->baseVisibleQuery($viewer)
                ->whereHas('hashtags', static function (Builder $tagQuery) use ($cleanHashtag): void {
                    $tagQuery->where('slug', $cleanHashtag);
                })
                ->orderByDesc('published_at')
                ->paginate(10, ['*'], 'page', $safePage);

            $posts = $this->feedService->serializeCollection(collect($paginator->items()), $viewer);
            $topics = $this->hashtagService->trending(8);
            $discovery = $posts === [] ? $this->buildDiscoveryPayload($viewer, true) : $this->emptyDiscoveryPayload();

            return [
                'mode' => 'hashtag',
                'query' => $cleanQuery,
                'hashtag' => $cleanHashtag,
                'active_type' => 'posts',
                'posts' => $posts,
                'users' => [],
                'communities' => [],
                'hashtags' => $topics,
                'counts' => [
                    'all' => $paginator->total(),
                    'posts' => $paginator->total(),
                    'users' => 0,
                    'communities' => 0,
                    'topics' => count($topics),
                ],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ],
                'discovery' => $discovery,
            ];
        }

        // State 1 — No keyword entered yet: return genuine Explore Discovery content (NEVER "no results").
        if ($cleanQuery === '' || mb_strlen($cleanQuery) < 2) {
            $discovery = $this->buildDiscoveryPayload($viewer, false);

            return [
                'mode' => 'explore',
                'query' => $cleanQuery,
                'hashtag' => '',
                'active_type' => $activeType,
                'posts' => [],
                'users' => [],
                'communities' => [],
                'hashtags' => $discovery['topics'],
                'counts' => [
                    'all' => 0,
                    'posts' => 0,
                    'users' => 0,
                    'communities' => 0,
                    'topics' => 0,
                ],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'has_more' => false,
                ],
                'discovery' => $discovery,
            ];
        }

        $patterns = $this->buildSearchLikePatterns($cleanQuery);
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        // Posts search with pagination
        $postsQuery = $this->feedService->baseVisibleQuery($viewer)
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('title', 'like', $pattern)
                        ->orWhere('content', 'like', $pattern);
                }
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $postsPaginator = $postsQuery->paginate(10, ['*'], 'page', $safePage);
        $posts = $this->feedService->serializeCollection(collect($postsPaginator->items()), $viewer);

        // Users search (active & non-blocked only)
        $usersQuery = User::query()
            ->select(['id', 'name', 'username', 'avatar_url', 'status', 'premium_until', 'premium_suspended_at', 'account_type', 'ai_disclosure'])
            ->with(['profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge,followers_count'])
            ->where('status', UserStatus::Active->value)
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('name', 'like', $pattern)
                        ->orWhere('username', 'like', $pattern);
                }
            });

        if ($blockedIds !== []) {
            $usersQuery->whereNotIn('id', $blockedIds);
        }

        $usersTotal = (clone $usersQuery)->count();
        $userModels = $usersQuery->limit(10)->get();
        $users = $this->serializeUsers(array_values($userModels->all()));

        // Communities search (public & active only)
        $communitiesQuery = Community::query()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('name', 'like', $pattern)
                        ->orWhere('description', 'like', $pattern);
                }
            });

        $communitiesTotal = (clone $communitiesQuery)->count();
        $communities = $this->serializeCommunities(
            array_values($communitiesQuery->orderByDesc('members_count')->limit(8)->get()->all()),
        );

        // Topics / Hashtags search (public non-spam only)
        $topicsQuery = Hashtag::query()
            ->where('is_restricted', false)
            ->where('is_spam', false)
            ->where('posts_count', '>', 0)
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('name', 'like', $pattern)
                        ->orWhere('slug', 'like', $pattern);
                }
            });

        $topicsTotal = (clone $topicsQuery)->count();
        $matchingHashtags = array_values(
            $topicsQuery
                ->orderByDesc('posts_count')
                ->limit(10)
                ->get(['id', 'name', 'slug', 'posts_count'])
                ->map(static fn (Hashtag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'posts_count' => $tag->posts_count,
                ])
                ->all(),
        );

        $totalMatches = $postsPaginator->total() + $usersTotal + $communitiesTotal + $topicsTotal;
        $discovery = $totalMatches === 0
            ? $this->buildDiscoveryPayload($viewer, true)
            : $this->emptyDiscoveryPayload();

        return [
            'mode' => 'search',
            'query' => $cleanQuery,
            'hashtag' => '',
            'active_type' => $activeType,
            'posts' => $posts,
            'users' => $users,
            'communities' => $communities,
            'hashtags' => $matchingHashtags,
            'counts' => [
                'all' => $totalMatches,
                'posts' => $postsPaginator->total(),
                'users' => $usersTotal,
                'communities' => $communitiesTotal,
                'topics' => $topicsTotal,
            ],
            'pagination' => [
                'current_page' => $postsPaginator->currentPage(),
                'last_page' => $postsPaginator->lastPage(),
                'total' => $postsPaginator->total(),
                'has_more' => $postsPaginator->hasMorePages(),
            ],
            'discovery' => $discovery,
        ];
    }

    /**
     * Lightweight real-time typeahead suggestions (debounced on frontend, strictly real data).
     *
     * @return array{
     *     query: string,
     *     users: list<array{id: int, name: string, username: string, avatar: string|null, is_verified: bool}>,
     *     communities: list<array{id: int, name: string, slug: string, members_count: int, avatar: string|null}>,
     *     topics: list<array{id: int, name: string, slug: string, posts_count: int}>,
     *     posts: list<array{id: int, title: string}>
     * }
     */
    public function suggestions(string $rawQuery, ?User $viewer): array
    {
        $cleanQuery = trim($rawQuery);
        if (mb_strlen($cleanQuery) < 2) {
            return [
                'query' => $cleanQuery,
                'users' => [],
                'communities' => [],
                'topics' => [],
                'posts' => [],
            ];
        }

        $cacheKey = $viewer === null
            ? 'syncviet.search.suggest.v1.'.sha1(Str::lower($cleanQuery))
            : null;

        $compute = function () use ($cleanQuery, $viewer): array {
            $patterns = $this->buildSearchLikePatterns($cleanQuery);
            $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

            $usersQuery = User::query()
                ->select(['id', 'name', 'username', 'avatar_url', 'status'])
                ->with(['profile:id,user_id,avatar_disk,avatar_path,is_verified_badge'])
                ->where('status', UserStatus::Active->value)
                ->where(function (Builder $q) use ($patterns): void {
                    foreach ($patterns as $p) {
                        $q->orWhere('name', 'like', $p)->orWhere('username', 'like', $p);
                    }
                });

            if ($blockedIds !== []) {
                $usersQuery->whereNotIn('id', $blockedIds);
            }

            $users = array_values($usersQuery->limit(3)->get()->map(static fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'avatar' => $u->avatarUrl(),
                'is_verified' => $u->profile !== null && $u->profile->is_verified_badge,
            ])->all());

            $communities = array_values(Community::query()
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->where(function (Builder $q) use ($patterns): void {
                    foreach ($patterns as $p) {
                        $q->orWhere('name', 'like', $p);
                    }
                })
                ->orderByDesc('members_count')
                ->limit(3)
                ->get()
                ->map(static fn (Community $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'members_count' => $c->members_count,
                    'avatar' => $c->avatarUrl(),
                ])
                ->all());

            $topics = array_values(Hashtag::query()
                ->where('is_restricted', false)
                ->where('is_spam', false)
                ->where('posts_count', '>', 0)
                ->where(function (Builder $q) use ($patterns): void {
                    foreach ($patterns as $p) {
                        $q->orWhere('name', 'like', $p)->orWhere('slug', 'like', $p);
                    }
                })
                ->orderByDesc('posts_count')
                ->limit(4)
                ->get(['id', 'name', 'slug', 'posts_count'])
                ->map(static fn (Hashtag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'posts_count' => $tag->posts_count,
                ])
                ->all());

            $posts = array_values($this->feedService->baseVisibleQuery($viewer)
                ->where(function (Builder $q) use ($patterns): void {
                    foreach ($patterns as $p) {
                        $q->orWhere('title', 'like', $p)->orWhere('content', 'like', $p);
                    }
                })
                ->orderByDesc('published_at')
                ->limit(3)
                ->get(['id', 'title', 'content'])
                ->map(static fn ($post): array => [
                    'id' => (int) $post->id,
                    'title' => trim((string) ($post->title ?: Str::limit(strip_tags((string) $post->content), 72))),
                ])
                ->all());

            return [
                'query' => $cleanQuery,
                'users' => $users,
                'communities' => $communities,
                'topics' => $topics,
                'posts' => $posts,
            ];
        };

        if ($cacheKey !== null) {
            /** @var array{query: string, users: list<array{id: int, name: string, username: string, avatar: string|null, is_verified: bool}>, communities: list<array{id: int, name: string, slug: string, members_count: int, avatar: string|null}>, topics: list<array{id: int, name: string, slug: string, posts_count: int}>, posts: list<array{id: int, title: string}>} $cached */
            $cached = Cache::remember($cacheKey, now()->addSeconds(20), $compute);

            return $cached;
        }

        return $compute();
    }

    /**
     * Build authentic Explore discovery payload from real database content only.
     *
     * @return array{
     *     has_real_trending: bool,
     *     topics: list<array{id: int, name: string, slug: string, posts_count: int}>,
     *     categories: list<array{value: string, label: string, icon: string}>,
     *     featured_posts: list<array<string, mixed>>,
     *     latest_posts: list<array<string, mixed>>,
     *     suggested_communities: list<array<string, mixed>>,
     *     notable_accounts: list<array<string, mixed>>
     * }
     */
    private function buildDiscoveryPayload(?User $viewer, bool $compactFallback): array
    {
        $topics = $this->hashtagService->trending(10);
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        // Featured / engaging posts — ONLY label as Trending when real human/guest engagement exists (excludes AI views).
        $engagingPosts = $this->feedService->baseVisibleQuery($viewer)
            ->whereRaw('(reactions_count + comments_count + human_views_count + guest_views_count + legacy_views_count) >= 3')
            ->orderByRaw('(reactions_count * 3 + comments_count * 4 + human_views_count + guest_views_count + legacy_views_count) DESC')
            ->orderByDesc('published_at')
            ->limit($compactFallback ? 3 : 4)
            ->get();

        $hasRealTrending = $engagingPosts->isNotEmpty();
        $featuredIds = $engagingPosts->pluck('id')->all();

        $latestQuery = $this->feedService->baseVisibleQuery($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($featuredIds !== []) {
            $latestQuery->whereNotIn('id', $featuredIds);
        }

        $latestPosts = $latestQuery->limit($compactFallback ? 3 : 5)->get();

        $communities = $this->serializeCommunities(
            array_values(
                Community::query()
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderByDesc('members_count')
                    ->orderByDesc('id')
                    ->limit($compactFallback ? 4 : 6)
                    ->get()
                    ->all(),
            ),
        );

        $accountsQuery = User::query()
            ->select(['id', 'name', 'username', 'avatar_url', 'status', 'premium_until', 'premium_suspended_at', 'account_type', 'ai_disclosure'])
            ->with(['profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge,followers_count'])
            ->where('status', UserStatus::Active->value)
            ->whereHas('profile')
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->orderByDesc('profiles.followers_count')
            ->orderByDesc('users.id')
            ->select('users.id', 'users.name', 'users.username', 'users.avatar_url', 'users.status', 'users.premium_until', 'users.premium_suspended_at', 'users.account_type', 'users.ai_disclosure');

        if ($viewer !== null) {
            $accountsQuery->where('users.id', '!=', $viewer->id);
        }
        if ($blockedIds !== []) {
            $accountsQuery->whereNotIn('users.id', $blockedIds);
        }

        $notableAccounts = $compactFallback
            ? []
            : $this->serializeUsers(array_values($accountsQuery->limit(6)->get()->all()));

        return [
            'has_real_trending' => $hasRealTrending,
            'topics' => $topics,
            'categories' => PostCategory::options(),
            'featured_posts' => $this->feedService->serializeCollection($engagingPosts, $viewer),
            'latest_posts' => $this->feedService->serializeCollection($latestPosts, $viewer),
            'suggested_communities' => $communities,
            'notable_accounts' => $notableAccounts,
        ];
    }

    /**
     * @return array{
     *     has_real_trending: bool,
     *     topics: list<array{id: int, name: string, slug: string, posts_count: int}>,
     *     categories: list<array{value: string, label: string, icon: string}>,
     *     featured_posts: list<array<string, mixed>>,
     *     latest_posts: list<array<string, mixed>>,
     *     suggested_communities: list<array<string, mixed>>,
     *     notable_accounts: list<array<string, mixed>>
     * }
     */
    private function emptyDiscoveryPayload(): array
    {
        return [
            'has_real_trending' => false,
            'topics' => [],
            'categories' => PostCategory::options(),
            'featured_posts' => [],
            'latest_posts' => [],
            'suggested_communities' => [],
            'notable_accounts' => [],
        ];
    }

    /**
     * Build escaped LIKE patterns supporting Unicode and unaccented Vietnamese (`d` <-> `đ`).
     *
     * @return list<string>
     */
    private function buildSearchLikePatterns(string $cleanQuery): array
    {
        $candidates = [$cleanQuery];

        $ascii = trim(Str::ascii($cleanQuery));
        if ($ascii !== '' && Str::lower($ascii) !== Str::lower($cleanQuery)) {
            $candidates[] = $ascii;
        }

        // Vietnamese `đ` is not folded to `d` in utf8mb4_unicode_ci; add a safe `d` -> `đ` leading-word variant.
        if (preg_match('/\bd/iu', $cleanQuery) === 1 && ! str_contains(Str::lower($cleanQuery), 'đ')) {
            $withDStroke = preg_replace('/\bd/iu', 'đ', $cleanQuery);
            if (is_string($withDStroke) && $withDStroke !== $cleanQuery) {
                $candidates[] = $withDStroke;
            }
        }

        $patterns = [];
        foreach (array_unique($candidates) as $term) {
            $escaped = addcslashes($term, '\\%_');
            $patterns[] = "%{$escaped}%";
        }

        return $patterns;
    }

    /**
     * @param  list<User>  $users
     * @return list<array<string, mixed>>
     */
    private function serializeUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $ids = array_map(static fn (User $u): int => $u->id, $users);
        $badgesByUser = app(EarlyMemberAndBadgeService::class)->activeBadgesForUsers($ids);

        return array_map(static function (User $user) use ($badgesByUser): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'headline' => $user->profile?->headline,
                'avatar' => $user->avatarUrl(),
                'is_verified' => $user->profile !== null && $user->profile->is_verified_badge,
                'is_premium' => $user->isPremium(),
                'is_ai_agent' => $user->isAiAgent(),
                'ai_disclosure' => $user->ai_disclosure,
                'badges' => $badgesByUser[$user->id] ?? [],
                'followers_count' => $user->profile !== null ? $user->profile->followers_count : 0,
            ];
        }, $users);
    }

    /**
     * @param  list<Community>  $communities
     * @return list<array<string, mixed>>
     */
    private function serializeCommunities(array $communities): array
    {
        return array_map(static fn (Community $community): array => [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'category_label' => $community->category->label(),
            'avatar' => $community->avatarUrl(),
            'is_verified' => $community->is_verified_badge,
            'members_count' => $community->members_count,
        ], $communities);
    }
}
