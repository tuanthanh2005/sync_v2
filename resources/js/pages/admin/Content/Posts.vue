<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
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
                        'hidden',
                        'pending',
                        'spam',
                        'sponsored',
                        'deleted',
                    ]"
                    :key="tab"
                    size="sm"
                    :variant="filters.filter === tab ? 'default' : 'ghost'"
                    class="min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap capitalize sm:min-h-8"
                    @click="applyFilter(tab)"
                >
                    {{ tab }}
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
                                <span class="font-semibold text-foreground"
                                    >@{{
                                        post.author?.username || 'unknown'
                                    }}</span
                                >
                                <Badge
                                    variant="secondary"
                                    class="text-[10px]"
                                    >{{ post.moderation_status }}</Badge
                                >
                                <Badge
                                    v-if="post.is_pinned"
                                    variant="outline"
                                    class="text-[10px]"
                                    >Pinned</Badge
                                >
                                <Badge
                                    v-if="post.comments_locked"
                                    variant="outline"
                                    class="text-[10px]"
                                    >Locked</Badge
                                >
                                <Badge
                                    v-if="post.is_spam"
                                    variant="destructive"
                                    class="text-[10px]"
                                    >Spam</Badge
                                >
                                <Badge
                                    v-if="post.ad_campaigns_count > 0"
                                    variant="outline"
                                    class="text-[10px]"
                                    >Sponsored ({{
                                        post.ad_campaigns_count
                                    }})</Badge
                                >
                                <Badge
                                    v-if="post.content_warning"
                                    variant="destructive"
                                    class="text-[10px]"
                                    >CW: {{ post.content_warning }}</Badge
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
                                @click="historyPost = post"
                            >
                                <History class="size-3.5" />
                                <span>{{ post.edit_histories.length }}</span>
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
            :title="`${t('admin.content.postsTitle')} · ${activeAction}`"
            :description="
                activePost
                    ? `#${activePost.id} (@${activePost.author?.username})`
                    : ''
            "
            :consequence="
                activePost &&
                activePost.ad_campaigns_count > 0 &&
                (activeAction === 'hide' || activeAction === 'soft_delete')
                    ? 'Active ad campaigns on this post will be stopped and unspent budget refunded.'
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
                    placeholder="Sensitive topic / spoiler / graphic"
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
                    <DialogTitle class="text-base"
                        >{{ t('admin.content.editHistory') }} #{{
                            historyPost?.id
                        }}</DialogTitle
                    >
                </DialogHeader>
                <div class="max-h-80 space-y-2 overflow-y-auto text-xs">
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
                            @{{ h.editor_username || 'system' }} ·
                            {{ formatDateTime(h.created_at, locale) }}
                        </p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
