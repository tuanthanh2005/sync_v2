<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    RotateCcw,
    Search,
    ShieldBan,
    Star,
    UserCog,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/formatters';
import { communities as communitiesRoute } from '@/routes/admin/content';
import { moderate as moderateCommunityRoute } from '@/routes/admin/content/communities';

type CommunityRow = {
    id: number;
    name: string;
    slug: string;
    category: string;
    visibility: string;
    status: string;
    is_verified_badge: boolean;
    is_featured: boolean;
    allow_new_members: boolean;
    allow_posting: boolean;
    members_count: number;
    posts_count: number;
    owner: { id: number; name: string; username: string } | null;
    deleted_at: string | null;
};

const props = defineProps<{
    communities: {
        data: CommunityRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; status: string };
}>();

const { t, locale } = useI18n();
const searchInput = ref(props.filters.search);
const dialogOpen = ref(false);
const activeCommunity = ref<CommunityRow | null>(null);
const activeAction = ref('suspend');
const nextOwnerUsername = ref('');

function applyFilter(nextStatus = props.filters.status): void {
    router.get(
        communitiesRoute.url(),
        { status: nextStatus, search: searchInput.value.trim() || undefined },
        { preserveState: true },
    );
}

function openAction(community: CommunityRow, action: string): void {
    activeCommunity.value = community;
    activeAction.value = action;
    nextOwnerUsername.value = '';
    dialogOpen.value = true;
}

function submitAction(reason: string): void {
    if (!activeCommunity.value) {
        return;
    }
    router.post(
        moderateCommunityRoute.url(activeCommunity.value.id),
        {
            action: activeAction.value,
            reason,
            new_owner_username:
                activeAction.value === 'transfer_owner'
                    ? nextOwnerUsername.value
                    : undefined,
        },
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
        <Head :title="t('admin.content.communitiesTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Users class="size-5 text-primary" />
                <span>{{ t('admin.content.communitiesTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.communitiesSubtitle') }}
            </p>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4"
        >
            <div class="flex flex-wrap gap-1.5">
                <Button
                    v-for="st in [
                        'all',
                        'active',
                        'suspended',
                        'hidden',
                        'deleted',
                    ]"
                    :key="st"
                    size="sm"
                    :variant="filters.status === st ? 'default' : 'ghost'"
                    class="h-8 rounded-lg text-xs capitalize"
                    @click="applyFilter(st)"
                >
                    {{ st }}
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

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="communities.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <div v-else class="space-y-3">
                <div
                    v-for="c in communities.data"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="text-sm font-semibold text-foreground"
                                >{{ c.name }}</span
                            >
                            <BadgeCheck
                                v-if="c.is_verified_badge"
                                class="size-4 text-primary"
                            />
                            <Star
                                v-if="c.is_featured"
                                class="size-3.5 text-warning"
                            />
                            <Badge variant="secondary" class="text-[10px]">{{
                                c.status
                            }}</Badge>
                            <Badge
                                v-if="!c.allow_posting"
                                variant="outline"
                                class="text-[10px]"
                                >No Posting</Badge
                            >
                            <Badge
                                v-if="!c.allow_new_members"
                                variant="outline"
                                class="text-[10px]"
                                >Closed Joins</Badge
                            >
                        </div>
                        <p class="text-muted-foreground">
                            /{{ c.slug }} · Owner: @{{
                                c.owner?.username || 'unknown'
                            }}
                            ·
                            {{ formatNumber(c.members_count, locale) }} members
                            · {{ formatNumber(c.posts_count, locale) }} posts
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-[11px]"
                            @click="openAction(c, 'toggle_verified')"
                        >
                            <BadgeCheck class="size-3.5" />
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-[11px]"
                            @click="openAction(c, 'toggle_featured')"
                        >
                            <Star class="size-3.5" />
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-[11px]"
                            @click="openAction(c, 'toggle_posting')"
                        >
                            {{ t('admin.content.restrictPosting') }}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 px-2 text-[11px]"
                            @click="openAction(c, 'toggle_new_members')"
                        >
                            {{ t('admin.content.restrictJoining') }}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-7 gap-1 px-2 text-[11px]"
                            @click="openAction(c, 'transfer_owner')"
                        >
                            <UserCog class="size-3.5" />
                        </Button>
                        <Button
                            v-if="c.deleted_at"
                            size="sm"
                            class="h-7 gap-1 px-2 text-[11px]"
                            @click="openAction(c, 'restore')"
                        >
                            <RotateCcw class="size-3.5" />
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="destructive"
                            class="h-7 gap-1 px-2 text-[11px]"
                            @click="
                                openAction(
                                    c,
                                    c.status === 'active'
                                        ? 'suspend'
                                        : 'activate',
                                )
                            "
                        >
                            <ShieldBan class="size-3.5" />
                            <span>{{
                                c.status === 'active' ? 'Suspend' : 'Activate'
                            }}</span>
                        </Button>
                    </div>
                </div>
            </div>

            <AdminPagination
                :current-page="communities.current_page"
                :last-page="communities.last_page"
                :total="communities.total"
                :from="communities.from"
                :to="communities.to"
                :base-url="communitiesRoute.url()"
                :query="filters"
            />
        </div>

        <ReasonDialog
            v-model:open="dialogOpen"
            :title="`${t('admin.content.communitiesTitle')} · ${activeAction}`"
            :description="activeCommunity?.name || ''"
            @submit="submitAction"
        >
            <div
                v-if="activeAction === 'transfer_owner'"
                class="mt-2 space-y-1"
            >
                <Label class="text-xs"
                    >{{ t('admin.content.transferOwner') }} (@username)</Label
                >
                <Input
                    v-model="nextOwnerUsername"
                    placeholder="username"
                    class="h-9 text-xs"
                />
            </div>
        </ReasonDialog>
    </div>
</template>
