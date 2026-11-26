<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    EyeOff,
    MessageSquareText,
    RotateCcw,
    Search,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import { comments as commentsRoute } from '@/routes/admin/content';
import {
    bulk as bulkCommentsRoute,
    moderate as moderateCommentRoute,
} from '@/routes/admin/content/comments';

type CommentRow = {
    id: number;
    post_id: number;
    post_excerpt: string;
    content: string;
    moderation_status: string;
    is_spam: boolean;
    author: { id: number; name: string; username: string } | null;
    created_at: string | null;
    deleted_at: string | null;
};

const props = defineProps<{
    comments: {
        data: CommentRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { filter: string; search: string };
}>();

const { t, locale } = useI18n();
const searchInput = ref(props.filters.search);
const selectedIds = ref<number[]>([]);
const reasonOpen = ref(false);
const activeCommentId = ref<number | null>(null);
const activeAction = ref('hide');
const isBulk = ref(false);

function moderationStatusLabel(status: string): string {
    const map: Record<string, string> = {
        approved: 'admin.contentExtra.modStatus.approved',
        visible: 'admin.contentExtra.modStatus.visible',
        published: 'admin.contentExtra.modStatus.published',
        pending: 'admin.contentExtra.modStatus.pending',
        hidden: 'admin.contentExtra.modStatus.hidden',
        removed: 'admin.contentExtra.modStatus.removed',
        rejected: 'admin.contentExtra.modStatus.rejected',
    };
    const key = map[status.toLowerCase()];

    return key ? t(key) : status;
}

function commentActionLabel(action: string): string {
    const map: Record<string, string> = {
        hide: 'admin.contentExtra.actions.hide',
        restore_visibility: 'admin.contentExtra.actions.restore_visibility',
        restore_deleted: 'admin.contentExtra.actions.restore_deleted',
        soft_delete: 'admin.contentExtra.actions.soft_delete',
    };
    const key = map[action];

    return key ? t(key) : action;
}

function applyFilter(nextFilter = props.filters.filter): void {
    router.get(
        commentsRoute.url(),
        { filter: nextFilter, search: searchInput.value.trim() || undefined },
        { preserveState: true },
    );
}

function toggleSelect(id: number): void {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}

function openSingle(id: number, action: string): void {
    isBulk.value = false;
    activeCommentId.value = id;
    activeAction.value = action;
    reasonOpen.value = true;
}

function openBulk(action: string): void {
    if (selectedIds.value.length === 0) {
        return;
    }
    isBulk.value = true;
    activeAction.value = action;
    reasonOpen.value = true;
}

function submitReason(reason: string): void {
    if (isBulk.value) {
        router.post(
            bulkCommentsRoute.url(),
            { ids: selectedIds.value, action: activeAction.value, reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    selectedIds.value = [];
                    reasonOpen.value = false;
                },
            },
        );
        return;
    }

    if (activeCommentId.value !== null) {
        router.post(
            moderateCommentRoute.url(activeCommentId.value),
            { action: activeAction.value, reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    reasonOpen.value = false;
                },
            },
        );
    }
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.content.commentsTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <MessageSquareText class="size-5 text-primary" />
                <span>{{ t('admin.content.commentsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.commentsSubtitle') }}
            </p>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4"
        >
            <div class="flex flex-wrap gap-1.5">
                <Button
                    v-for="tab in ['all', 'hidden', 'spam', 'deleted']"
                    :key="tab"
                    size="sm"
                    :variant="filters.filter === tab ? 'default' : 'ghost'"
                    class="h-8 rounded-lg text-xs"
                    @click="applyFilter(tab)"
                >
                    {{ t(`admin.contentExtra.commentFilters.${tab}`) }}
                </Button>
            </div>
            <form
                @submit.prevent="applyFilter()"
                class="flex items-center gap-2"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        :placeholder="t('admin.common.search')"
                        class="h-8 rounded-lg border border-input bg-background pr-3 pl-8 text-xs"
                    />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="h-8 rounded-lg text-xs"
                    >{{ t('admin.common.filter') }}</Button
                >
            </form>
        </div>

        <div
            v-if="selectedIds.length > 0"
            class="flex items-center justify-between rounded-xl border border-primary/30 bg-primary-soft px-4 py-2.5 text-xs"
        >
            <span class="font-semibold text-accent-foreground">{{
                t('admin.contentExtra.selectedCount', {
                    count: selectedIds.length,
                })
            }}</span>
            <div class="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 rounded-lg text-xs"
                    @click="openBulk('hide')"
                    >{{ t('admin.content.bulkHide') }}</Button
                >
                <Button
                    size="sm"
                    variant="destructive"
                    class="h-7 rounded-lg text-xs"
                    @click="openBulk('soft_delete')"
                    >{{ t('admin.content.bulkDelete') }}</Button
                >
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="comments.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <div v-else class="space-y-2.5">
                <div
                    v-for="c in comments.data"
                    :key="c.id"
                    class="flex items-start gap-3 rounded-xl border border-border p-3.5 text-xs"
                >
                    <input
                        type="checkbox"
                        :checked="selectedIds.includes(c.id)"
                        class="mt-1 rounded border-input"
                        @change="toggleSelect(c.id)"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-foreground">{{
                                c.author?.username
                                    ? `@${c.author.username}`
                                    : t('admin.contentExtra.unknownAuthor')
                            }}</span>
                            <Badge variant="secondary" class="text-[10px]">{{
                                moderationStatusLabel(c.moderation_status)
                            }}</Badge>
                            <Badge
                                v-if="c.is_spam"
                                variant="destructive"
                                class="text-[10px]"
                                >{{ t('admin.contentExtra.badgeSpam') }}</Badge
                            >
                            <span class="text-[11px] text-muted-foreground">{{
                                t('admin.contentExtra.commentPostExcerpt', {
                                    id: c.post_id,
                                    excerpt: c.post_excerpt,
                                })
                            }}</span>
                        </div>
                        <p class="text-foreground">{{ c.content }}</p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatDateTime(c.created_at, locale) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-[11px]"
                            :title="
                                c.moderation_status === 'hidden'
                                    ? t(
                                          'admin.contentExtra.actions.restore_visibility',
                                      )
                                    : t('admin.contentExtra.actions.hide')
                            "
                            :aria-label="
                                c.moderation_status === 'hidden'
                                    ? t(
                                          'admin.contentExtra.actions.restore_visibility',
                                      )
                                    : t('admin.contentExtra.actions.hide')
                            "
                            @click="
                                openSingle(
                                    c.id,
                                    c.moderation_status === 'hidden'
                                        ? 'restore_visibility'
                                        : 'hide',
                                )
                            "
                        >
                            <EyeOff class="size-3.5" />
                        </Button>
                        <Button
                            v-if="c.deleted_at"
                            size="sm"
                            class="h-7 px-2 text-[11px]"
                            :title="
                                t('admin.contentExtra.actions.restore_deleted')
                            "
                            :aria-label="
                                t('admin.contentExtra.actions.restore_deleted')
                            "
                            @click="openSingle(c.id, 'restore_deleted')"
                        >
                            <RotateCcw class="size-3.5" />
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="destructive"
                            class="h-7 px-2 text-[11px]"
                            :title="t('admin.contentExtra.actions.soft_delete')"
                            :aria-label="
                                t('admin.contentExtra.actions.soft_delete')
                            "
                            @click="openSingle(c.id, 'soft_delete')"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                </div>
            </div>

            <AdminPagination
                :current-page="comments.current_page"
                :last-page="comments.last_page"
                :total="comments.total"
                :from="comments.from"
                :to="comments.to"
                :base-url="commentsRoute.url()"
                :query="filters"
            />
        </div>

        <ReasonDialog
            v-model:open="reasonOpen"
            :title="
                t('admin.contentExtra.actionDialogTitle', {
                    section: t('admin.content.commentsTitle'),
                    action: commentActionLabel(activeAction),
                })
            "
            :description="
                isBulk
                    ? t('admin.contentExtra.bulkCommentsCount', {
                          count: selectedIds.length,
                      })
                    : t('admin.contentExtra.commentNumber', {
                          id: activeCommentId ?? '',
                      })
            "
            @submit="submitReason"
        />
    </div>
</template>
