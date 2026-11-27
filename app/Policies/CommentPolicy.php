<?php

namespace App\Policies;

use App\Enums\ModerationStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\Gate;

class CommentPolicy
{
    public function create(User $user, Post $post): bool
    {
        if (! $user->isActive() || ! $user->hasVerifiedEmail()) {
            return false;
        }

        if ($post->moderation_status !== ModerationStatus::Approved) {
            return false;
        }

        if (! Gate::forUser($user)->allows('view', $post)) {
            return false;
        }

        return ! UserBlock::query()
            ->where(function ($query) use ($user, $post): void {
                $query->where('blocker_id', $user->id)
                    ->where('blocked_id', $post->user_id);
            })
            ->orWhere(function ($query) use ($user, $post): void {
                $query->where('blocker_id', $post->user_id)
                    ->where('blocked_id', $user->id);
            })
            ->exists();
    }

    public function delete(User $user, Comment $comment): bool
    {
        if (! $user->isActive() || ! $user->hasVerifiedEmail()) {
            return false;
        }

        return $comment->user_id === $user->id
            || $comment->post?->user_id === $user->id
            || $user->isStaff();
    }
}
