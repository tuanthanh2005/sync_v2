<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    EyeOff,
    History,
    Lock,
    Newspaper,
    Pin,
    RotateCcw,
    Search,
    ShieldAlert,
    Trash2,
    Unlock,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';
import { posts as postsRoute } from '@/routes/admin/content';
import { moderate as moderatePostRoute } from '@/routes/admin/content/posts';

type PostRow = {
    id: number;
    title: string | null;
    content: string;
    excerpt: string;
    category: string;
    visibility: string;
    moderation_status: string;
    moderation_reason: string | null;
    is_pinned: boolean;
    comments_locked: boolean;
    content_warning: string | null;
    is_spam: boolean;
    spam_risk_score?: number;
    reactions_count: number;
    comments_count: number;
    ad_campaigns_count: number;
    author: { id: number; name: string; username: string } | null;
    community: { id: number; name: string; slug: string } | null;
    media: Array<{ id: number; url: string }>;
    edit_histories: Array<{
        id: number;
        previous_title: string | null;
        previous_content: string;
        editor_username: string | null;
        created_at: string;
    }>;
    created_at: string | null;
    deleted_at: string | null;
};

const props = defineProps<{
    posts: {
        data: PostRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    communities: Array<{ id: number; name: string }>;
    filters: { filter: string; search: string; community_id: number | null };
}>();

const { t, locale } = useI18n();
const searchInput = ref(props.filters.search);
const communityId = ref(
    props.filters.community_id ? String(props.filters.community_id) : '',
);

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

function postActionLabel(action: string): string {
    const map: Record<string, string> = {
        approve: 'admin.contentExtra.actions.approve',
        reject: 'admin.contentExtra.actions.reject',
        hide: 'admin.contentExtra.actions.hide',
        restore_visibility: 'admin.contentExtra.actions.restore_visibility',
        lock_comments: 'admin.contentExtra.actions.lock_comments',
        unlock_comments: 'admin.contentExtra.actions.unlock_comments',
        pin: 'admin.contentExtra.actions.pin',
        unpin: 'admin.contentExtra.actions.unpin',
        content_warning: 'admin.contentExtra.actions.content_warning',
        restore_deleted: 'admin.contentExtra.actions.restore_deleted',
        soft_delete: 'admin.contentExtra.actions.soft_delete',
    };
    const key = map[action];

    return key ? t(key) : action;
}

const reasonOpen = ref(false);
const activePost = ref<PostRow | null>(null);
const activeAction = ref('hide');
const warningLabel = ref('');
const historyPost = ref<PostRow | null>(null);

function applyFilter(nextFilter = props.filters.filter): void {
    router.get(
        postsRoute.url(),
        {
            filter: nextFilter,
            search: searchInput.value.trim() || undefined,
            community_id: communityId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function openAction(post: PostRow, action: string): void {
    activePost.value = post;
    activeAction.value = action;
    warningLabel.value = post.content_warning ?? '';
    reasonOpen.value = true;
}

function submitAction(reason: string): void {
    if (!activePost.value) {
        return;
    }

    router.post(
        moderatePostRoute.url(activePost.value.id),
        {
            action: activeAction.value,
            reason,
            content_warning:
                activeAction.value === 'content_warning'
                    ? warningLabel.value
                    : undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                reasonOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.content.postsTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Newspaper class="size-5 text-primary" />
                <span>{{ t('admin.content.postsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.postsSubtitle') }}
            </p>
        </div>

        <div
            class="space-y-3 rounded-xl border border-border bg-card p-3.5 sm:p-4"
        >
            <div
                class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto pb-0.5 sm:flex-wrap sm:overflow-visible"
            >
                <Button
                    v-for="tab in [
                        'all',
                        'pending',
                        'rejected',
                        'hidden',
                        'spam',
                        'sponsored',
                        'deleted',
                    ]"
                    :key="tab"
                    size="sm"
                    :variant="filters.filter === tab ? 'default' : 'ghost'"
                    class="min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap sm:min-h-8"
                    @click="applyFilter(tab)"
                >
                    {{ t(`admin.contentExtra.postFilters.${tab}`) }}
                </Button>
            </div>
            <form
                @submit.prevent="applyFilter()"
                class="flex flex-wrap items-center gap-2"
            >
                <div class="relative min-w-[220px] flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        :placeholder="t('admin.common.search')"
                        class="h-9 w-full rounded-lg border border-input bg-background pr-3 pl-9 text-xs"
                    />
                </div>
                <select
                    v-model="communityId"
                    class="h-9 rounded-lg border border-input bg-background px-3 text-xs"
                    @change="applyFilter()"
                >
                    <option value="">
                        {{ t('admin.nav.communities') }}:
                        {{ t('admin.common.all') }}
                    </option>
                    <option v-for="c in communities" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
                <Button
                    type="submit"
                    size="sm"
                    class="h-9 rounded-lg text-xs"
                    >{{ t('admin.common.filter') }}</Button
                >
            </form>
        </div>

        <div class="space-y-3 rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="posts.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <div v-else class="space-y-3">
                <div
                    v-for="post in posts.data"
                    :key="post.id"
                    class="rounded-xl border border-border p-4 text-xs"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-muted-foreground"
                                    >#{{ post.id }}</span
                                >
                                <span class="font-semibold text-foreground">{{
                                    post.author?.username
                                        ? `@${post.author.username}`
                                        : t('admin.contentExtra.unknownAuthor')
                                }}</span>
                                <Badge
                                    variant="secondary"
                                    class="text-[10px]"
                                    >{{
                                        moderationStatusLabel(
                                            post.moderation_status,
                                        )
                                    }}</Badge
                                >
                                <Badge
                                    v-if="post.is_pinned"
                                    variant="outline"
                                    class="text-[10px]"
                                    >{{
                                        t('admin.contentExtra.badgePinned')
                                    }}</Badge
                                >
                                <Badge
                                    v-if="post.comments_locked"
                                    variant="outline"
                                    class="text-[10px]"
                                    >{{
                                        t('admin.contentExtra.badgeLocked')
                                    }}</Badge
                                >
                                <Badge
                                    v-if="post.is_spam"
                                    variant="destructive"
                                    class="text-[10px]"
                                    >{{
                                        t('admin.contentExtra.badgeSpam')
                                    }}</Badge
                                >
                                <Badge
                                    v-if="(post.spam_risk_score ?? 0) >= 30"
                                    variant="outline"
                                    class="text-[10px]"
                                    >{{
                                        t('admin.contentExtra.riskScoreBadge', {
                                            score: post.spam_risk_score ?? 0,
                                        })
                                    }}</Badge
                                >
                                <Badge
                                    v-if="post.ad_campaigns_count > 0"
                                    variant="outline"
                                    class="text-[10px]"
                                    >{{
                                        t('admin.contentExtra.badgeSponsored', {
                                            count: post.ad_campaigns_count,
                                        })
                                    }}</Badge
                                >
                                <Badge
                                    v-if="post.content_warning"
                                    variant="destructive"
                                    class="text-[10px]"
                                    >{{
                                        t(
                                            'admin.contentExtra.badgeContentWarning',
                                            {
                                                label: post.content_warning,
                                            },
                                        )
                                    }}</Badge
                                >
                            </div>
                            <p
                                v-if="post.title"
                                class="text-sm font-semibold text-foreground"
                            >
                                {{ post.title }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ post.excerpt }}
                            </p>
                            <div
                                v-if="post.media.length > 0"
                                class="flex flex-wrap gap-2 pt-1"
                            >
                                <img
                                    v-for="img in post.media.slice(0, 4)"
                                    :key="img.id"
                                    :src="img.url"
                                    alt=""
                                    class="size-14 rounded-lg border border-border object-cover"
                                />
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                {{ formatDateTime(post.created_at, locale) }} ·
                                ❤️ {{ post.reactions_count }} · 💬
                                {{ post.comments_count }}
                                <span v-if="post.community">
                                    · {{ post.community.name }}</span
                                >
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <Button
                                v-if="post.edit_histories.length > 0"
                                size="sm"
                                variant="ghost"
                                class="h-7 gap-1 px-2 text-[11px]"
                                :title="t('admin.content.editHistory')"
                                :aria-label="t('admin.content.editHistory')"
                                @click="historyPost = post"
                            >
                                <History class="size-3.5" />
                                <span>{{ post.edit_histories.length }}</span>
                            </Button>
                            <Button
                                v-if="post.moderation_status !== 'approved' && !post.deleted_at"
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="openAction(post, 'approve')"
                            >
                                <CheckCircle2 class="size-3.5 text-success" />
                                <span>{{ t('admin.contentExtra.actions.approve') }}</span>
                            </Button>
                            <Button
                                v-if="post.moderation_status !== 'rejected' && !post.deleted_at"
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="openAction(post, 'reject')"
                            >
                                <XCircle class="size-3.5 text-destructive" />
                                <span>{{ t('admin.contentExtra.actions.reject') }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="
                                    openAction(
                                        post,
                                        post.moderation_status === 'hidden'
                                            ? 'restore_visibility'
                                            : 'hide',
                                    )
                                "
                            >
                                <EyeOff class="size-3.5" />
                                <span>{{
                                    post.moderation_status === 'hidden'
                                        ? t('admin.common.unhide')
                                        : t('admin.common.hide')
                                }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                :title="
                                    post.comments_locked
                                        ? t(
                                              'admin.contentExtra.actions.unlock_comments',
                                          )
                                        : t(
                                              'admin.contentExtra.actions.lock_comments',
                                          )
                                "
                                :aria-label="
                                    post.comments_locked
                                        ? t(
                                              'admin.contentExtra.actions.unlock_comments',
                                          )
                                        : t(
                                              'admin.contentExtra.actions.lock_comments',
                                          )
                                "
                                @click="
                                    openAction(
                                        post,
                                        post.comments_locked
                                            ? 'unlock_comments'
                                            : 'lock_comments',
                                    )
                                "
                            >
                                <Unlock
                                    v-if="post.comments_locked"
                                    class="size-3.5"
                                />
                                <Lock v-else class="size-3.5" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                :title="
                                    post.is_pinned
                                        ? t('admin.contentExtra.actions.unpin')
                                        : t('admin.contentExtra.actions.pin')
                                "
                                :aria-label="
                                    post.is_pinned
                                        ? t('admin.contentExtra.actions.unpin')
                                        : t('admin.contentExtra.actions.pin')
                                "
                                @click="
                                    openAction(
                                        post,
                                        post.is_pinned ? 'unpin' : 'pin',
                                    )
                                "
                            >
                                <Pin class="size-3.5" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 gap-1 px-2 text-[11px]"
                                :title="
                                    t(
                                        'admin.contentExtra.actions.content_warning',
                                    )
                                "
                                :aria-label="
                                    t(
                                        'admin.contentExtra.actions.content_warning',
                                    )
                                "
                                @click="openAction(post, 'content_warning')"
                            >
                                <ShieldAlert class="size-3.5 text-warning" />
                            </Button>
                            <Button
                                v-if="post.deleted_at"
                                size="sm"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="openAction(post, 'restore_deleted')"
                            >
                                <RotateCcw class="size-3.5" />
                                <span>{{ t('admin.common.restore') }}</span>
                            </Button>
                            <Button
                                v-else
                                size="sm"
                                variant="destructive"
                                class="h-7 gap-1 px-2 text-[11px]"
                                :title="
                                    t('admin.contentExtra.actions.soft_delete')
                                "
                                :aria-label="
                                    t('admin.contentExtra.actions.soft_delete')
                                "
                                @click="openAction(post, 'soft_delete')"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <AdminPagination
                :current-page="posts.current_page"
                :last-page="posts.last_page"
                :total="posts.total"
                :from="posts.from"
                :to="posts.to"
                :base-url="postsRoute.url()"
                :query="{
                    filter: filters.filter,
                    search: filters.search,
                    community_id: filters.community_id,
                }"
            />
        </div>

        <ReasonDialog
            v-model:open="reasonOpen"
            :title="
                t('admin.contentExtra.actionDialogTitle', {
                    section: t('admin.content.postsTitle'),
                    action: postActionLabel(activeAction),
                })
            "
            :description="
                activePost
                    ? `#${activePost.id} (${activePost.author?.username ? `@${activePost.author.username}` : t('admin.contentExtra.unknownAuthor')})`
                    : ''
            "
            :consequence="
                activePost &&
                activePost.ad_campaigns_count > 0 &&
                (activeAction === 'hide' || activeAction === 'soft_delete')
                    ? t('admin.contentExtra.hideSponsoredPostConsequence')
                    : undefined
            "
            @submit="submitAction"
        >
            <div
                v-if="activeAction === 'content_warning'"
                class="mt-2 space-y-1"
            >
                <Label class="text-xs">{{
                    t('admin.content.contentWarning')
                }}</Label>
                <Input
                    v-model="warningLabel"
                    :placeholder="
                        t('admin.contentExtra.contentWarningPlaceholder')
                    "
                    class="h-9 text-xs"
                />
            </div>
        </ReasonDialog>

        <Dialog
            :open="historyPost !== null"
            @update:open="(v) => !v && (historyPost = null)"
        >
            <DialogContent class="max-w-lg rounded-2xl">
                <DialogHeader>
                    <DialogTitle class="text-base">{{
                        t('admin.contentExtra.editHistoryTitle', {
                            id: historyPost?.id ?? '',
                        })
                    }}</DialogTitle>
                </DialogHeader>
                <div
                    class="sv-scrollbar max-h-80 space-y-2 overflow-y-auto overscroll-contain text-xs"
                >
                    <div
                        v-for="h in historyPost?.edit_histories || []"
                        :key="h.id"
                        class="rounded-lg border border-border p-3"
                    >
                        <p class="font-semibold text-foreground">
                            {{ h.previous_title || '—' }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            {{ h.previous_content }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{
                                h.editor_username
                                    ? `@${h.editor_username}`
                                    : t('admin.contentExtra.systemActor')
                            }}
                            ·
                            {{ formatDateTime(h.created_at, locale) }}
                        </p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
