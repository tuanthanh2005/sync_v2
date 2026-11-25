<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BadgeCheck,
    CreditCard,
    Flag,
    HardDrive,
    Megaphone,
    MessageSquareText,
    Newspaper,
    Scale,
    Server,
    Shield,
    ShieldAlert,
    UserCheck,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminChart from '@/components/admin/AdminChart.vue';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    formatCurrencyVnd,
    formatDateTime,
    formatNumber,
} from '@/lib/formatters';
import {
    analytics as adminAnalytics,
    health as adminHealth,
} from '@/routes/admin';
import { index as adminAds } from '@/routes/admin/ads';
import { index as adminAppeals } from '@/routes/admin/appeals';
import { index as adminFinance } from '@/routes/admin/finance';
import { index as adminModeration } from '@/routes/admin/moderation';
import { index as adminSecurity } from '@/routes/admin/security';
import {
    index as adminUsers,
    show as adminUserShow,
} from '@/routes/admin/users';
import { index as adminVerifications } from '@/routes/admin/verifications';
import type {
    AdminAuditRecord,
    AdminPlatformStatus,
    AdminUserRecord,
} from '@/types';

type DashboardMetrics = {
    total_users: number;
    new_users_7d: number;
    active_users: number;
    verified_users: number;
    unverified_users: number;
    staff_users: number;
    suspended_users: number;
    banned_users: number;
    dau: number;
    mau: number;
    total_posts: number;
    total_comments: number;
    total_communities: number;
    pending_reports: number;
    pending_ads: number;
    active_ads: number;
    pending_appeals: number;
    pending_verifications: number;
    total_revenue_vnd: number;
    media_storage_bytes: number;
};

type GrowthSeries = {
    labels: string[];
    users: number[];
    posts: number[];
    comments: number[];
    deposits: number[];
    adSpend: number[];
};

const props = defineProps<{
    metrics: DashboardMetrics;
    platform_status: AdminPlatformStatus;
    growth_chart: GrowthSeries;
    recent_users: AdminUserRecord[];
    recent_audits: AdminAuditRecord[];
}>();

const { t, locale } = useI18n();
const page = usePage();
const can = computed(() => page.props.auth.user?.can);

const chartDatasets = computed(() => [
    {
        label: t('admin.analytics.usersSeries'),
        data: props.growth_chart.users,
        color: '#4F46E5',
    },
    {
        label: t('admin.analytics.postsSeries'),
        data: props.growth_chart.posts,
        color: '#16A34A',
    },
    {
        label: t('admin.analytics.commentsSeries'),
        data: props.growth_chart.comments,
        color: '#0284C7',
    },
]);
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.dashboard.title')" />

        <!-- Page Heading -->
        <section
            class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <div
                    class="flex items-center gap-2 text-xs font-semibold text-primary"
                >
                    <Shield class="size-4" aria-hidden="true" />
                    <span>{{ t('admin.centerTitle') }}</span>
                </div>
                <h1 class="mt-1 text-xl font-bold text-foreground">
                    {{ t('admin.dashboard.title') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.dashboard.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="can?.viewAnalytics"
                    size="sm"
                    variant="outline"
                    class="rounded-lg"
                    as-child
                >
                    <Link :href="adminAnalytics()">{{
                        t('admin.nav.analytics')
                    }}</Link>
                </Button>
                <Button
                    v-if="can?.viewSystemHealth"
                    size="sm"
                    variant="outline"
                    class="rounded-lg"
                    as-child
                >
                    <Link :href="adminHealth()">{{
                        t('admin.nav.systemHealth')
                    }}</Link>
                </Button>
                <Button
                    v-if="can?.manageUsers"
                    size="sm"
                    class="rounded-lg"
                    as-child
                >
                    <Link :href="adminUsers()">{{
                        t('admin.nav.allUsers')
                    }}</Link>
                </Button>
            </div>
        </section>

        <!-- Primary KPI Grid -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AdminStatCard
                :label="t('admin.dashboard.totalUsers')"
                :value="formatNumber(metrics.total_users, locale)"
                :hint="`${t('admin.dashboard.dau')}: ${formatNumber(metrics.dau, locale)} · ${t('admin.dashboard.mau')}: ${formatNumber(metrics.mau, locale)}`"
                :icon="Users"
                tone="primary"
                :href="can?.manageUsers ? adminUsers.url() : undefined"
            />
            <AdminStatCard
                :label="t('admin.dashboard.newUsers')"
                :value="formatNumber(metrics.new_users_7d, locale)"
                :hint="`${t('admin.dashboard.unverifiedUsers')}: ${formatNumber(metrics.unverified_users, locale)}`"
                :icon="UserPlus"
                tone="success"
                :href="
                    can?.manageUsers
                        ? adminUsers.url({ query: { filter: 'unverified' } })
                        : undefined
                "
            />
            <AdminStatCard
                :label="t('admin.dashboard.totalPosts')"
                :value="formatNumber(metrics.total_posts, locale)"
                :hint="`${t('admin.dashboard.totalComments')}: ${formatNumber(metrics.total_comments, locale)} · ${t('admin.dashboard.totalCommunities')}: ${formatNumber(metrics.total_communities, locale)}`"
                :icon="Newspaper"
                tone="primary"
            />
            <AdminStatCard
                :label="t('admin.dashboard.revenue')"
                :value="formatCurrencyVnd(metrics.total_revenue_vnd, locale)"
                :hint="`${t('admin.dashboard.activeAds')}: ${formatNumber(metrics.active_ads, locale)}`"
                :icon="CreditCard"
                tone="success"
                :href="can?.manageFinance ? adminFinance.url() : undefined"
            />
        </section>

        <!-- Action Queues & Safety Metrics -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <AdminStatCard
                :label="t('admin.dashboard.pendingReports')"
                :value="formatNumber(metrics.pending_reports, locale)"
                :icon="Flag"
                :tone="metrics.pending_reports > 0 ? 'warning' : 'neutral'"
                :href="
                    can?.accessModeration ? adminModeration.url() : undefined
                "
            />
            <AdminStatCard
                :label="t('admin.dashboard.pendingAds')"
                :value="formatNumber(metrics.pending_ads, locale)"
                :icon="Megaphone"
                :tone="metrics.pending_ads > 0 ? 'warning' : 'neutral'"
                :href="
                    can?.reviewAds
                        ? adminAds.url({ query: { status: 'pending_review' } })
                        : undefined
                "
            />
            <AdminStatCard
                :label="t('admin.nav.appeals')"
                :value="formatNumber(metrics.pending_appeals, locale)"
                :icon="Scale"
                :tone="metrics.pending_appeals > 0 ? 'warning' : 'neutral'"
                :href="can?.manageAppeals ? adminAppeals.url() : undefined"
            />
            <AdminStatCard
                :label="t('admin.dashboard.bannedUsers')"
                :value="
                    formatNumber(
                        metrics.banned_users + metrics.suspended_users,
                        locale,
                    )
                "
                :icon="ShieldAlert"
                tone="destructive"
                :href="
                    can?.manageUsers
                        ? adminUsers.url({ query: { filter: 'banned' } })
                        : undefined
                "
            />
            <AdminStatCard
                :label="t('admin.dashboard.mediaStorage')"
                :value="formatBytes(metrics.media_storage_bytes, locale)"
                :hint="`${t('admin.nav.verifications')}: ${metrics.pending_verifications}`"
                :icon="HardDrive"
                tone="neutral"
                :href="
                    can?.manageVerifications
                        ? adminVerifications.url()
                        : undefined
                "
            />
        </section>

        <!-- 14-Day Growth Chart + Runtime Posture -->
        <section class="grid gap-5 lg:grid-cols-3">
            <div
                class="rounded-xl border border-border bg-card p-5 lg:col-span-2"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ t('admin.dashboard.growthChart') }}
                    </h2>
                    <Link
                        v-if="can?.viewAnalytics"
                        :href="adminAnalytics()"
                        class="text-xs font-medium text-primary hover:underline"
                    >
                        {{ t('admin.nav.analytics') }}
                    </Link>
                </div>
                <AdminChart
                    type="line"
                    :labels="growth_chart.labels"
                    :datasets="chartDatasets"
                    :height="250"
                />
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="flex items-center gap-2">
                    <Server class="size-4 text-primary" aria-hidden="true" />
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ t('admin.health.drivers') }}
                    </h2>
                </div>
                <dl class="mt-4 space-y-2.5 text-xs">
                    <div
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('admin.health.environment') }}
                        </dt>
                        <dd class="font-mono font-semibold">
                            {{ platform_status.app_env }} ·
                            {{ platform_status.locale }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-muted-foreground">Hashing</dt>
                        <dd class="font-mono font-semibold">
                            {{ platform_status.hashing_driver }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-muted-foreground">Session / Cache</dt>
                        <dd class="font-mono font-semibold">
                            {{ platform_status.session_driver }} /
                            {{ platform_status.cache_store }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-muted-foreground">Queue / Disk</dt>
                        <dd class="font-mono font-semibold">
                            {{ platform_status.queue_connection }} /
                            {{ platform_status.media_disk }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-muted-foreground">Admin 2FA</dt>
                        <dd class="font-semibold text-success">
                            {{ t('admin.common.enabled') }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <!-- Newest Accounts & Recent Audits -->
        <section class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <UserCheck
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <h2 class="text-sm font-semibold text-foreground">
                            {{ t('admin.dashboard.recentUsers') }}
                        </h2>
                    </div>
                    <Link
                        v-if="can?.manageUsers"
                        :href="adminUsers()"
                        class="text-xs font-medium text-primary hover:underline"
                    >
                        {{ t('admin.common.all') }}
                    </Link>
                </div>

                <EmptyState
                    v-if="recent_users.length === 0"
                    :title="t('admin.common.emptyTitle')"
                    :description="t('admin.common.emptyDescription')"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-3 font-medium">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2 pr-3 font-medium">
                                    {{ t('admin.users.role') }}
                                </th>
                                <th class="py-2 font-medium">
                                    {{ t('admin.common.status') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="account in recent_users"
                                :key="account.id"
                                class="hover:bg-surface-hover/50"
                            >
                                <td class="py-2.5 pr-3">
                                    <Link
                                        v-if="can?.manageUsers"
                                        :href="adminUserShow(account.id)"
                                        class="font-medium text-foreground hover:text-primary"
                                    >
                                        {{ account.name }}
                                    </Link>
                                    <span
                                        v-else
                                        class="font-medium text-foreground"
                                        >{{ account.name }}</span
                                    >
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        @{{ account.username }}
                                    </p>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <RoleBadge
                                        :role="account.role"
                                        :label="account.role_label"
                                    />
                                </td>
                                <td class="py-2.5">
                                    <Badge
                                        variant="secondary"
                                        class="text-[11px]"
                                        >{{ account.status_label }}</Badge
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Activity
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <h2 class="text-sm font-semibold text-foreground">
                            {{ t('admin.dashboard.recentAudits') }}
                        </h2>
                    </div>
                    <Link
                        v-if="can?.viewSecurity"
                        :href="adminSecurity()"
                        class="text-xs font-medium text-primary hover:underline"
                    >
                        {{ t('admin.common.all') }}
                    </Link>
                </div>

                <EmptyState
                    v-if="recent_audits.length === 0"
                    :title="t('admin.common.emptyTitle')"
                    :description="t('admin.common.emptyDescription')"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-3 font-medium">
                                    {{ t('admin.common.actions') }}
                                </th>
                                <th class="py-2 pr-3 font-medium">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2 font-medium">
                                    {{ t('admin.common.createdAt') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="audit in recent_audits" :key="audit.id">
                                <td
                                    class="py-2.5 pr-3 font-mono text-[11px] text-foreground"
                                >
                                    {{ audit.action }}
                                </td>
                                <td class="py-2.5 pr-3 text-muted-foreground">
                                    {{
                                        audit.actor_username
                                            ? `@${audit.actor_username}`
                                            : 'system'
                                    }}
                                </td>
                                <td class="py-2.5 text-muted-foreground">
                                    {{
                                        formatDateTime(audit.created_at, locale)
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
