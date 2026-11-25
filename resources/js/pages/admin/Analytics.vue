<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BarChart3,
    CreditCard,
    Megaphone,
    MessageSquareText,
    Newspaper,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminChart from '@/components/admin/AdminChart.vue';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import { Button } from '@/components/ui/button';
import { formatCurrencyVnd, formatNumber } from '@/lib/formatters';
import { analytics as adminAnalytics } from '@/routes/admin';

const props = defineProps<{
    period: number;
    totals: {
        users: number;
        posts: number;
        comments: number;
        communities: number;
        activeCampaigns: number;
        revenueVnd: number;
    };
    growth: {
        users_pct: number;
        posts_pct: number;
    };
    series: {
        labels: string[];
        users: number[];
        posts: number[];
        comments: number[];
        deposits: number[];
        adSpend: number[];
    };
}>();

const { t, locale } = useI18n();

function selectPeriod(days: number): void {
    router.get(
        adminAnalytics.url({ query: { period: days } }),
        {},
        { preserveScroll: true },
    );
}

const activityDatasets = computed(() => [
    {
        label: t('admin.analytics.usersSeries'),
        data: props.series.users,
        color: '#4F46E5',
    },
    {
        label: t('admin.analytics.postsSeries'),
        data: props.series.posts,
        color: '#16A34A',
    },
    {
        label: t('admin.analytics.commentsSeries'),
        data: props.series.comments,
        color: '#0284C7',
    },
]);

const revenueDatasets = computed(() => [
    {
        label: t('admin.analytics.revenueSeries'),
        data: props.series.deposits,
        color: '#16A34A',
    },
    {
        label: t('admin.analytics.adSpendSeries'),
        data: props.series.adSpend,
        color: '#4F46E5',
    },
]);
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.analytics.title')" />

        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <BarChart3 class="size-5 text-primary" aria-hidden="true" />
                    <span>{{ t('admin.analytics.title') }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.analytics.subtitle') }}
                </p>
            </div>
            <div
                class="flex items-center gap-1.5 rounded-lg border border-border bg-muted/40 p-1"
            >
                <Button
                    v-for="d in [7, 30, 90]"
                    :key="d"
                    type="button"
                    size="sm"
                    :variant="period === d ? 'default' : 'ghost'"
                    class="h-7 rounded-md px-3 text-xs"
                    @click="selectPeriod(d)"
                >
                    {{
                        d === 7
                            ? t('admin.analytics.last7Days')
                            : d === 30
                              ? t('admin.analytics.last30Days')
                              : t('admin.analytics.last90Days')
                    }}
                </Button>
            </div>
        </div>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <AdminStatCard
                :label="t('admin.dashboard.totalUsers')"
                :value="formatNumber(totals.users, locale)"
                :hint="`${t('admin.analytics.growthRate')}: ${growth.users_pct >= 0 ? '+' : ''}${growth.users_pct}%`"
                :icon="Users"
                tone="primary"
            />
            <AdminStatCard
                :label="t('admin.dashboard.totalPosts')"
                :value="formatNumber(totals.posts, locale)"
                :hint="`${t('admin.analytics.growthRate')}: ${growth.posts_pct >= 0 ? '+' : ''}${growth.posts_pct}%`"
                :icon="Newspaper"
                tone="success"
            />
            <AdminStatCard
                :label="t('admin.dashboard.totalComments')"
                :value="formatNumber(totals.comments, locale)"
                :icon="MessageSquareText"
                tone="neutral"
            />
            <AdminStatCard
                :label="t('admin.dashboard.activeAds')"
                :value="formatNumber(totals.activeCampaigns, locale)"
                :icon="Megaphone"
                tone="warning"
            />
            <AdminStatCard
                :label="t('admin.analytics.revenueSeries')"
                :value="formatCurrencyVnd(totals.revenueVnd, locale)"
                :icon="CreditCard"
                tone="success"
            />
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold text-foreground">
                    {{ t('admin.analytics.usersSeries') }} ·
                    {{ t('admin.analytics.postsSeries') }} ·
                    {{ t('admin.analytics.commentsSeries') }}
                </h2>
                <AdminChart
                    type="line"
                    :labels="series.labels"
                    :datasets="activityDatasets"
                    :height="280"
                />
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold text-foreground">
                    {{ t('admin.analytics.revenueSeries') }} &
                    {{ t('admin.analytics.adSpendSeries') }}
                </h2>
                <AdminChart
                    type="bar"
                    :labels="series.labels"
                    :datasets="revenueDatasets"
                    :height="280"
                />
            </div>
        </section>
    </div>
</template>
