<?php

namespace App\Services\Social;

use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HashtagService
{
    /**
     * Extract hashtag tokens cleanly from post title and body text.
     *
     * @return list<array{name: string, slug: string}>
     */
    public function extractTags(string $text): array
    {
        if (preg_match_all('/(?:^|\s)#([A-Za-z0-9_]{2,50})\b/u', $text, $matches) < 1) {
            return [];
        }

        $tags = [];

        foreach ($matches[1] as $rawTag) {
            $slug = Str::lower($rawTag);

            if (! isset($tags[$slug])) {
                $tags[$slug] = [
                    'name' => $rawTag,
                    'slug' => $slug,
                ];
            }
        }

        return array_values(array_slice($tags, 0, 10));
    }

    public function syncForPost(Post $post): void
    {
        $combinedText = trim(($post->title ?? '').' '.$post->content);
        $extracted = $this->extractTags($combinedText);

        $previousTagIds = DB::table('post_hashtags')
            ->where('post_id', $post->id)
            ->pluck('hashtag_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $newTagIds = [];

        foreach ($extracted as $tagData) {
            $hashtag = Hashtag::query()->firstOrCreate(
                ['slug' => $tagData['slug']],
                ['name' => $tagData['name']],
            );
            $newTagIds[] = $hashtag->id;
        }

        $post->hashtags()->sync($newTagIds);

        $affectedIds = array_values(array_unique([...$previousTagIds, ...$newTagIds]));

        if ($affectedIds !== []) {
            $this->recalculateCounts($affectedIds);
        }
    }

    /**
     * @param  list<int>  $hashtagIds
     */
    public function recalculateCounts(array $hashtagIds): void
    {
        foreach ($hashtagIds as $hashtagId) {
            $count = Post::query()
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->whereNotNull('published_at')
                ->whereIn('visibility', [PostVisibility::Public->value, PostVisibility::Community->value])
                ->whereHas('hashtags', static function ($query) use ($hashtagId): void {
                    $query->where('hashtags.id', $hashtagId);
                })
                ->count();

            Hashtag::query()
                ->whereKey($hashtagId)
                ->update(['posts_count' => $count]);
        }
    }

    /**
     * Fetch trending hashtags globally or scoped to a single community from real posts.
     *
     * @return list<array{id: int, name: string, slug: string, posts_count: int}>
     */
    public function trending(int $limit = 5, ?int $communityId = null): array
    {
        if ($communityId === null) {
            return array_values(
                Hashtag::query()
                    ->where('posts_count', '>', 0)
                    ->orderByDesc('posts_count')
                    ->orderBy('name')
                    ->limit($limit)
                    ->get(['id', 'name', 'slug', 'posts_count'])
                    ->map(static fn (Hashtag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'posts_count' => $tag->posts_count,
                    ])
                    ->all(),
            );
        }

        $rows = DB::table('post_hashtags')
            ->join('hashtags', 'hashtags.id', '=', 'post_hashtags.hashtag_id')
            ->join('posts', 'posts.id', '=', 'post_hashtags.post_id')
            ->where('posts.community_id', $communityId)
            ->whereNull('posts.deleted_at')
            ->where('posts.moderation_status', ModerationStatus::Approved->value)
            ->groupBy('hashtags.id', 'hashtags.name', 'hashtags.slug')
            ->selectRaw('hashtags.id, hashtags.name, hashtags.slug, COUNT(posts.id) as scoped_count')
            ->orderByDesc('scoped_count')
            ->limit($limit)
            ->get();

        return array_values(
            $rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'posts_count' => (int) $row->scoped_count,
            ])->all(),
        );
    }
}
