<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Award,
    Bot,
    Crown,
    Eye,
    EyeOff,
    Search,
    ShieldCheck,
    Star,
    UserCheck,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import InputError from '@/components/InputError.vue';
import UserBadges from '@/components/social/UserBadges.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime, formatNumber } from '@/lib/formatters';
import {
    grant as adminBadgesGrant,
    index as adminBadgesIndex,
    revoke as adminBadgesRevoke,
    visibility as adminBadgesVisibility,
} from '@/routes/admin/badges';
import { show as adminUserShow } from '@/routes/admin/users';

type AdminBadgeRow = {
    id: number;
    badge_type: string;
    label: string;
    color: string;
    icon: string;
    status: string;
    is_active: boolean;
    is_public: boolean;
    is_effectively_visible: boolean;
    effective_eligible: boolean;
    grant_source: string;
    grant_reason: string | null;
    granted_at: string;
    granted_by_username: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    revoked_by_username: string | null;
    revoke_reason: string | null;
    user: {
        id: number;
        name: string;
        username: string;
        email: string;
        avatar: string | null;
        role: 'user' | 'moderator' | 'admin';
        rbac_role: string;
        role_label: string;
        status: string;
        is_ai_agent: boolean;
    } | null;
};

type GrantOption = {
    value: string;
    label_key: string;
    color: string;
    icon: string;
};

type BadgeAuditItem = {
    id: number;
    action: string;
    actor_username: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
};

const props = defineProps<{
    badges: {
        data: AdminBadgeRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    stats: {
        active_official_account: number;
        active_official_staff: number;
        active_moderator: number;
        active_early_member: number;
        active_premium: number;
        ai_accounts: number;
    };
    grantableOptions: GrantOption[];
    auditLogs: BadgeAuditItem[];
    canManage: boolean;
    filters: {
        search: string;
        badge_type: string;
        status: string;
        role: string;
    };
}>();

const { t, te, locale } = useI18n();
const { getInitials } = useInitials();

const search = ref(props.filters.search);
const badgeTypeFilter = ref(props.filters.badge_type || 'all');
const statusFilter = ref(props.filters.status || 'all');
const roleFilter = ref(props.filters.role || 'all');

function applyFilters(): void {
    router.get(
        adminBadgesIndex.url({
            query: {
                search: search.value.trim() || undefined,
                badge_type:
                    badgeTypeFilter.value !== 'all'
                        ? badgeTypeFilter.value
                        : undefined,
                status:
                    statusFilter.value !== 'all'
                        ? statusFilter.value
                        : undefined,
                role: roleFilter.value !== 'all' ? roleFilter.value : undefined,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true },
    );
}

const grantForm = useForm<{
    username: string;
    badge_type: string;
    reason: string;
    is_public: boolean;
    expires_at: string;
    user?: string;
}>({
    username: '',
    badge_type: props.grantableOptions[0]?.value ?? 'official_account',
    reason: '',
    is_public: true,
    expires_at: '',
});
const grantConfirmOpen = ref(false);

function requestGrantConfirm(): void {
    if (!grantForm.username.trim() || grantForm.reason.trim().length < 3) {
        submitGrant();
        return;
    }
    grantConfirmOpen.value = true;
}

function submitGrant(): void {
    grantForm.post(adminBadgesGrant.url(), {
        preserveScroll: true,
        onSuccess: () => {
            grantConfirmOpen.value = false;
            grantForm.reset('username', 'reason', 'expires_at');
        },
        onError: () => {
            grantConfirmOpen.value = false;
        },
    });
}

const revokeDialogOpen = ref(false);
const revokeTarget = ref<AdminBadgeRow | null>(null);
const revokeProcessing = ref(false);

function openRevokeDialog(row: AdminBadgeRow): void {
    revokeTarget.value = row;
    revokeDialogOpen.value = true;
}

function confirmRevoke(reason: string): void {
    if (!revokeTarget.value) {
        return;
    }
    revokeProcessing.value = true;
    router.post(
        adminBadgesRevoke.url(revokeTarget.value.id),
        { reason },
        {
            preserveScroll: true,
            onFinish: () => {
                revokeProcessing.value = false;
                revokeDialogOpen.value = false;
                revokeTarget.value = null;
            },
        },
    );
}

function toggleVisibility(row: AdminBadgeRow): void {
    router.post(
        adminBadgesVisibility.url(row.id),
        { is_public: !row.is_public },
        { preserveScroll: true },
    );
}

function optionLabel(opt: GrantOption): string {
    return te(opt.label_key) ? t(opt.label_key) : opt.value;
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.badges.title')" />

        <!-- Page Header -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <Award class="size-5 text-primary" />
                    <span>{{ t('admin.badges.title') }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.badges.subtitle') }}
                </p>
            </div>
            <Badge
                :variant="canManage ? 'default' : 'secondary'"
                class="text-xs"
            >
                {{
                    canManage
                        ? t('admin.badges.superAdminMode')
                        : t('admin.badges.readOnlyNotice')
                }}
            </Badge>
        </div>

        <!-- KPI Metrics Grid -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <AdminStatCard
                :label="t('badges.officialAccount.label')"
                :value="formatNumber(stats.active_official_account, locale)"
                :icon="UserCheck"
            />
            <AdminStatCard
                :label="t('badges.officialStaff.label')"
                :value="formatNumber(stats.active_official_staff, locale)"
                :icon="Crown"
            />
            <AdminStatCard
                :label="t('badges.moderator.label')"
                :value="formatNumber(stats.active_moderator, locale)"
                :icon="ShieldCheck"
            />
            <AdminStatCard
                :label="t('badges.earlyMember.label')"
                :value="formatNumber(stats.active_early_member, locale)"
                :icon="Star"
            />
            <AdminStatCard
                :label="t('badges.premiumMember.label')"
                :value="formatNumber(stats.active_premium, locale)"
                :icon="Award"
            />
            <AdminStatCard
                :label="t('badges.aiAccount.label')"
                :value="formatNumber(stats.ai_accounts, locale)"
                :icon="Bot"
            />
        </div>

        <!-- Super Admin Grant Panel -->
        <section
            v-if="canManage"
            class="rounded-xl border border-border bg-card p-5"
        >
            <div class="mb-4">
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.badges.grantTitle') }}
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ t('admin.badges.grantSubtitle') }}
                </p>
            </div>

            <form
                @submit.prevent="requestGrantConfirm"
                class="grid gap-3 md:grid-cols-2 lg:grid-cols-4"
            >
                <div class="space-y-1">
                    <Label class="text-xs">{{
                        t('admin.badges.targetUsername')
                    }}</Label>
                    <Input
                        v-model="grantForm.username"
                        class="h-9 text-xs"
                        :placeholder="t('admin.badges.usernamePlaceholder')"
                    />
                    <InputError :message="grantForm.errors.username" />
                    <InputError :message="grantForm.errors.user" />
                </div>

                <div class="space-y-1">
                    <Label class="text-xs">{{
                        t('admin.badges.badgeType')
                    }}</Label>
                    <select
                        v-model="grantForm.badge_type"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option
                            v-for="opt in grantableOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ optionLabel(opt) }}
                        </option>
                    </select>
                    <InputError :message="grantForm.errors.badge_type" />
                </div>

                <div class="space-y-1">
                    <Label class="text-xs">{{
                        t('admin.badges.reasonLabel')
                    }}</Label>
                    <Input
                        v-model="grantForm.reason"
                        class="h-9 text-xs"
                        :placeholder="t('admin.badges.reasonPlaceholder')"
                    />
                    <InputError :message="grantForm.errors.reason" />
                </div>

                <div class="space-y-1">
                    <Label class="text-xs">{{
                        t('admin.badges.expiresAtLabel')
                    }}</Label>
                    <Input
                        v-model="grantForm.expires_at"
                        type="datetime-local"
                        class="h-9 text-xs"
                    />
                    <InputError :message="grantForm.errors.expires_at" />
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-3 pt-1 md:col-span-2 lg:col-span-4"
                >
                    <label
                        class="inline-flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <input
                            v-model="grantForm.is_public"
                            type="checkbox"
                            class="rounded border-input"
                        />
                        <span>{{ t('admin.badges.publicVisibleToggle') }}</span>
                    </label>

                    <Button
                        type="submit"
                        size="sm"
                        class="rounded-lg text-xs"
                        :disabled="grantForm.processing"
                    >
                        {{ t('admin.badges.grantButton') }}
                    </Button>
                </div>
            </form>
        </section>

        <!-- Search & Filters Bar -->
        <div
            class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3.5"
        >
            <div class="relative min-w-[200px] flex-1">
                <Search
                    class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('admin.badges.searchPlaceholder')"
                    class="h-9 w-full rounded-lg border border-input bg-background ps-9 pe-3 text-xs outline-none focus:border-primary"
                    @keyup.enter="applyFilters"
                />
            </div>

            <select
                v-model="badgeTypeFilter"
                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                @change="applyFilters"
            >
                <option value="all">{{ t('admin.badges.allTypes') }}</option>
                <option value="official_account">
                    {{ t('badges.officialAccount.label') }}
                </option>
                <option value="official_staff">
                    {{ t('badges.officialStaff.label') }}
                </option>
                <option value="moderator">
                    {{ t('badges.moderator.label') }}
                </option>
                <option value="early_member">
                    {{ t('badges.earlyMember.label') }}
                </option>
                <option value="founding_member">
                    {{ t('growth.badges.foundingMember') }}
                </option>
            </select>

            <select
                v-model="statusFilter"
                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                @change="applyFilters"
            >
                <option value="all">{{ t('admin.badges.allStatuses') }}</option>
                <option value="active">{{ t('admin.badges.statusActive') }}</option>
                <option value="revoked">{{ t('admin.badges.statusRevoked') }}</option>
                <option value="hidden">{{ t('admin.badges.statusHidden') }}</option>
            </select>

            <select
                v-model="roleFilter"
                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                @change="applyFilters"
            >
                <option value="all">{{ t('admin.badges.allRoles') }}</option>
                <option value="super_admin">
                    {{ t('admin.badges.roleSuperAdmin') }}
                </option>
                <option value="admin">
                    {{ t('admin.badges.roleAdmin') }}
                </option>
                <option value="moderator">
                    {{ t('admin.badges.roleModerator') }}
                </option>
                <option value="user">
                    {{ t('admin.badges.roleMember') }}
                </option>
            </select>

            <Button size="sm" class="rounded-lg text-xs" @click="applyFilters">
                {{ t('admin.common.search') }}
            </Button>
        </div>

        <!-- Active & Historical Badges List -->
        <div class="rounded-xl border border-border bg-card p-5">
            <EmptyState
                v-if="badges.data.length === 0"
                :title="t('admin.badges.emptyTitle')"
                :description="t('admin.badges.emptyDescription')"
                :icon="Award"
            />

            <div v-else>
                <!-- Mobile Cards -->
                <div class="space-y-3 md:hidden">
                    <div
                        v-for="row in badges.data"
                        :key="row.id"
                        class="space-y-2.5 rounded-xl border border-border bg-background/60 p-3.5"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <Avatar class="size-9 shrink-0 rounded-full">
                                    <AvatarImage
                                        v-if="row.user?.avatar"
                                        :src="row.user.avatar"
                                        :alt="row.user.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(row.user?.name || 'U') }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-semibold text-foreground"
                                    >
                                        {{ row.user?.name || '-' }}
                                    </p>
                                    <p
                                        class="truncate text-[11px] text-muted-foreground"
                                    >
                                        @{{ row.user?.username || '-' }}
                                    </p>
                                </div>
                            </div>

                            <Badge
                                :variant="
                                    row.is_active ? 'default' : 'destructive'
                                "
                                class="text-[10px]"
                            >
                                {{
                                    row.is_active
                                        ? row.is_public
                                            ? t('admin.badges.statusActive')
                                            : t('admin.badges.statusHidden')
                                        : t('admin.badges.statusRevoked')
                                }}
                            </Badge>
                        </div>

                        <UserBadges
                            :badges="[
                                {
                                    type: row.badge_type,
                                    label_key:
                                        row.badge_type === 'official_account'
                                            ? 'badges.officialAccount.label'
                                            : row.badge_type === 'official_staff'
                                              ? 'badges.officialStaff.label'
                                              : row.badge_type === 'moderator'
                                                ? 'badges.moderator.label'
                                                : `badges.${row.badge_type}.label`,
                                    color: row.color,
                                    icon: row.icon,
                                },
                            ]"
                            mode="showcase"
                            size="sm"
                        />

                        <p
                            v-if="row.grant_reason"
                            class="text-xs text-muted-foreground"
                        >
                            {{ row.grant_reason }}
                        </p>
                        <p
                            v-if="row.revoke_reason"
                            class="text-xs text-destructive"
                        >
                            {{ row.revoke_reason }}
                        </p>

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <Button
                                v-if="row.user"
                                variant="outline"
                                size="sm"
                                class="h-7 rounded-lg text-[11px]"
                                as-child
                            >
                                <Link :href="adminUserShow.url(row.user.id)">
                                    {{ t('admin.common.view') }}
                                </Link>
                            </Button>
                            <Button
                                v-if="canManage && row.is_active"
                                variant="outline"
                                size="sm"
                                class="h-7 rounded-lg text-[11px]"
                                @click="toggleVisibility(row)"
                            >
                                {{
                                    row.is_public
                                        ? t('admin.badges.hidePublic')
                                        : t('admin.badges.showPublic')
                                }}
                            </Button>
                            <Button
                                v-if="canManage && row.is_active"
                                variant="destructive"
                                size="sm"
                                class="h-7 rounded-lg text-[11px]"
                                @click="openRevokeDialog(row)"
                            >
                                {{ t('admin.badges.revokeButton') }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Desktop Table -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr
                                class="border-b border-border text-start text-muted-foreground"
                            >
                                <th class="pb-3 font-medium">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="pb-3 font-medium">
                                    {{ t('admin.users.role') }}
                                </th>
                                <th class="pb-3 font-medium">
                                    {{ t('admin.badges.badgeType') }}
                                </th>
                                <th class="pb-3 font-medium">
                                    {{ t('admin.common.status') }}
                                </th>
                                <th class="pb-3 font-medium">
                                    {{ t('admin.badges.reasonAudit') }}
                                </th>
                                <th class="pb-3 font-medium">
                                    {{ t('admin.badges.timestamps') }}
                                </th>
                                <th class="pb-3 text-end font-medium">
                                    {{ t('admin.common.actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr
                                v-for="row in badges.data"
                                :key="row.id"
                                class="hover:bg-surface-hover/50"
                            >
                                <td class="py-3 pe-3">
                                    <div
                                        v-if="row.user"
                                        class="flex items-center gap-2.5"
                                    >
                                        <Avatar
                                            class="size-8 shrink-0 rounded-full"
                                        >
                                            <AvatarImage
                                                v-if="row.user.avatar"
                                                :src="row.user.avatar"
                                                :alt="row.user.name"
                                            />
                                            <AvatarFallback
                                                class="bg-primary-soft text-[11px] font-semibold text-accent-foreground"
                                            >
                                                {{ getInitials(row.user.name) }}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div class="min-w-0">
                                            <Link
                                                :href="
                                                    adminUserShow.url(
                                                        row.user.id,
                                                    )
                                                "
                                                class="block truncate font-semibold text-foreground hover:text-primary"
                                            >
                                                {{ row.user.name }}
                                            </Link>
                                            <p
                                                class="truncate text-[11px] text-muted-foreground"
                                            >
                                                @{{ row.user.username }} · #{{
                                                    row.user.id
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pe-3">
                                    <RoleBadge
                                        v-if="row.user"
                                        :role="row.user.role"
                                        :label="row.user.role_label"
                                    />
                                </td>
                                <td class="py-3 pe-3">
                                    <UserBadges
                                        :badges="[
                                            {
                                                type: row.badge_type,
                                                label_key:
                                                    row.badge_type ===
                                                    'official_account'
                                                        ? 'badges.officialAccount.label'
                                                        : row.badge_type ===
                                                            'official_staff'
                                                          ? 'badges.officialStaff.label'
                                                          : row.badge_type ===
                                                              'moderator'
                                                            ? 'badges.moderator.label'
                                                            : row.badge_type ===
                                                                'early_member'
                                                              ? 'badges.earlyMember.label'
                                                              : row.label,
                                                color: row.color,
                                                icon: row.icon,
                                            },
                                        ]"
                                        mode="showcase"
                                        size="sm"
                                    />
                                </td>
                                <td class="py-3 pe-3">
                                    <div class="flex flex-col gap-1">
                                        <Badge
                                            :variant="
                                                row.is_active
                                                    ? 'default'
                                                    : 'destructive'
                                            "
                                            class="w-fit text-[10px]"
                                        >
                                            {{
                                                row.is_active
                                                    ? t(
                                                          'admin.badges.statusActive',
                                                      )
                                                    : t(
                                                          'admin.badges.statusRevoked',
                                                      )
                                            }}
                                        </Badge>
                                        <span
                                            v-if="row.is_active && !row.is_public"
                                            class="text-[10px] text-warning"
                                        >
                                            {{ t('admin.badges.internalOnly') }}
                                        </span>
                                        <span
                                            v-if="
                                                row.is_active &&
                                                !row.effective_eligible
                                            "
                                            class="text-[10px] text-destructive"
                                        >
                                            {{
                                                t(
                                                    'admin.badges.ineligibleHidden',
                                                )
                                            }}
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-xs py-3 pe-3">
                                    <p
                                        v-if="row.grant_reason"
                                        class="text-foreground/90"
                                    >
                                        {{ row.grant_reason }}
                                    </p>
                                    <p
                                        v-if="row.revoke_reason"
                                        class="mt-0.5 text-destructive"
                                    >
                                        {{ t('admin.badges.revokedPrefix') }}
                                        {{ row.revoke_reason }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-muted-foreground"
                                    >
                                        {{ row.grant_source }}
                                        <span v-if="row.granted_by_username">
                                            · @{{ row.granted_by_username }}
                                        </span>
                                    </p>
                                </td>
                                <td
                                    class="py-3 pe-3 text-[11px] text-muted-foreground"
                                >
                                    <p>
                                        {{
                                            formatDateTime(
                                                row.granted_at,
                                                locale,
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="row.revoked_at"
                                        class="text-destructive"
                                    >
                                        {{
                                            formatDateTime(
                                                row.revoked_at,
                                                locale,
                                            )
                                        }}
                                    </p>
                                </td>
                                <td class="py-3 text-end">
                                    <div
                                        v-if="canManage && row.is_active"
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            class="h-7 px-2 text-xs"
                                            :title="
                                                row.is_public
                                                    ? t('admin.badges.hidePublic')
                                                    : t('admin.badges.showPublic')
                                            "
                                            @click="toggleVisibility(row)"
                                        >
                                            <EyeOff
                                                v-if="row.is_public"
                                                class="size-3.5"
                                            />
                                            <Eye v-else class="size-3.5" />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            class="h-7 gap-1 rounded-lg px-2 text-xs"
                                            @click="openRevokeDialog(row)"
                                        >
                                            <XCircle class="size-3.5" />
                                            <span>{{
                                                t('admin.badges.revokeButton')
                                            }}</span>
                                        </Button>
                                    </div>
                                    <Link
                                        v-else-if="row.user"
                                        :href="adminUserShow.url(row.user.id)"
                                        class="text-xs font-medium text-primary hover:underline"
                                    >
                                        {{ t('admin.common.view') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <AdminPagination
                :current-page="badges.current_page"
                :last-page="badges.last_page"
                :total="badges.total"
                :from="badges.from"
                :to="badges.to"
                :base-url="adminBadgesIndex.url()"
                :query="{
                    search: filters.search,
                    badge_type: filters.badge_type,
                    status: filters.status,
                    role: filters.role,
                }"
            />
        </div>

        <!-- Audit Log Section -->
        <section
            v-if="auditLogs.length > 0"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="mb-3 text-sm font-semibold text-foreground">
                {{ t('admin.badges.auditTitle') }}
            </h2>
            <div class="divide-y divide-border/70 text-xs">
                <div
                    v-for="log in auditLogs"
                    :key="log.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <div class="min-w-0">
                        <span class="font-mono font-semibold text-primary">{{
                            log.action
                        }}</span>
                        <span
                            v-if="log.actor_username"
                            class="ms-2 text-muted-foreground"
                        >
                            @{{ log.actor_username }}
                        </span>
                        <span
                            v-if="log.metadata?.badge_type"
                            class="ms-2 text-foreground/80"
                        >
                            · {{ log.metadata.badge_type }}
                        </span>
                        <span
                            v-if="log.metadata?.reason"
                            class="ms-2 text-muted-foreground"
                        >
                            — {{ log.metadata.reason }}
                        </span>
                    </div>
                    <span class="text-[11px] text-muted-foreground">
                        {{ formatDateTime(log.created_at, locale) }}
                    </span>
                </div>
            </div>
        </section>

        <ConfirmActionDialog
            v-model:open="grantConfirmOpen"
            :title="t('admin.badges.confirmGrantTitle')"
            :description="
                t('admin.badges.confirmGrantDesc', {
                    username: grantForm.username,
                    badge: grantForm.badge_type,
                })
            "
            :confirm-label="t('admin.badges.grantButton')"
            :destructive="false"
            :processing="grantForm.processing"
            @confirm="submitGrant"
        />

        <ReasonDialog
            v-model:open="revokeDialogOpen"
            :title="t('admin.badges.confirmRevokeTitle')"
            :description="
                t('admin.badges.confirmRevokeDesc', {
                    username: revokeTarget?.user?.username || '-',
                    badge: revokeTarget?.label || '',
                })
            "
            :confirm-label="t('admin.badges.revokeButton')"
            :destructive="true"
            :processing="revokeProcessing"
            @submit="confirmRevoke"
        />
    </div>
</template>
