<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Search, ShieldCheck, Users } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import UserBadges from '@/components/social/UserBadges.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { formatDate, formatNumber } from '@/lib/formatters';
import {
    index as adminUsers,
    show as adminUserShow,
} from '@/routes/admin/users';

type UserRow = {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar: string | null;
    role: 'user' | 'moderator' | 'admin';
    role_label: string;
    rbac_role: string;
    status: 'active' | 'suspended' | 'banned';
    status_label: string;
    is_verified_badge: boolean;
    compact_badges?: Array<{
        type: string;
        label_key: string;
        description_key?: string;
        icon?: string;
        color?: string;
        member_number?: number | null;
        ai_disclosure?: string | null;
    }>;
    email_verified: boolean;
    two_factor_enabled: boolean;
    posts_count: number;
    comments_count: number;
    violations_count: number;
    last_login_at: string | null;
    created_at: string | null;
    deleted_at: string | null;
};

const props = defineProps<{
    users: {
        data: UserRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    counts: Record<string, number>;
    roles: Array<{
        id: number;
        slug: string;
        name: string;
        hierarchy_level: number;
    }>;
    filters: {
        filter: string;
        search: string;
        role: string;
        sort: string;
        dir: string;
    };
}>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const searchInput = ref(props.filters.search);
const roleFilter = ref(props.filters.role);

const tabs = [
    { key: 'all', labelKey: 'admin.nav.allUsers' },
    { key: 'active', labelKey: 'admin.nav.activeUsers' },
    { key: 'suspended', labelKey: 'admin.nav.suspendedUsers' },
    { key: 'banned', labelKey: 'admin.nav.bannedUsers' },
    { key: 'unverified', labelKey: 'admin.nav.unverifiedUsers' },
    { key: 'deleted', labelKey: 'admin.common.delete' },
];

function applyFilters(nextFilter = props.filters.filter): void {
    router.get(
        adminUsers.url(),
        {
            filter: nextFilter,
            search: searchInput.value.trim() || undefined,
            role: roleFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'banned') {
        return 'destructive';
    }
    if (status === 'suspended') {
        return 'outline';
    }
    return 'secondary';
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.users.title')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Users class="size-5 text-primary" aria-hidden="true" />
                <span>{{ t('admin.users.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.users.subtitle') }}
            </p>
        </div>

        <!-- Filter Tabs & Search -->
        <div
            class="space-y-3 rounded-xl border border-border bg-card p-3.5 sm:p-4"
        >
            <div
                class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto pb-0.5 sm:flex-wrap sm:overflow-visible"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :class="[
                        'inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors',
                        filters.filter === tab.key
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted/60 text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                    ]"
                    @click="applyFilters(tab.key)"
                >
                    <span>{{ t(tab.labelKey) }}</span>
                    <span
                        class="rounded-full bg-background/20 px-1.5 text-[10px]"
                    >
                        {{ formatNumber(counts[tab.key] ?? 0, locale) }}
                    </span>
                </button>
            </div>

            <form
                @submit.prevent="applyFilters()"
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
                        class="h-9 w-full rounded-lg border border-input bg-background pr-3 pl-9 text-xs focus:border-primary focus:outline-none"
                    />
                </div>
                <select
                    v-model="roleFilter"
                    class="h-9 rounded-lg border border-input bg-background px-3 text-xs"
                    @change="applyFilters()"
                >
                    <option value="">
                        {{ t('admin.users.role') }}: {{ t('admin.common.all') }}
                    </option>
                    <option v-for="r in roles" :key="r.slug" :value="r.slug">
                        {{ r.name }}
                    </option>
                </select>
                <Button type="submit" size="sm" class="h-9 rounded-lg text-xs">
                    {{ t('admin.common.filter') }}
                </Button>
            </form>
        </div>

        <!-- Users Table -->
        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="users.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <template v-else>
                <!-- Mobile Card List -->
                <div class="space-y-3 md:hidden">
                    <div
                        v-for="u in users.data"
                        :key="`card-${u.id}`"
                        class="space-y-3 rounded-xl border border-border bg-background/50 p-3.5 text-xs"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <Avatar class="size-10 shrink-0 rounded-full">
                                    <AvatarImage
                                        v-if="u.avatar"
                                        :src="u.avatar"
                                        :alt="u.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(u.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p
                                        class="flex items-center gap-1 font-semibold text-foreground"
                                    >
                                        <span class="truncate">{{
                                            u.name
                                        }}</span>
                                        <UserBadges
                                            :badges="u.compact_badges"
                                            mode="compact"
                                            size="xs"
                                            :max="2"
                                        />
                                        <ShieldCheck
                                            v-if="u.two_factor_enabled"
                                            class="size-3.5 shrink-0 text-success"
                                            title="2FA"
                                        />
                                    </p>
                                    <p
                                        class="truncate text-[11px] text-muted-foreground"
                                    >
                                        @{{ u.username }} · #{{ u.id }}
                                    </p>
                                    <p
                                        class="truncate text-[11px] text-muted-foreground"
                                    >
                                        {{ u.email }}
                                    </p>
                                </div>
                            </div>
                            <Badge
                                :variant="statusVariant(u.status)"
                                class="shrink-0 text-[10px]"
                            >
                                {{
                                    u.deleted_at
                                        ? t('admin.common.delete')
                                        : u.status_label
                                }}
                            </Badge>
                        </div>

                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2.5"
                        >
                            <div class="flex flex-wrap items-center gap-1.5">
                                <RoleBadge
                                    :role="u.role"
                                    :label="u.role_label"
                                />
                                <span
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ formatNumber(u.posts_count, locale) }} /
                                    {{ formatNumber(u.comments_count, locale) }}
                                </span>
                                <span
                                    v-if="u.violations_count > 0"
                                    class="font-semibold text-destructive"
                                >
                                    · {{ t('admin.users.violations') }}:
                                    {{
                                        formatNumber(u.violations_count, locale)
                                    }}
                                </span>
                            </div>

                            <Button
                                size="sm"
                                variant="outline"
                                class="min-h-9 gap-1.5 rounded-lg px-3 text-xs"
                                as-child
                            >
                                <Link :href="adminUserShow(u.id)">
                                    <Eye class="size-3.5" />
                                    <span>{{ t('admin.common.view') }}</span>
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Desktop Data Table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.id') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.role') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.common.status') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.posts') }} /
                                    {{ t('admin.users.comments') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.violations') }}
                                </th>
                                <th class="py-2.5 pr-3 font-medium">
                                    {{ t('admin.users.lastLogin') }}
                                </th>
                                <th class="py-2.5 text-right font-medium">
                                    {{ t('admin.common.actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="u in users.data"
                                :key="u.id"
                                class="hover:bg-surface-hover/50"
                            >
                                <td
                                    class="py-3 pr-3 font-mono text-muted-foreground"
                                >
                                    #{{ u.id }}
                                </td>
                                <td class="py-3 pr-3">
                                    <div class="flex items-center gap-2.5">
                                        <Avatar class="size-8 rounded-full">
                                            <AvatarImage
                                                v-if="u.avatar"
                                                :src="u.avatar"
                                                :alt="u.name"
                                            />
                                            <AvatarFallback
                                                class="bg-primary-soft text-[11px] font-semibold text-accent-foreground"
                                            >
                                                {{ getInitials(u.name) }}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div class="min-w-0">
                                            <p
                                                class="flex items-center gap-1 font-semibold text-foreground"
                                            >
                                                <span class="truncate">{{
                                                    u.name
                                                }}</span>
                                                <UserBadges
                                                    :badges="u.compact_badges"
                                                    mode="compact"
                                                    size="xs"
                                                    :max="2"
                                                />
                                                <ShieldCheck
                                                    v-if="u.two_factor_enabled"
                                                    class="size-3.5 shrink-0 text-success"
                                                    title="2FA"
                                                />
                                            </p>
                                            <p
                                                class="truncate text-[11px] text-muted-foreground"
                                            >
                                                @{{ u.username }} ·
                                                {{ u.email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-3">
                                    <RoleBadge
                                        :role="u.role"
                                        :label="u.role_label"
                                    />
                                </td>
                                <td class="py-3 pr-3">
                                    <div class="flex flex-col gap-1">
                                        <Badge
                                            :variant="statusVariant(u.status)"
                                            class="w-fit text-[11px]"
                                        >
                                            {{
                                                u.deleted_at
                                                    ? t('admin.common.delete')
                                                    : u.status_label
                                            }}
                                        </Badge>
                                        <span
                                            :class="[
                                                'text-[10px]',
                                                u.email_verified
                                                    ? 'text-success'
                                                    : 'text-warning',
                                            ]"
                                        >
                                            {{
                                                u.email_verified
                                                    ? t('admin.users.verified')
                                                    : t(
                                                          'admin.users.unverified',
                                                      )
                                            }}
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="py-3 pr-3 font-mono text-muted-foreground"
                                >
                                    {{ formatNumber(u.posts_count, locale) }} /
                                    {{ formatNumber(u.comments_count, locale) }}
                                </td>
                                <td class="py-3 pr-3">
                                    <span
                                        :class="
                                            u.violations_count > 0
                                                ? 'font-semibold text-destructive'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            formatNumber(
                                                u.violations_count,
                                                locale,
                                            )
                                        }}
                                    </span>
                                </td>
                                <td class="py-3 pr-3 text-muted-foreground">
                                    {{
                                        formatDate(
                                            u.last_login_at || u.created_at,
                                            locale,
                                        )
                                    }}
                                </td>
                                <td class="py-3 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 gap-1 rounded-lg px-2.5 text-xs"
                                        as-child
                                    >
                                        <Link :href="adminUserShow(u.id)">
                                            <Eye class="size-3.5" />
                                            <span>{{
                                                t('admin.common.view')
                                            }}</span>
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <AdminPagination
                :current-page="users.current_page"
                :last-page="users.last_page"
                :total="users.total"
                :from="users.from"
                :to="users.to"
                :base-url="adminUsers.url()"
                :query="{
                    filter: filters.filter,
                    search: filters.search,
                    role: filters.role,
                }"
            />
        </div>
    </div>
</template>
