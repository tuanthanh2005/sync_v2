<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\SocialActivityNotification;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    public function notifyIfAllowed(User $recipient, ?User $actor, SocialActivityNotification $notification): void
    {
        if ($actor !== null && $actor->id === $recipient->id) {
            return;
        }

        if ($actor !== null) {
            $blocked = UserBlock::query()
                ->where(static function ($query) use ($recipient, $actor): void {
                    $query->where('blocker_id', $recipient->id)->where('blocked_id', $actor->id);
                })
                ->orWhere(static function ($query) use ($recipient, $actor): void {
                    $query->where('blocker_id', $actor->id)->where('blocked_id', $recipient->id);
                })
                ->exists();

            if ($blocked) {
                return;
            }
        }

        $recipient->notify($notification);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, string $locale, int $limit = 30): array
    {
        return array_values(
            $user->notifications()
                ->latest()
                ->limit($limit)
                ->get()
                ->map(static function (DatabaseNotification $notification) use ($locale): array {
                    /** @var array<string, mixed> $data */
                    $data = $notification->data;

                    return [
                        'id' => $notification->id,
                        'event' => (string) ($data['event'] ?? 'activity'),
                        'title' => $locale === 'en'
                            ? (string) ($data['title_en'] ?? $data['title_vi'] ?? '')
                            : (string) ($data['title_vi'] ?? $data['title_en'] ?? ''),
                        'url' => (string) ($data['url'] ?? '/'),
                        'actor_name' => $data['actor_name'] ?? null,
                        'actor_username' => $data['actor_username'] ?? null,
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ];
                })
                ->all(),
        );
    }

    public function markRead(User $user, string $notificationId): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->whereKey($notificationId)->first();

        if ($notification !== null && $notification->read_at === null) {
            $notification->markAsRead();
        }
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
