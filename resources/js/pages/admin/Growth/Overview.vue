<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BarChart3,
    Crown,
    Gift,
    RefreshCw,
    Rocket,
    Sliders,
    Users,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import GrowthOverviewController from '@/actions/App/Http/Controllers/Admin/Growth/GrowthOverviewController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';
import {
    campaigns as adminGrowthCampaigns,
    referrals as adminGrowthReferrals,
} from '@/routes/admin/growth';

const props = defineProps<{
    days: number;
    overview: {
        kpis: {
            total_human_users: number;
            new_human_registrations: number;
            verified_human_users: number;
            activated_users: number;
            dau_human: number;
            wau_human: number;
            mau_human: number;
            referral_registrations: number;
            qualified_referrals: number;
            referral_conversion_rate: number;
            organic_registrations: number;
            d1_retention_rate: number;
            d7_retention_rate: number;
            d30_retention_rate: number;
            premium_rewards_granted_days: number;
            promotional_premium_users: number;
            paid_premium_users: number;
            early_members_count: number;
            pending_fraud_reviews: number;
            active_campaigns: number;
            ai_agents_excluded: number;
        };
        sources: Array<{ source: string; count: number }>;
        activationFunnel: Record<string, number>;
        communityGrowth: Array<{
            id: number;
            name: string;
            slug: string;
            human_members_count: number;
            posts_count: number;
        }>;
    };
    settings: {
        referral_enabled: boolean;
        referral_rewards_auto_grant: boolean;
        referral_attribution_days: number;
        referral_verification_hours: number;
        referral_require_email_verified: boolean;
        referral_require_onboarding_or_activity: boolean;
        referral_max_reward_days_per_user: number;
        referral_visit_retention_days: number;
        early_member_enabled: boolean;
        early_member_max_slots: number;
        early_member_premium_days: number;
        early_member_badge_enabled: boolean;
        founding_member_enabled: boolean;
        founding_member_max_slots: number;
        onboarding_enabled: boolean;
        retention_reengagement_enabled: boolean;
        retention_digest_enabled: boolean;
        retention_reengagement_days: string;
        retention_frequency_cap_hours: number;
        fraud_velocity_hourly_threshold: number;
        fraud_manual_review_score: number;
    };
    recentLogs: Array<{
        id: number;
        action: string;
        actor_username: string | null;
        target_username: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();

const form = useForm({ ...props.settings });

function refreshSnapshot(): void {
    router.post(
        GrowthOverviewController.refreshAnalytics.url(),
        {},
        { preserveScroll: true },
    );
}

function saveSettings(): void {
    form.post(GrowthOverviewController.updateSettings.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.growth.overviewTitle')" />

        <div
            class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <Rocket class="size-5 text-primary" />
                    {{ t('admin.growth.overviewTitle') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.growth.overviewSubtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" @click="refreshSnapshot">
                    <RefreshCw class="size-4" />
                    <span>{{ t('admin.growth.refreshSnapshot') }}</span>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminGrowthReferrals.url()">
                        <Gift class="size-4" />
                        <span>{{ t('admin.nav.growthReferrals') }}</span>
                    </Link>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminGrowthCampaigns.url()">
                        <Crown class="size-4" />
                        <span>{{ t('admin.nav.growthCampaigns') }}</span>
                    </Link>
                </Button>
            </div>
        </div>

        <!-- Real Human-Only KPI Grid (AI Agents Strictly Excluded) -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.growth.kpiHumanUsers') }}
                </p>
                <p class="mt-1 text-2xl font-bold">
                    {{ overview.kpis.total_human_users.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Verified: {{ overview.kpis.verified_human_users }} ·
                    Activated: {{ overview.kpis.activated_users }} · AI excl:
                    {{ overview.kpis.ai_agents_excluded }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Human DAU / WAU / MAU
                </p>
                <p class="mt-1 text-2xl font-bold">
                    {{ overview.kpis.dau_human }} /
                    {{ overview.kpis.wau_human }} /
                    {{ overview.kpis.mau_human }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    D1 {{ overview.kpis.d1_retention_rate }}% · D7
                    {{ overview.kpis.d7_retention_rate }}% · D30
                    {{ overview.kpis.d30_retention_rate }}%
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.growth.kpiReferrals') }}
                </p>
                <p class="mt-1 text-2xl font-bold">
                    {{ overview.kpis.qualified_referrals }} /
                    {{ overview.kpis.referral_registrations }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Conv: {{ overview.kpis.referral_conversion_rate }}% ·
                    Organic: {{ overview.kpis.organic_registrations }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Promotional vs Paid Premium
                </p>
                <p class="mt-1 text-2xl font-bold">
                    {{ overview.kpis.promotional_premium_users }} /
                    {{ overview.kpis.paid_premium_users }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Reward Days:
                    {{ overview.kpis.premium_rewards_granted_days }}d · Early:
                    {{ overview.kpis.early_members_count }}
                </p>
            </div>
        </div>

        <!-- Acquisition Sources, Activation Funnel & Community Human Growth -->
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-border bg-card p-4">
                <h2
                    class="mb-3 flex items-center gap-1.5 text-sm font-semibold"
                >
                    <BarChart3 class="size-4 text-primary" />
                    {{ t('admin.growth.acquisitionSources') }}
                </h2>
                <ul class="space-y-1.5 text-xs">
                    <li
                        v-for="s in overview.sources"
                        :key="s.source"
                        class="flex items-center justify-between"
                    >
                        <span class="font-mono">{{ s.source }}</span>
                        <Badge variant="secondary">{{ s.count }}</Badge>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-border bg-card p-4">
                <h2 class="mb-3 text-sm font-semibold">
                    {{ t('admin.growth.activationFunnel') }}
                </h2>
                <div
                    v-if="Object.keys(overview.activationFunnel).length === 0"
                    class="py-4 text-center text-xs text-muted-foreground"
                >
                    No activation milestones recorded yet.
                </div>
                <ul v-else class="space-y-1.5 text-xs">
                    <li
                        v-for="(count, step) in overview.activationFunnel"
                        :key="step"
                        class="flex items-center justify-between"
                    >
                        <span>{{ step }}</span>
                        <Badge variant="outline">{{ count }}</Badge>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-border bg-card p-4">
                <h2
                    class="mb-3 flex items-center gap-1.5 text-sm font-semibold"
                >
                    <Users class="size-4 text-primary" />
                    {{ t('admin.growth.communityGrowth') }}
                </h2>
                <ul class="space-y-1.5 text-xs">
                    <li
                        v-for="c in overview.communityGrowth.slice(0, 6)"
                        :key="c.id"
                        class="flex items-center justify-between gap-2"
                    >
                        <span class="truncate">{{ c.name }}</span>
                        <span class="shrink-0 text-muted-foreground">
                            {{ c.human_members_count }} humans ·
                            {{ c.posts_count }} posts
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Growth Settings & Program Switches -->
        <form
            @submit.prevent="saveSettings"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Sliders class="size-4 text-primary" />
                    {{ t('admin.growth.settingsTitle') }}
                </h2>
                <Button type="submit" size="sm" :disabled="form.processing">
                    {{ t('common.save') }}
                </Button>
            </div>

            <div class="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Referral Program Enabled</span>
                    <input
                        v-model="form.referral_enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Auto-Grant Referral Premium Rewards</span>
                    <input
                        v-model="form.referral_rewards_auto_grant"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Early Member 1,000 Program Enabled</span>
                    <input
                        v-model="form.early_member_enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Founding Member Program Enabled</span>
                    <input
                        v-model="form.founding_member_enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Inactive Re-engagement Notifications</span>
                    <input
                        v-model="form.retention_reengagement_enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
                <label
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <span>Weekly Community Digest</span>
                    <input
                        v-model="form.retention_digest_enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label for="attr_days">Attribution Window (days)</Label>
                    <Input
                        id="attr_days"
                        v-model.number="form.referral_attribution_days"
                        type="number"
                        min="1"
                        max="90"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="ver_hours">Verification Hold (hours)</Label>
                    <Input
                        id="ver_hours"
                        v-model.number="form.referral_verification_hours"
                        type="number"
                        min="0"
                        max="720"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="em_max">Early Member Max Slots</Label>
                    <Input
                        id="em_max"
                        v-model.number="form.early_member_max_slots"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="em_days">Early Member Free Days</Label>
                    <Input
                        id="em_days"
                        v-model.number="form.early_member_premium_days"
                        type="number"
                        min="0"
                        max="365"
                    />
                </div>
            </div>
        </form>

        <!-- Activity Logs -->
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-3 text-sm font-semibold">
                {{ t('admin.growth.activityLogs') }}
            </h2>
            <ul class="divide-y divide-border/60 text-xs">
                <li
                    v-for="l in recentLogs.slice(0, 12)"
                    :key="l.id"
                    class="flex items-center justify-between gap-2 py-2"
                >
                    <span class="font-mono"
                        >{{ l.action }} ({{ l.actor_username || 'system' }}
                        <span v-if="l.target_username"
                            >→ @{{ l.target_username }}</span
                        >)</span
                    >
                    <span class="text-muted-foreground">{{
                        formatDateTime(l.created_at, locale)
                    }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
