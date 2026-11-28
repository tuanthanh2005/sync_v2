<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\PostIdempotency;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class PostIdempotencyService
{
    /**
     * Attempt to claim an idempotency slot or return the already-created Post on duplicate/retry requests.
     *
     * @return array{claimed: bool, existing_post: Post|null, record: PostIdempotency|null}
     */
    public function acquireOrResolve(
        User $user,
        string $action,
        ?string $rawKey,
        string $payloadHash,
        int $ttlHours = 24,
    ): array {
        $key = $rawKey !== null ? trim($rawKey) : '';

        if ($key === '') {
            return ['claimed' => true, 'existing_post' => null, 'record' => null];
        }

        if (strlen($key) > 96 || preg_match('/^[A-Za-z0-9_.:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => [__('Invalid idempotency key format.')],
            ]);
        }

        try {
            $record = PostIdempotency::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'expires_at' => now()->addHours(max(1, $ttlHours)),
            ]);

            return ['claimed' => true, 'existing_post' => null, 'record' => $record];
        } catch (QueryException) {
            /** @var PostIdempotency|null $existing */
            $existing = PostIdempotency::query()
                ->where('user_id', $user->id)
                ->where('action', $action)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                throw ValidationException::withMessages([
                    'content' => [__('Please retry your request.')],
                ]);
            }

            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => [__('This request key has already been used with different content.')],
                ]);
            }

            if ($existing->status === 'completed' && $existing->post_id !== null) {
                $existingPost = Post::withTrashed()
                    ->with(['author.profile', 'community', 'hashtags', 'media', 'poll.options'])
                    ->find($existing->post_id);

                if ($existingPost !== null) {
                    return ['claimed' => false, 'existing_post' => $existingPost, 'record' => $existing];
                }
            }

            if ($existing->status === 'processing' && $existing->updated_at !== null && $existing->updated_at->gt(now()->subSeconds(30))) {
                throw ValidationException::withMessages([
                    'content' => [__('Your submission is already being processed. Please wait a moment.')],
                ]);
            }

            // Previous attempt failed or expired; reclaim safely for retry.
            $existing->forceFill([
                'status' => 'processing',
                'expires_at' => now()->addHours(max(1, $ttlHours)),
            ])->save();

            return ['claimed' => true, 'existing_post' => null, 'record' => $existing];
        }
    }

    public function markCompleted(?PostIdempotency $record, int $postId, int $responseCode = 201): void
    {
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => 'completed',
            'post_id' => $postId,
            'response_code' => $responseCode,
        ])->save();
    }

    public function markFailed(?PostIdempotency $record): void
    {
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => 'failed',
        ])->save();
    }

    /**
     * Build a deterministic SHA-256 fingerprint for idempotency payload verification.
     *
     * @param  list<string>  $imageSignatures
     */
    public static function payloadHash(
        ?string $title,
        string $content,
        ?int $communityId,
        ?string $category,
        ?string $visibility,
        ?string $linkUrl,
        array $imageSignatures = [],
        ?string $pollQuestion = null,
    ): string {
        $encoded = json_encode([
            'title' => trim((string) ($title ?? '')),
            'content' => trim($content),
            'community_id' => $communityId,
            'category' => $category,
            'visibility' => $visibility,
            'link_url' => trim((string) ($linkUrl ?? '')),
            'images' => $imageSignatures,
            'poll_question' => trim((string) ($pollQuestion ?? '')),
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $encoded);
    }
}
