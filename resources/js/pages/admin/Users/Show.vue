<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    BadgeCheck,
    KeyRound,
    LogOut,
    Mail,
    RotateCcw,
    ShieldAlert,
    ShieldBan,
    Trash2,
    UserCheck,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import { formatCurrencyVnd, formatDateTime } from '@/lib/formatters';
import {
    index as adminUsers,
    lifecycle as userLifecycle,
    passwordReset as userPasswordReset,
    revokeSessions as userRevokeSessions,
    role as userRoleRoute,
    sanction as userSanctionRoute,
    update as userUpdateRoute,
} from '@/routes/admin/users';

const props = defineProps<{
    account: {
        id: number;
        name: string;
        username: string;
        email: string;
        avatar: string | null;
        is_verified_badge: boolean;
        role: 'user' | 'moderator' | 'admin';
        role_label: string;
        rbac_role: string;
        status: 'active' | 'suspended' | 'banned';
        status_label: string;
        suspended_until: string | null;
        ban_reason: string | null;
        warnings_count: number;
        violations_count: number;
        timezone: string;
        email_verified_at: string | null;
        two_factor_enabled: boolean;
        last_login_at: string | null;
        last_activity_at: string | null;
        deactivated_at: string | null;
        deleted_at: string | null;
        created_at: string | null;
        capabilities: {
            can_post: boolean;
            post_restricted_until: string | null;
            can_comment: boolean;
            comment_restricted_until: string | null;
            can_create_community: boolean;
            community_restricted_until: string | null;
            can_message: boolean;
            message_restricted_until: string | null;
            can_advertise: boolean;
            ad_restricted_until: string | null;
        };
        wallet: {
            balance: number;
            promo_balance: number;
            held_balance: number;
            total_deposited: number;
            total_spent: number;
        };
    };
    roles: Array<{
        id: number;
        slug: string;
        name: string;
        hierarchy_level: number;
    }>;
    sessions: Array<{
        id: string;
        ip_address: string;
        user_agent: string;
        last_active_at: string;
    }>;
    loginHistories: Array<{
        id: number;
        event_type: string;
        ip_address: string | null;
        user_agent: string;
        created_at: string;
    }>;
    sanctions: Array<{
        id: number;
        type: string;
        type_label: string;
        reason: string;
        starts_at: string;
        ends_at: string | null;
        is_active: boolean;
        actor_username: string | null;
    }>;
    posts: Array<{
        id: number;
        title: string | null;
        excerpt: string;
        moderation_status: string;
        reactions_count: number;
        comments_count: number;
        created_at: string | null;
    }>;
    comments: Array<{
        id: number;
        post_id: number;
        excerpt: string;
        moderation_status: string;
        created_at: string | null;
    }>;
    communities: Array<{
        id: number;
        name: string;
        slug: string | null;
        role: string;
        points: number;
    }>;
    reportsAgainst: Array<{
        id: number;
        reason: string;
        status: string;
        created_at: string | null;
    }>;
    campaigns: Array<{
        id: number;
        name: string;
        status: string;
        total_budget: number;
        spent_amount: number;
    }>;
    creditEntries: Array<{
        id: number;
        type: string;
        wallet_bucket: string;
        amount: number;
        description: string;
        created_at: string;
    }>;
    audits: Array<{
        id: number;
        action: string;
        actor_username: string | null;
        ip_address: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const activeTab = ref<
    'overview' | 'activity' | 'security' | 'finance' | 'audits'
>('overview');

const editForm = useForm({
    name: props.account.name,
    username: props.account.username,
    timezone: props.account.timezone,
    is_verified_badge: props.account.is_verified_badge,
});

const selectedRole = ref(props.account.rbac_role);
const sanctionAction = ref('warn');
const sanctionDuration = ref('24h');
const sanctionUntil = ref('');
const sanctionDialogOpen = ref(false);
const sanctionProcessing = ref(false);

const lifecycleMode = ref<'deactivate' | 'soft_delete' | 'restore'>(
    'soft_delete',
);
const lifecycleDialogOpen = ref(false);
const revokeDialogOpen = ref(false);

function saveProfile(): void {
    editForm.patch(userUpdateRoute.url(props.account.id), {
        preserveScroll: true,
    });
}

function saveRole(): void {
    router.post(
        userRoleRoute.url(props.account.id),
        { role: selectedRole.value },
        { preserveScroll: true },
    );
}

function openSanction(action: string): void {
    sanctionAction.value = action;
    sanctionDialogOpen.value = true;
}

function submitSanction(reason: string): void {
    sanctionProcessing.value = true;
    router.post(
        userSanctionRoute.url(props.account.id),
        {
            action: sanctionAction.value,
            reason,
            duration:
                sanctionAction.value === 'ban'
                    ? 'permanent'
                    : sanctionDuration.value,
            until:
                sanctionDuration.value === 'custom'
                    ? sanctionUntil.value
                    : undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                sanctionProcessing.value = false;
                sanctionDialogOpen.value = false;
            },
        },
    );
}

function openLifecycle(mode: 'deactivate' | 'soft_delete' | 'restore'): void {
    lifecycleMode.value = mode;
    lifecycleDialogOpen.value = true;
}

function submitLifecycle(reason: string): void {
    router.post(
        userLifecycle.url(props.account.id),
        { mode: lifecycleMode.value, reason },
        {
            preserveScroll: true,
            onFinish: () => {
                lifecycleDialogOpen.value = false;
            },
        },
    );
}

function confirmRevokeSessions(): void {
    router.post(
        userRevokeSessions.url(props.account.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                revokeDialogOpen.value = false;
            },
        },
    );
}

function sendReset(): void {
    router.post(
        userPasswordReset.url(props.account.id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="`${account.name} · ${t('admin.users.detailTitle')}`" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button
                variant="outline"
                size="sm"
                class="gap-1.5 rounded-lg text-xs"
                as-child
            >
                <Link :href="adminUsers()">
                    <ArrowLeft class="size-3.5" />
                    <span>{{ t('admin.nav.allUsers') }}</span>
                </Link>
            </Button>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="revokeDialogOpen = true"
                >
                    <LogOut class="size-3.5" />
                    <span>{{ t('admin.users.revokeSessions') }}</span>
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="sendReset"
                >
                    <Mail class="size-3.5" />
                    <span>{{ t('admin.users.sendPasswordReset') }}</span>
                </Button>
                <Button
                    v-if="account.deleted_at || account.deactivated_at"
                    size="sm"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openLifecycle('restore')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.users.restoreAccount') }}</span>
                </Button>
                <Button
                    v-else
                    size="sm"
                    variant="destructive"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openLifecycle('soft_delete')"
                >
                    <Trash2 class="size-3.5" />
                    <span>{{ t('admin.users.softDelete') }}</span>
                </Button>
            </div>
        </div>

        <!-- Account Summary Card -->
        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex items-center gap-4">
                <Avatar class="size-14 rounded-full">
                    <AvatarImage
                        v-if="account.avatar"
                        :src="account.avatar"
                        :alt="account.name"
                    />
                    <AvatarFallback
                        class="bg-primary-soft text-base font-bold text-accent-foreground"
                    >
                        {{ getInitials(account.name) }}
                    </AvatarFallback>
                </Avatar>
                <div>
                    <h1
                        class="flex items-center gap-2 text-lg font-bold text-foreground"
                    >
                        <span>{{ account.name }}</span>
                        <BadgeCheck
                            v-if="account.is_verified_badge"
                            class="size-4 text-primary"
                        />
                        <RoleBadge
                            :role="account.role"
                            :label="account.role_label"
                        />
                    </h1>
                    <p class="text-xs text-muted-foreground">
                        #{{ account.id }} · @{{ account.username }} ·
                        {{ account.email }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                        <Badge
                            :variant="
                                account.status === 'banned'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{ account.status_label }}
                        </Badge>
                        <Badge variant="outline">
                            {{ t('admin.users.warnings') }}:
                            {{ account.warnings_count }}
                        </Badge>
                        <Badge variant="outline">
                            {{ t('admin.users.violations') }}:
                            {{ account.violations_count }}
                        </Badge>
                        <Badge v-if="account.suspended_until" variant="outline">
                            Until:
                            {{
                                formatDateTime(account.suspended_until, locale)
                            }}
                        </Badge>
                    </div>
                </div>
            </div>

            <!-- Quick Sanction Actions -->
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openSanction('warn')"
                >
                    <AlertTriangle class="size-3.5 text-warning" />
                    <span>{{ t('admin.users.warn') }}</span>
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openSanction('suspend')"
                >
                    <ShieldAlert class="size-3.5 text-warning" />
                    <span>{{ t('admin.users.suspend') }}</span>
                </Button>
                <Button
                    v-if="account.status !== 'banned'"
                    size="sm"
                    variant="destructive"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openSanction('ban')"
                >
                    <ShieldBan class="size-3.5" />
                    <span>{{ t('admin.users.ban') }}</span>
                </Button>
                <Button
                    v-if="account.status !== 'active'"
                    size="sm"
                    class="gap-1.5 rounded-lg text-xs"
                    @click="openSanction('unban')"
                >
                    <UserCheck class="size-3.5" />
                    <span>{{ t('admin.users.unban') }}</span>
                </Button>
            </div>
        </section>

        <!-- Navigation Tabs -->
        <div
            class="flex flex-wrap gap-1.5 rounded-xl border border-border bg-card p-2"
        >
            <button
                v-for="tab in [
                    'overview',
                    'activity',
                    'security',
                    'finance',
                    'audits',
                ] as const"
                :key="tab"
                type="button"
                :class="[
                    'rounded-lg px-3.5 py-2 text-xs font-medium transition-colors',
                    activeTab === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                ]"
                @click="activeTab = tab"
            >
                {{ t(`admin.users.tabs.${tab}`) }}
            </button>
        </div>

        <!-- Tab 1: Overview, Profile Edit, RBAC & Granular Restrictions -->
        <section
            v-if="activeTab === 'overview'"
            class="grid gap-5 lg:grid-cols-2"
        >
            <div class="space-y-4 rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.common.edit') }}
                </h2>
                <form @submit.prevent="saveProfile" class="space-y-3">
                    <div class="space-y-1">
                        <Label class="text-xs">{{
                            t('admin.users.member')
                        }}</Label>
                        <Input v-model="editForm.name" class="h-9 text-xs" />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-xs">Username</Label>
                        <Input
                            v-model="editForm.username"
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-xs">Timezone</Label>
                        <Input
                            v-model="editForm.timezone"
                            class="h-9 text-xs"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            v-model="editForm.is_verified_badge"
                            type="checkbox"
                            class="rounded border-input"
                        />
                        <span>{{ t('admin.content.verify') }}</span>
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        class="rounded-lg text-xs"
                        :disabled="editForm.processing"
                    >
                        {{
                            editForm.processing
                                ? t('admin.common.saving')
                                : t('admin.common.save')
                        }}
                    </Button>
                </form>

                <div class="border-t border-border pt-4">
                    <h3
                        class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                    >
                        <KeyRound class="size-3.5 text-primary" />
                        <span>{{ t('admin.users.assignRole') }}</span>
                    </h3>
                    <div class="mt-2 flex items-center gap-2">
                        <select
                            v-model="selectedRole"
                            class="h-9 flex-1 rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option
                                v-for="r in roles"
                                :key="r.slug"
                                :value="r.slug"
                            >
                                {{ r.name }} (L{{ r.hierarchy_level }})
                            </option>
                        </select>
                        <Button
                            type="button"
                            size="sm"
                            class="h-9 rounded-lg text-xs"
                            @click="saveRole"
                        >
                            {{ t('admin.common.save') }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Granular Capability Restrictions & Sanction History -->
            <div class="space-y-4 rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.users.sanctions') }}
                </h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="cap in [
                            {
                                key: 'post',
                                ok: account.capabilities.can_post,
                                until: account.capabilities
                                    .post_restricted_until,
                                action: 'restrict_post',
                            },
                            {
                                key: 'comment',
                                ok: account.capabilities.can_comment,
                                until: account.capabilities
                                    .comment_restricted_until,
                                action: 'restrict_comment',
                            },
                            {
                                key: 'community',
                                ok: account.capabilities.can_create_community,
                                until: account.capabilities
                                    .community_restricted_until,
                                action: 'restrict_community',
                            },
                            {
                                key: 'message',
                                ok: account.capabilities.can_message,
                                until: account.capabilities
                                    .message_restricted_until,
                                action: 'restrict_message',
                            },
                            {
                                key: 'advertise',
                                ok: account.capabilities.can_advertise,
                                until: account.capabilities.ad_restricted_until,
                                action: 'restrict_ad',
                            },
                        ]"
                        :key="cap.key"
                        class="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3 text-xs"
                    >
                        <div>
                            <p class="font-medium text-foreground">
                                {{ t(`admin.users.capabilities.${cap.key}`) }}
                            </p>
                            <p
                                :class="
                                    cap.ok ? 'text-success' : 'text-destructive'
                                "
                            >
                                {{
                                    cap.ok
                                        ? t('admin.common.enabled')
                                        : t('admin.common.disabled')
                                }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            :variant="cap.ok ? 'outline' : 'default'"
                            class="h-7 rounded-md px-2 text-[11px]"
                            @click="
                                openSanction(
                                    cap.ok ? cap.action : 'lift_restriction',
                                )
                            "
                        >
                            {{
                                cap.ok
                                    ? t('admin.users.restrict')
                                    : t('admin.users.liftRestriction')
                            }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-2 border-t border-border pt-3">
                    <EmptyState
                        v-if="sanctions.length === 0"
                        :title="t('admin.common.emptyTitle')"
                        :description="t('admin.common.emptyDescription')"
                    />
                    <div
                        v-for="s in sanctions"
                        :key="s.id"
                        class="rounded-lg border border-border/80 bg-muted/20 p-3 text-xs"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-foreground">{{
                                s.type_label
                            }}</span>
                            <Badge
                                :variant="
                                    s.is_active ? 'destructive' : 'secondary'
                                "
                                class="text-[10px]"
                            >
                                {{
                                    s.is_active
                                        ? t('admin.common.enabled')
                                        : t('admin.common.disabled')
                                }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-muted-foreground">{{ s.reason }}</p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            @{{ s.actor_username || 'system' }} ·
                            {{ formatDateTime(s.starts_at, locale) }}
                            <span v-if="s.ends_at">
                                → {{ formatDateTime(s.ends_at, locale) }}</span
                            >
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tab 2: Activity -->
        <section
            v-if="activeTab === 'activity'"
            class="grid gap-5 lg:grid-cols-2"
        >
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.users.posts') }} ({{ posts.length }})
                </h2>
                <div class="space-y-2 text-xs">
                    <div
                        v-for="p in posts"
                        :key="p.id"
                        class="rounded-lg border border-border p-3"
                    >
                        <p class="font-medium text-foreground">
                            {{ p.title || `#${p.id}` }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            {{ p.excerpt }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{ p.moderation_status }} ·
                            {{ formatDateTime(p.created_at, locale) }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.users.comments') }} ({{ comments.length }})
                </h2>
                <div class="space-y-2 text-xs">
                    <div
                        v-for="c in comments"
                        :key="c.id"
                        class="rounded-lg border border-border p-3"
                    >
                        <p class="text-foreground">{{ c.excerpt }}</p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            Post #{{ c.post_id }} · {{ c.moderation_status }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tab 3: Security & Sessions -->
        <section
            v-if="activeTab === 'security'"
            class="grid gap-5 lg:grid-cols-2"
        >
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.security.tabs.sessions') }} ({{
                        sessions.length
                    }})
                </h2>
                <div class="space-y-2 text-xs">
                    <div
                        v-for="s in sessions"
                        :key="s.id"
                        class="rounded-lg border border-border p-3"
                    >
                        <p class="font-mono font-semibold">
                            {{ s.ip_address }}
                        </p>
                        <p class="mt-0.5 text-[11px] text-muted-foreground">
                            {{ s.user_agent }}
                        </p>
                        <p class="mt-0.5 text-[11px] text-muted-foreground">
                            {{ formatDateTime(s.last_active_at, locale) }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.security.tabs.logins') }}
                </h2>
                <div class="space-y-2 text-xs">
                    <div
                        v-for="h in loginHistories"
                        :key="h.id"
                        class="flex items-center justify-between rounded-lg border border-border p-2.5"
                    >
                        <div>
                            <span class="font-mono font-semibold">{{
                                h.event_type
                            }}</span>
                            <span class="ms-2 text-muted-foreground">{{
                                h.ip_address || '-'
                            }}</span>
                        </div>
                        <span class="text-[11px] text-muted-foreground">{{
                            formatDateTime(h.created_at, locale)
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tab 4: Finance & Ads -->
        <section v-if="activeTab === 'finance'" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-4">
                <div
                    class="rounded-xl border border-border bg-card p-4 text-xs"
                >
                    <p class="text-muted-foreground">
                        {{ t('admin.finance.paidBalance') }}
                    </p>
                    <p class="mt-1 text-lg font-bold">
                        {{ formatCurrencyVnd(account.wallet.balance, locale) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 text-xs"
                >
                    <p class="text-muted-foreground">
                        {{ t('admin.finance.promoBalance') }}
                    </p>
                    <p class="mt-1 text-lg font-bold">
                        {{
                            formatCurrencyVnd(
                                account.wallet.promo_balance,
                                locale,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 text-xs"
                >
                    <p class="text-muted-foreground">
                        {{ t('admin.finance.heldBalance') }}
                    </p>
                    <p class="mt-1 text-lg font-bold">
                        {{
                            formatCurrencyVnd(
                                account.wallet.held_balance,
                                locale,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 text-xs"
                >
                    <p class="text-muted-foreground">
                        {{ t('wallet.totalSpent') }}
                    </p>
                    <p class="mt-1 text-lg font-bold">
                        {{
                            formatCurrencyVnd(
                                account.wallet.total_spent,
                                locale,
                            )
                        }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.finance.tabs.transactions') }}
                </h2>
                <div class="space-y-2 text-xs">
                    <div
                        v-for="e in creditEntries"
                        :key="e.id"
                        class="flex items-center justify-between rounded-lg border border-border p-3"
                    >
                        <div>
                            <p class="font-medium text-foreground">
                                {{ e.description }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ e.type }} · {{ e.wallet_bucket }}
                            </p>
                        </div>
                        <span class="font-mono font-semibold">{{
                            formatCurrencyVnd(e.amount, locale)
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tab 5: Admin Audit Trail -->
        <section
            v-if="activeTab === 'audits'"
            class="rounded-xl border border-border bg-card p-5"
        >
            <div class="space-y-2 text-xs">
                <div
                    v-for="a in audits"
                    :key="a.id"
                    class="flex items-center justify-between rounded-lg border border-border p-3"
                >
                    <div>
                        <p class="font-mono font-semibold text-foreground">
                            {{ a.action }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            @{{ a.actor_username || 'system' }} ·
                            {{ a.ip_address || '-' }}
                        </p>
                    </div>
                    <span class="text-[11px] text-muted-foreground">{{
                        formatDateTime(a.created_at, locale)
                    }}</span>
                </div>
            </div>
        </section>

        <!-- Sanction Reason + Duration Dialog -->
        <ReasonDialog
            v-model:open="sanctionDialogOpen"
            :title="`${t('admin.users.sanctions')}: ${sanctionAction}`"
            :description="`@${account.username} (${account.email})`"
            :consequence="
                sanctionAction === 'ban'
                    ? 'Permanent ban immediately revokes all sessions and blocks login.'
                    : undefined
            "
            :processing="sanctionProcessing"
            @submit="submitSanction"
        >
            <div
                v-if="
                    sanctionAction !== 'warn' &&
                    sanctionAction !== 'ban' &&
                    sanctionAction !== 'unban' &&
                    sanctionAction !== 'lift_restriction'
                "
                class="mt-3 space-y-2"
            >
                <Label class="text-xs">{{ t('admin.common.duration') }}</Label>
                <select
                    v-model="sanctionDuration"
                    class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                >
                    <option value="1h">
                        {{ t('admin.users.durations.1h') }}
                    </option>
                    <option value="24h">
                        {{ t('admin.users.durations.24h') }}
                    </option>
                    <option value="3d">
                        {{ t('admin.users.durations.3d') }}
                    </option>
                    <option value="7d">
                        {{ t('admin.users.durations.7d') }}
                    </option>
                    <option value="30d">
                        {{ t('admin.users.durations.30d') }}
                    </option>
                    <option value="custom">
                        {{ t('admin.users.durations.custom') }}
                    </option>
                    <option value="permanent">
                        {{ t('admin.users.durations.permanent') }}
                    </option>
                </select>
                <Input
                    v-if="sanctionDuration === 'custom'"
                    v-model="sanctionUntil"
                    type="datetime-local"
                    class="h-9 text-xs"
                />
            </div>
        </ReasonDialog>

        <!-- Lifecycle Reason Dialog -->
        <ReasonDialog
            v-model:open="lifecycleDialogOpen"
            :title="
                lifecycleMode === 'restore'
                    ? t('admin.users.restoreAccount')
                    : t('admin.users.softDelete')
            "
            :description="`@${account.username}`"
            :destructive="lifecycleMode !== 'restore'"
            @submit="submitLifecycle"
        />

        <!-- Revoke Sessions Confirm -->
        <ConfirmActionDialog
            v-model:open="revokeDialogOpen"
            :title="t('admin.users.revokeSessions')"
            :description="`@${account.username}`"
            @confirm="confirmRevokeSessions"
        />
    </div>
</template>
