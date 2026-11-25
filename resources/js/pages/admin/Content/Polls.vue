<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EyeOff, Lock, Trash2, Vote } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatNumber } from '@/lib/formatters';
import { polls as pollsRoute } from '@/routes/admin/content';
import { moderate as moderatePollRoute } from '@/routes/admin/content/polls';

type PollRow = {
    id: number;
    post_id: number;
    question: string;
    total_votes: number;
    is_closed: boolean;
    is_expired: boolean;
    moderation_status: string;
    ends_at: string | null;
    author_username: string | null;
    options: Array<{ id: number; label: string; votes_count: number }>;
};

defineProps<{
    polls: {
        data: PollRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    search: string;
}>();

const { t, locale } = useI18n();
const dialogOpen = ref(false);
const activePoll = ref<PollRow | null>(null);
const activeAction = ref('close');

function openAction(poll: PollRow, action: string): void {
    activePoll.value = poll;
    activeAction.value = action;
    dialogOpen.value = true;
}

function submitAction(reason: string): void {
    if (!activePoll.value) {
        return;
    }
    router.post(
        moderatePollRoute.url(activePoll.value.id),
        { action: activeAction.value, reason },
        {
            preserveScroll: true,
            onFinish: () => {
                dialogOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.content.pollsTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Vote class="size-5 text-primary" />
                <span>{{ t('admin.content.pollsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.pollsSubtitle') }}
            </p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="polls.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-3">
                <div
                    v-for="poll in polls.data"
                    :key="poll.id"
                    class="rounded-xl border border-border p-4 text-xs"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-foreground">{{
                                    poll.question
                                }}</span>
                                <Badge variant="secondary" class="text-[10px]"
                                    >{{
                                        formatNumber(poll.total_votes, locale)
                                    }}
                                    votes</Badge
                                >
                                <Badge
                                    v-if="poll.is_closed"
                                    variant="destructive"
                                    class="text-[10px]"
                                    >Closed</Badge
                                >
                                <Badge variant="outline" class="text-[10px]">{{
                                    poll.moderation_status
                                }}</Badge>
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                Post #{{ poll.post_id }} · @{{
                                    poll.author_username || 'unknown'
                                }}
                                · Ends:
                                {{ formatDateTime(poll.ends_at, locale) }}
                            </p>
                            <div class="flex flex-wrap gap-2 pt-1">
                                <span
                                    v-for="opt in poll.options"
                                    :key="opt.id"
                                    class="rounded-md bg-muted px-2 py-1 text-[11px]"
                                >
                                    {{ opt.label }} ({{ opt.votes_count }})
                                </span>
                            </div>
                        </div>
                        <div class="flex gap-1.5">
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="
                                    openAction(
                                        poll,
                                        poll.is_closed ? 'reopen' : 'close',
                                    )
                                "
                            >
                                <Lock class="size-3.5" />
                                <span>{{
                                    poll.is_closed
                                        ? 'Reopen'
                                        : t('admin.content.closePoll')
                                }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 px-2 text-[11px]"
                                @click="
                                    openAction(
                                        poll,
                                        poll.moderation_status === 'hidden'
                                            ? 'restore'
                                            : 'hide',
                                    )
                                "
                            >
                                <EyeOff class="size-3.5" />
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-7 px-2 text-[11px]"
                                @click="openAction(poll, 'delete')"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <AdminPagination
                :current-page="polls.current_page"
                :last-page="polls.last_page"
                :total="polls.total"
                :from="polls.from"
                :to="polls.to"
                :base-url="pollsRoute.url()"
            />
        </div>

        <ReasonDialog
            v-model:open="dialogOpen"
            :title="`${t('admin.content.pollsTitle')} · ${activeAction}`"
            :description="activePoll?.question || ''"
            @submit="submitAction"
        />
    </div>
</template>
