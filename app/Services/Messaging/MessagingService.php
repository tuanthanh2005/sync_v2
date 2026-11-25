<?php

namespace App\Services\Messaging;

use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessagingService
{
    public function startOrOpenDirect(User $sender, User $recipient): Conversation
    {
        if ($sender->id === $recipient->id) {
            throw ValidationException::withMessages([
                'recipient' => [__('You cannot message your own account.')],
            ]);
        }

        if ($recipient->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'recipient' => [__('This recipient is not available for messaging.')],
            ]);
        }

        $this->ensureNotBlocked($sender, $recipient);

        $userOneId = min($sender->id, $recipient->id);
        $userTwoId = max($sender->id, $recipient->id);

        return Conversation::query()->firstOrCreate(
            ['user_one_id' => $userOneId, 'user_two_id' => $userTwoId],
            ['last_message_at' => now()],
        );
    }

    public function sendMessage(User $sender, Conversation $conversation, string $body): Message
    {
        $recipient = $conversation->otherUserFor($sender);

        if ($recipient === null || $recipient->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'body' => [__('This recipient is not available for messaging.')],
            ]);
        }

        $this->ensureNotBlocked($sender, $recipient);

        return DB::transaction(function () use ($sender, $conversation, $body): Message {
            $message = new Message([
                'body' => trim($body),
            ]);
            $message->conversation_id = $conversation->id;
            $message->sender_id = $sender->id;
            $message->created_at = now();
            $message->save();

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });
    }

    public function markRead(User $viewer, Conversation $conversation): void
    {
        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $viewer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function totalUnreadCount(User $viewer): int
    {
        return Message::query()
            ->where('sender_id', '!=', $viewer->id)
            ->whereNull('read_at')
            ->whereHas('conversation', static function ($query) use ($viewer): void {
                $query->where('user_one_id', $viewer->id)
                    ->orWhere('user_two_id', $viewer->id);
            })
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function inboxPayload(User $viewer, ?Conversation $activeConversation = null): array
    {
        $conversations = Conversation::query()
            ->where(static function ($query) use ($viewer): void {
                $query->where('user_one_id', $viewer->id)
                    ->orWhere('user_two_id', $viewer->id);
            })
            ->with([
                'userOne:id,name,username',
                'userOne.profile:id,user_id,avatar_disk,avatar_path',
                'userTwo:id,name,username',
                'userTwo.profile:id,user_id,avatar_disk,avatar_path',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $conversationIds = $conversations->pluck('id')->all();

        $unreadByConversation = [];
        $latestMessages = [];

        if ($conversationIds !== []) {
            $unreadRows = Message::query()
                ->whereIn('conversation_id', $conversationIds)
                ->where('sender_id', '!=', $viewer->id)
                ->whereNull('read_at')
                ->groupBy('conversation_id')
                ->selectRaw('conversation_id, COUNT(*) as unread_total')
                ->get();

            foreach ($unreadRows as $row) {
                $unreadByConversation[(int) $row->getAttribute('conversation_id')] = (int) $row->getAttribute('unread_total');
            }

            $recentMsgs = Message::query()
                ->whereIn('conversation_id', $conversationIds)
                ->orderByDesc('id')
                ->get(['id', 'conversation_id', 'sender_id', 'body', 'created_at']);

            foreach ($recentMsgs as $msg) {
                if (! isset($latestMessages[$msg->conversation_id])) {
                    $latestMessages[$msg->conversation_id] = $msg;
                }
            }
        }

        $serializedConversations = array_values(
            $conversations->map(static function (Conversation $conv) use ($viewer, $unreadByConversation, $latestMessages): array {
                $other = $conv->otherUserFor($viewer);
                $lastMessage = $latestMessages[$conv->id] ?? null;

                return [
                    'id' => $conv->id,
                    'other_user' => $other === null ? null : [
                        'id' => $other->id,
                        'name' => $other->name,
                        'username' => $other->username,
                        'avatar' => $other->profile?->avatarUrl(),
                    ],
                    'last_message' => $lastMessage?->body,
                    'last_message_at' => $conv->last_message_at?->toIso8601String(),
                    'unread_count' => $unreadByConversation[$conv->id] ?? 0,
                ];
            })->all(),
        );

        $activeData = null;

        if ($activeConversation !== null) {
            $this->markRead($viewer, $activeConversation);
            $activeConversation->loadMissing([
                'userOne:id,name,username',
                'userOne.profile:id,user_id,avatar_disk,avatar_path',
                'userTwo:id,name,username',
                'userTwo.profile:id,user_id,avatar_disk,avatar_path',
            ]);
            $other = $activeConversation->otherUserFor($viewer);

            $messages = array_values(
                Message::query()
                    ->where('conversation_id', $activeConversation->id)
                    ->orderBy('id')
                    ->limit(80)
                    ->get()
                    ->map(static fn (Message $msg): array => [
                        'id' => $msg->id,
                        'sender_id' => $msg->sender_id,
                        'is_mine' => $msg->sender_id === $viewer->id,
                        'body' => $msg->body,
                        'created_at' => $msg->created_at->toIso8601String(),
                    ])
                    ->all(),
            );

            $activeData = [
                'id' => $activeConversation->id,
                'other_user' => $other === null ? null : [
                    'id' => $other->id,
                    'name' => $other->name,
                    'username' => $other->username,
                    'avatar' => $other->profile?->avatarUrl(),
                ],
                'messages' => $messages,
            ];
        }

        return [
            'conversations' => $serializedConversations,
            'activeConversation' => $activeData,
        ];
    }

    private function ensureNotBlocked(User $sender, User $recipient): void
    {
        $blocked = UserBlock::query()
            ->where(static function ($query) use ($sender, $recipient): void {
                $query->where('blocker_id', $sender->id)->where('blocked_id', $recipient->id);
            })
            ->orWhere(static function ($query) use ($sender, $recipient): void {
                $query->where('blocker_id', $recipient->id)->where('blocked_id', $sender->id);
            })
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'body' => [__('Messaging is disabled due to block settings.')],
            ]);
        }
    }
}
