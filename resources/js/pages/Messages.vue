<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { MessageSquare, Send } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import MessageController from '@/actions/App/Http/Controllers/Messaging/MessageController';
import EmptyState from '@/components/common/EmptyState.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import { show as showConversation } from '@/routes/messages';
import type { ActiveConversation, ConversationSummary } from '@/types';

const props = defineProps<{
    conversations: ConversationSummary[];
    activeConversation: ActiveConversation | null;
}>();

const { t } = useI18n();
const { getInitials } = useInitials();

const form = useForm({
    body: '',
});

let pollTimer: ReturnType<typeof setInterval> | null = null;

function submitMessage(): void {
    if (!props.activeConversation || !form.body.trim()) {
        return;
    }

    form.post(MessageController.send.url(props.activeConversation.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
}

onMounted(() => {
    pollTimer = setInterval(() => {
        if (document.hidden || !props.activeConversation) {
            return;
        }

        router.reload({
            only: ['conversations', 'activeConversation'],
        });
    }, 8000);
});

onBeforeUnmount(() => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
    }
});
</script>

<template>
    <div class="space-y-4">
        <Head :title="t('messages.title')" />

        <div
            class="grid min-h-[540px] overflow-hidden rounded-xl border border-border bg-card shadow-xs md:grid-cols-12"
        >
            <!-- Conversations list -->
            <aside
                class="border-b border-border md:col-span-4 md:border-r md:border-b-0"
            >
                <div class="border-b border-border px-4 py-3.5">
                    <h1 class="text-base font-bold text-foreground">
                        {{ t('messages.title') }}
                    </h1>
                </div>

                <div
                    v-if="conversations.length > 0"
                    class="divide-y divide-border"
                >
                    <Link
                        v-for="conv in conversations"
                        :key="conv.id"
                        :href="showConversation(conv.id)"
                        :class="[
                            'flex items-center justify-between gap-2.5 px-4 py-3 transition-colors hover:bg-muted/60',
                            activeConversation?.id === conv.id
                                ? 'bg-accent/50'
                                : '',
                        ]"
                    >
                        <div class="flex min-w-0 items-center gap-2.5">
                            <Avatar class="size-9 shrink-0 rounded-full">
                                <AvatarImage
                                    v-if="conv.other_user?.avatar"
                                    :src="conv.other_user.avatar"
                                    :alt="conv.other_user.name"
                                />
                                <AvatarFallback
                                    class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                >
                                    {{
                                        getInitials(
                                            conv.other_user?.name ?? 'User',
                                        )
                                    }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ conv.other_user?.name || 'Member' }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ conv.last_message || '...' }}
                                </p>
                            </div>
                        </div>

                        <Badge
                            v-if="conv.unread_count > 0"
                            class="shrink-0 bg-primary text-[10px] text-primary-foreground"
                        >
                            {{ conv.unread_count }}
                        </Badge>
                    </Link>
                </div>

                <p v-else class="p-4 text-xs text-muted-foreground">
                    {{ t('messages.inboxEmpty') }}
                </p>
            </aside>

            <!-- Active Thread -->
            <section class="flex flex-col md:col-span-8">
                <template v-if="activeConversation">
                    <header
                        class="flex items-center gap-3 border-b border-border px-4 py-3"
                    >
                        <Avatar class="size-8 rounded-full">
                            <AvatarImage
                                v-if="activeConversation.other_user?.avatar"
                                :src="activeConversation.other_user.avatar"
                                :alt="activeConversation.other_user.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{
                                    getInitials(
                                        activeConversation.other_user?.name ??
                                            'User',
                                    )
                                }}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <p class="text-sm font-semibold text-foreground">
                                {{ activeConversation.other_user?.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                @{{ activeConversation.other_user?.username }}
                            </p>
                        </div>
                    </header>

                    <div class="flex-1 space-y-3 overflow-y-auto p-4">
                        <div
                            v-for="msg in activeConversation.messages"
                            :key="msg.id"
                            :class="[
                                'flex',
                                msg.is_mine ? 'justify-end' : 'justify-start',
                            ]"
                        >
                            <div
                                :class="[
                                    'max-w-[78%] rounded-2xl px-3.5 py-2 text-sm',
                                    msg.is_mine
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-foreground',
                                ]"
                            >
                                <p class="whitespace-pre-line">
                                    {{ msg.body }}
                                </p>
                                <p
                                    :class="[
                                        'mt-1 text-[10px]',
                                        msg.is_mine
                                            ? 'text-primary-foreground/80'
                                            : 'text-muted-foreground',
                                    ]"
                                >
                                    {{
                                        new Date(
                                            msg.created_at,
                                        ).toLocaleTimeString()
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <form
                        @submit.prevent="submitMessage"
                        class="flex items-center gap-2 border-t border-border p-3"
                    >
                        <Input
                            v-model="form.body"
                            type="text"
                            maxlength="2000"
                            required
                            :placeholder="t('messages.placeholder')"
                            class="flex-1"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                            class="rounded-lg"
                        >
                            <Send class="size-4" />
                            <span>{{ t('messages.send') }}</span>
                        </Button>
                    </form>
                </template>

                <div v-else class="flex flex-1 items-center justify-center p-6">
                    <EmptyState
                        :icon="MessageSquare"
                        :title="t('messages.title')"
                        :description="t('messages.selectPrompt')"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
