<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Globe2,
    Link2,
    Plug,
    RefreshCw,
    ShieldCheck,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import SeoIntegrationsController from '@/actions/App/Http/Controllers/Admin/Seo/SeoIntegrationsController';
import SeoInternationalAndAuditController from '@/actions/App/Http/Controllers/Admin/Seo/SeoInternationalAndAuditController';
import SeoOverviewController from '@/actions/App/Http/Controllers/Admin/Seo/SeoOverviewController';
import SeoUrlsAndSitemapsController from '@/actions/App/Http/Controllers/Admin/Seo/SeoUrlsAndSitemapsController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';

type ConnectionSummary = {
    provider: string;
    auth_type: string;
    property_identifier: string | null;
    status: string;
    verified_at: string | null;
    last_synced_at: string | null;
    last_error: string | null;
};

defineProps<{
    days: number;
    canonicalOrigin: string;
    indexingAllowed: boolean;
    environment: string;
    kpis: {
        total_urls: number;
        eligible_urls: number;
        in_sitemap_urls: number;
        indexed_urls: number;
        not_indexed_urls: number;
        excluded_urls: number;
        published_translations: number;
        open_recommendations: number;
    };
    gsc: {
        status: string;
        property_identifier: string | null;
        setup_required: boolean;
        inspection_quota_used_today: number;
        inspection_quota_limit_daily: number;
        last_synced_at: string | null;
    };
    connections: Record<string, ConnectionSummary>;
    searchAnalytics: {
        has_data: boolean;
        clicks: number;
        impressions: number;
        ctr: number;
        avg_position: number;
        trend: Array<{ date: string; clicks: number; impressions: number }>;
        top_queries: Array<{
            query: string;
            clicks: number;
            impressions: number;
            ctr: number;
            position: number;
        }>;
    };
    latestAudit: {
        id: number;
        technical_health_score: number;
        urls_scanned: number;
        critical_count: number;
        high_count: number;
        medium_count: number;
        low_count: number;
        finished_at: string | null;
        disclaimer: string;
    } | null;
    alerts: Array<{
        id: number;
        severity: string;
        check_code: string;
        title: string;
        url: string | null;
    }>;
    byLocale: Array<{
        locale: string;
        total: number;
        eligible: number;
        in_sitemap: number;
        indexed: number;
    }>;
    sitemapsSummary: {
        total_groups: number;
        dirty_groups: number;
        total_urls: number;
        last_generated_at: string | null;
    };
    organicGrowth: {
        window_days: number;
        funnel: {
            organic_visit: number;
            post_view: number;
            register_click: number;
            registration: number;
            first_interaction: number;
            join_community: number;
            premium_purchase: number;
        };
        visit_to_signup_rate: number;
        by_locale: Array<{ locale: string; visits: number }>;
        top_landing_pages: Array<{
            landing_path: string;
            visits: number;
            registrations: number;
        }>;
    };
    recentJobs: Array<{
        id: number;
        job_name: string;
        status: string;
        processed_count: number;
        error_count: number;
        duration_ms: number | null;
        started_at: string;
        error_message: string | null;
    }>;
}>();

const { t, locale } = useI18n();

function switchDays(d: number): void {
    router.get(
        SeoOverviewController.index.url({ query: { days: d } }),
        {},
        { preserveState: true, replace: true },
    );
}

function triggerBackfill(): void {
    router.post(
        SeoUrlsAndSitemapsController.backfillRegistry.url(),
        {},
        { preserveScroll: true },
    );
}

function triggerAudit(): void {
    router.post(
        SeoInternationalAndAuditController.runAuditNow.url(),
        {},
        { preserveScroll: true },
    );
}

function statusBadgeVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (
        status === 'connected' ||
        status === 'verified' ||
        status === 'completed'
    ) {
        return 'default';
    }
    if (status === 'error' || status === 'failed') {
        return 'destructive';
    }
    return 'secondary';
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.seo.overviewTitle')" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1
                        class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        {{ t('admin.seo.overviewTitle') }}
                    </h1>
                    <Badge :variant="indexingAllowed ? 'default' : 'secondary'">
                        {{
                            indexingAllowed
                                ? t('admin.seo.indexingActive')
                                : t('admin.seo.indexingGuarded')
                        }}
                    </Badge>
                    <Badge variant="outline">{{ environment }}</Badge>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.seo.canonicalOrigin') }}:
                    <span class="font-mono text-foreground">{{
                        canonicalOrigin
                    }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex rounded-lg border border-border bg-card p-0.5">
                    <button
                        v-for="d in [7, 28, 90]"
                        :key="d"
                        type="button"
                        :class="[
                            'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                            days === d
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="switchDays(d)"
                    >
                        {{ t('admin.seoCenter.daysShort', { count: d }) }}
                    </button>
                </div>
                <Button variant="outline" size="sm" @click="triggerBackfill">
                    <RefreshCw class="size-3.5" aria-hidden="true" />
                    <span>{{ t('admin.seo.syncRegistry') }}</span>
                </Button>
                <Button size="sm" @click="triggerAudit">
                    <ShieldCheck class="size-3.5" aria-hidden="true" />
                    <span>{{ t('admin.seo.runAudit') }}</span>
                </Button>
            </div>
        </div>

        <!-- Primary KPIs -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('admin.seo.kpiRegisteredUrls') }}</span>
                    <Link2 class="size-4 text-primary" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ kpis.total_urls.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ kpis.eligible_urls.toLocaleString() }}
                    {{ t('admin.seo.eligible') }} ·
                    {{ kpis.excluded_urls.toLocaleString() }}
                    {{ t('admin.seo.excluded') }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('admin.seo.kpiSitemapCoverage') }}</span>
                    <Globe2 class="size-4 text-primary" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ kpis.in_sitemap_urls.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ sitemapsSummary.total_groups }}
                    {{ t('admin.seo.sitemapGroups') }} ·
                    {{ sitemapsSummary.dirty_groups }}
                    {{ t('admin.seo.pendingSync') }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('admin.seo.kpiGoogleIndexed') }}</span>
                    <CheckCircle2 class="size-4 text-primary" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ kpis.indexed_urls.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.seo.notIndexed') }}:
                    {{ kpis.not_indexed_urls.toLocaleString() }} ·
                    {{ t('admin.seoCenter.gscShort') }}:
                    <span class="font-medium">{{ gsc.status }}</span>
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('admin.seo.kpiTechnicalScore') }}</span>
                    <ShieldCheck class="size-4 text-primary" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{
                        latestAudit
                            ? `${latestAudit.technical_health_score}/100`
                            : '—'
                    }}
                </p>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ t('admin.seo.scoreDisclaimer') }}
                </p>
            </div>
        </div>

        <!-- Search Console Analytics & Organic Growth Funnel -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <TrendingUp class="size-4 text-primary" />
                            <span>{{ t('admin.seo.gscPerformance') }}</span>
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ t('admin.seo.gscHonestSubtitle') }}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="SeoIntegrationsController.index.url()">
                            <Plug class="size-3.5" />
                            <span>{{ t('admin.nav.seoIntegrations') }}</span>
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="!searchAnalytics.has_data"
                    class="mt-4 rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center"
                >
                    <p class="text-sm font-medium text-foreground">
                        {{
                            gsc.setup_required
                                ? t('admin.seo.gscNotConnected')
                                : t('admin.seo.gscNoDataYet')
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('admin.seo.gscConnectHint') }}
                    </p>
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-lg bg-muted/40 p-3">
                            <p class="text-[11px] text-muted-foreground">
                                {{ t('admin.seoCenter.clicks') }}
                            </p>
                            <p class="mt-1 text-lg font-bold text-foreground">
                                {{ searchAnalytics.clicks.toLocaleString() }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-3">
                            <p class="text-[11px] text-muted-foreground">
                                {{ t('admin.seoCenter.impressions') }}
                            </p>
                            <p class="mt-1 text-lg font-bold text-foreground">
                                {{
                                    searchAnalytics.impressions.toLocaleString()
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-3">
                            <p class="text-[11px] text-muted-foreground">
                                {{ t('admin.seoCenter.ctr') }}
                            </p>
                            <p class="mt-1 text-lg font-bold text-foreground">
                                {{ searchAnalytics.ctr }}%
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/40 p-3">
                            <p class="text-[11px] text-muted-foreground">
                                {{ t('admin.seoCenter.avgPosition') }}
                            </p>
                            <p class="mt-1 text-lg font-bold text-foreground">
                                {{ searchAnalytics.avg_position }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="searchAnalytics.top_queries.length > 0"
                        class="overflow-x-auto"
                    >
                        <table class="w-full text-left text-xs">
                            <thead
                                class="border-b border-border text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-2 pr-3 font-medium">
                                        {{ t('admin.seoCenter.query') }}
                                    </th>
                                    <th
                                        class="px-2 py-2 text-right font-medium"
                                    >
                                        {{ t('admin.seoCenter.clicks') }}
                                    </th>
                                    <th
                                        class="px-2 py-2 text-right font-medium"
                                    >
                                        {{
                                            t(
                                                'admin.seoCenter.impressionsShort',
                                            )
                                        }}
                                    </th>
                                    <th
                                        class="py-2 pl-2 text-right font-medium"
                                    >
                                        {{ t('admin.seoCenter.positionShort') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                <tr
                                    v-for="q in searchAnalytics.top_queries.slice(
                                        0,
                                        8,
                                    )"
                                    :key="q.query"
                                >
                                    <td
                                        class="py-2 pr-3 font-medium text-foreground"
                                    >
                                        {{ q.query }}
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        {{ q.clicks }}
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        {{ q.impressions }}
                                    </td>
                                    <td class="py-2 pl-2 text-right">
                                        {{ q.position }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section
                class="rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Users class="size-4 text-primary" />
                    <span>{{ t('admin.seo.organicFunnelTitle') }}</span>
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ t('admin.seo.organicFunnelSubtitle') }}
                </p>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div
                        class="rounded-lg border border-border/70 bg-muted/20 p-3"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('admin.seoCenter.organicVisits') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                organicGrowth.funnel.organic_visit.toLocaleString()
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/20 p-3"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('admin.seoCenter.postViews') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                organicGrowth.funnel.post_view.toLocaleString()
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/20 p-3"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('admin.seoCenter.registrations') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                organicGrowth.funnel.registration.toLocaleString()
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/20 p-3"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('admin.seoCenter.communityJoins') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                organicGrowth.funnel.join_community.toLocaleString()
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/20 p-3"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('admin.seoCenter.premiumConversions') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                organicGrowth.funnel.premium_purchase.toLocaleString()
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-primary/25 bg-primary-soft/50 p-3"
                    >
                        <p class="text-[11px] text-primary">
                            {{ t('admin.seoCenter.visitToSignup') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-primary">
                            {{ organicGrowth.visit_to_signup_rate }}%
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Alerts & 15-Locale Distribution -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <AlertTriangle class="size-4 text-amber-500" />
                        <span>{{ t('admin.seo.criticalAlertsTitle') }}</span>
                    </h2>
                    <Button variant="ghost" size="sm" as-child>
                        <Link
                            :href="
                                SeoInternationalAndAuditController.audit.url()
                            "
                        >
                            {{ t('admin.seo.viewAllAudits') }}
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="alerts.length === 0"
                    class="mt-4 rounded-lg bg-muted/30 p-4 text-xs text-muted-foreground"
                >
                    {{ t('admin.seo.noCriticalAlerts') }}
                </div>
                <ul v-else class="mt-3 space-y-2">
                    <li
                        v-for="alert in alerts"
                        :key="alert.id"
                        class="flex items-start justify-between gap-3 rounded-lg border border-border/70 p-3 text-xs"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold text-foreground">
                                {{ alert.title }}
                            </p>
                            <p
                                v-if="alert.url"
                                class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground"
                            >
                                {{ alert.url }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                alert.severity === 'critical'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{ alert.severity }}
                        </Badge>
                    </li>
                </ul>
            </section>

            <section
                class="rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Globe2 class="size-4 text-primary" />
                    <span>{{ t('admin.seo.localeDistribution') }}</span>
                </h2>
                <div
                    v-if="byLocale.length === 0"
                    class="mt-4 rounded-lg bg-muted/30 p-4 text-xs text-muted-foreground"
                >
                    {{ t('admin.seo.registryEmptyHint') }}
                </div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-2 font-medium">
                                    {{ t('admin.seoCenter.locale') }}
                                </th>
                                <th class="px-2 py-2 text-right font-medium">
                                    {{ t('admin.seoCenter.total') }}
                                </th>
                                <th class="px-2 py-2 text-right font-medium">
                                    {{ t('admin.seoCenter.eligible') }}
                                </th>
                                <th class="px-2 py-2 text-right font-medium">
                                    {{ t('admin.seoCenter.sitemap') }}
                                </th>
                                <th class="py-2 pl-2 text-right font-medium">
                                    {{ t('admin.seoCenter.indexed') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            <tr v-for="row in byLocale" :key="row.locale">
                                <td
                                    class="py-2 pr-2 font-mono font-semibold uppercase"
                                >
                                    {{ row.locale }}
                                </td>
                                <td class="px-2 py-2 text-right">
                                    {{ row.total }}
                                </td>
                                <td class="px-2 py-2 text-right">
                                    {{ row.eligible }}
                                </td>
                                <td class="px-2 py-2 text-right">
                                    {{ row.in_sitemap }}
                                </td>
                                <td class="py-2 pl-2 text-right">
                                    {{ row.indexed }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Recent SEO Jobs -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Activity class="size-4 text-primary" />
                <span>{{ t('admin.seo.recentJobsTitle') }}</span>
            </h2>
            <div
                v-if="recentJobs.length === 0"
                class="mt-3 text-xs text-muted-foreground"
            >
                {{ t('admin.seo.noJobsYet') }}
            </div>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3 font-medium">
                                {{ t('admin.seoCenter.job') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.status') }}
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                {{ t('admin.seoCenter.processed') }}
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                {{ t('admin.seoCenter.duration') }}
                            </th>
                            <th class="py-2 pl-2 text-right font-medium">
                                {{ t('admin.seoCenter.started') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="job in recentJobs" :key="job.id">
                            <td class="py-2 pr-3 font-mono text-foreground">
                                {{ job.job_name }}
                            </td>
                            <td class="px-2 py-2">
                                <Badge
                                    :variant="statusBadgeVariant(job.status)"
                                >
                                    {{ job.status }}
                                </Badge>
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ job.processed_count }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{
                                    job.duration_ms !== null
                                        ? t('admin.seoCenter.durationMs', {
                                              ms: job.duration_ms,
                                          })
                                        : '—'
                                }}
                            </td>
                            <td
                                class="py-2 pl-2 text-right text-muted-foreground"
                            >
                                {{ formatDateTime(job.started_at, locale) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
