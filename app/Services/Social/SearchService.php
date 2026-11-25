<?php

namespace App\Services\Social;

use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SearchService
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly HashtagService $hashtagService,
    ) {}

    /**
     * Search posts, communities, hashtags, and active users safely using parameterized escaped LIKE clauses.
     *
     * @return array{
     *     query: string,
     *     hashtag: string,
     *     posts: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     communities: list<array<string, mixed>>,
     *     hashtags: list<array{id: int, name: string, slug: string, posts_count: int}>
     * }
     */
    public function search(string $rawQuery, ?User $viewer, string $rawHashtag = ''): array
    {
        $cleanQuery = trim($rawQuery);
        $cleanHashtag = Str::lower(ltrim(trim($rawHashtag), '#'));

        if ($cleanHashtag !== '') {
            $posts = $this->feedService->baseVisibleQuery($viewer)
                ->whereHas('hashtags', static function (Builder $tagQuery) use ($cleanHashtag): void {
                    $tagQuery->where('slug', $cleanHashtag);
                })
                ->orderByDesc('published_at')
                ->limit(20)
                ->get();

            return [
                'query' => $cleanQuery,
                'hashtag' => $cleanHashtag,
                'posts' => $this->feedService->serializeCollection($posts, $viewer),
                'users' => [],
                'communities' => [],
                'hashtags' => $this->hashtagService->trending(5),
            ];
        }

        if ($cleanQuery === '' || mb_strlen($cleanQuery) < 2) {
            return [
                'query' => $cleanQuery,
                'hashtag' => '',
                'posts' => [],
                'users' => [],
                'communities' => [],
                'hashtags' => $this->hashtagService->trending(5),
            ];
        }

        $escaped = addcslashes($cleanQuery, '\\%_');
        $likePattern = "%{$escaped}%";

        $posts = $this->feedService->baseVisibleQuery($viewer)
            ->where(function (Builder $query) use ($likePattern): void {
                $query->where('title', 'like', $likePattern)
                    ->orWhere('content', 'like', $likePattern);
            })
            ->orderByDesc('published_at')
            ->limit(15)
            ->get();

        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        $usersQuery = User::query()
            ->select(['id', 'name', 'username', 'status', 'premium_until', 'premium_suspended_at'])
            ->with(['profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge,followers_count'])
            ->where('status', UserStatus::Active->value)
            ->where(function (Builder $query) use ($likePattern): void {
                $query->where('name', 'like', $likePattern)
                    ->orWhere('username', 'like', $likePattern);
            });

        if ($blockedIds !== []) {
            $usersQuery->whereNotIn('id', $blockedIds);
        }

        $users = array_values(
            $usersQuery->limit(10)->get()->map(static function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'headline' => $user->profile?->headline,
                    'avatar' => $user->profile?->avatarUrl(),
                    'is_verified' => $user->profile !== null && $user->profile->is_verified_badge,
                    'is_premium' => $user->isPremium(),
                    'followers_count' => $user->profile !== null ? $user->profile->followers_count : 0,
                ];
            })->all(),
        );

        $communities = array_values(
            Community::query()
                ->where('visibility', 'public')
                ->where(function (Builder $query) use ($likePattern): void {
                    $query->where('name', 'like', $likePattern)
                        ->orWhere('description', 'like', $likePattern);
                })
                ->orderByDesc('members_count')
                ->limit(6)
                ->get()
                ->map(static fn (Community $community): array => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'category_label' => $community->category->label(),
                    'avatar' => $community->avatarUrl(),
                    'is_verified' => $community->is_verified_badge,
                    'members_count' => $community->members_count,
                ])
                ->all(),
        );

        return [
            'query' => $cleanQuery,
            'hashtag' => '',
            'posts' => $this->feedService->serializeCollection($posts, $viewer),
            'users' => $users,
            'communities' => $communities,
            'hashtags' => $this->hashtagService->trending(5),
        ];
    }
}
