<?php

namespace App\Services\Admin;

use App\Enums\UserStatus;
use App\Models\AdminNotification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Security\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AdminNotificationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $broadcasts = AdminNotification::query()
            ->with(['sender:id,username', 'targetUser:id,username'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (AdminNotification $n): array => [
                'id' => $n->id,
                'audience' => $n->audience,
                'target_username' => $n->targetUser?->username,
                'category' => $n->category,
                'title' => $n->title,
                'body' => $n->body,
                'action_url' => $n->action_url,
                'status' => $n->status,
                'recipients_count' => $n->recipients_count,
                'sender_username' => $n->sender?->username,
                'scheduled_at' => $n->scheduled_at?->toIso8601String(),
                'sent_at' => $n->sent_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values()->all();

        $templates = NotificationTemplate::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (NotificationTemplate $t): array => [
                'id' => $t->id,
                'key' => $t->key,
                'name' => $t->name,
                'category' => $t->category,
                'subject_template' => $t->subject_template,
                'body_template' => $t->body_template,
                'is_active' => $t->is_active,
            ])->values()->all();

        return [
            'broadcasts' => $broadcasts,
            'templates' => $templates,
        ];
    }

    /**
     * @param  array{
     *     audience: string,
     *     target_username?: string|null,
     *     category: string,
     *     title: string,
     *     body: string,
     *     action_url?: string|null,
     *     scheduled_at?: string|null
     * }  $data
     */
    public function createBroadcast(User $actor, array $data): AdminNotification
    {
        $targetUser = null;

        if ($data['audience'] === 'user') {
            $cleanUsername = strtolower(trim((string) ($data['target_username'] ?? '')));
            $targetUser = User::query()->where('username', $cleanUsername)->first();
            if ($targetUser === null) {
                throw ValidationException::withMessages([
                    'target_username' => [__('Target user was not found.')],
                ]);
            }
        }

        $scheduledAt = isset($data['scheduled_at']) && trim((string) $data['scheduled_at']) !== ''
            ? CarbonImmutable::parse((string) $data['scheduled_at'])->utc()
            : null;

        $broadcast = new AdminNotification([
            'audience' => $data['audience'],
            'target_user_id' => $targetUser?->id,
            'category' => $data['category'],
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'action_url' => isset($data['action_url']) && trim((string) $data['action_url']) !== '' ? trim((string) $data['action_url']) : null,
            'status' => $scheduledAt !== null && $scheduledAt->isFuture() ? 'scheduled' : 'sent',
            'scheduled_at' => $scheduledAt,
        ]);
        $broadcast->sender_id = $actor->id;
        $broadcast->save();

        if ($broadcast->status === 'sent') {
            $query = User::query()->where('status', UserStatus::Active->value);

            if ($broadcast->audience === 'user' && $targetUser !== null) {
                $query->whereKey($targetUser->id);
            } elseif ($broadcast->audience === 'staff') {
                $query->whereIn('role', ['admin', 'moderator']);
            } elseif ($broadcast->audience === 'verified') {
                $query->whereNotNull('email_verified_at');
            }

            $sentCount = 0;

            $query->chunkById(200, static function ($users) use ($broadcast, &$sentCount): void {
                foreach ($users as $user) {
                    $user->notify(new SocialActivityNotification(
                        eventKey: "admin.{$broadcast->category}",
                        titleVi: $broadcast->title,
                        titleEn: $broadcast->title,
                        url: $broadcast->action_url ?: '/',
                        actorName: config('app.name', 'SysNoxis'),
                        actorUsername: 'system',
                    ));
                    $sentCount++;
                }
            });

            $broadcast->forceFill([
                'sent_at' => now(),
                'recipients_count' => $sentCount,
            ])->save();
        }

        $this->auditLogger->log(
            'admin.notification.created',
            $broadcast,
            ['audience' => $broadcast->audience, 'status' => $broadcast->status, 'recipients' => $broadcast->recipients_count],
            $actor,
        );

        return $broadcast;
    }

    public function cancelScheduled(User $actor, AdminNotification $notification): void
    {
        if ($notification->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'notification' => [__('Only scheduled notifications can be cancelled.')],
            ]);
        }

        $notification->forceFill(['status' => 'cancelled'])->save();
        $this->auditLogger->log('admin.notification.cancelled', $notification, [], $actor);
    }

    /**
     * @param  array{key: string, name: string, category: string, subject_template: string, body_template: string, is_active?: bool}  $data
     */
    public function saveTemplate(User $actor, array $data): NotificationTemplate
    {
        $template = NotificationTemplate::query()->firstOrNew(['key' => trim($data['key'])]);
        $template->forceFill([
            'name' => trim($data['name']),
            'category' => trim($data['category']),
            'subject_template' => trim($data['subject_template']),
            'body_template' => trim($data['body_template']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by' => $actor->id,
        ])->save();

        $this->auditLogger->log('admin.notification_template.saved', $template, ['key' => $template->key], $actor);

        return $template;
    }
}
