<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    Bot,
    CheckCircle2,
    Key,
    Languages,
    ListChecks,
    Play,
    ShieldAlert,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AiOverviewController from '@/actions/App/Http/Controllers/Admin/Ai/AiOverviewController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';
import {
    agents as adminAiAgents,
    assist as adminAiAssist,
    content as adminAiContent,
    credentials as adminAiCredentials,
} from '@/routes/admin/ai';

const props = defineProps<{
    days: number;
    connectionStatus: 'setup_required' | 'connected' | 'degraded' | 'untested';
    control: {
        emergency_stop: boolean;
        switches: Record<string, boolean>;
        compliance: Record<string, boolean>;
        limits: Record<string, number>;
        usage_today: {
            requests: number;
            tokens: number;
            estimated_cost_micro_usd: number;
            monthly_estimated_cost_micro_usd: number;
            budget_allowed: boolean;
            budget_reason: string | null;
        };
    };
    kpis: {
        total_credentials: number;
        enabled_credentials: number;
        healthy_credentials: number;
        active_agents: number;
        total_agents: number;
        drafts_pending_review: number;
        drafts_approved: number;
        drafts_scheduled: number;
        drafts_waiting_quota: number;
        published_ai_posts: number;
        ai_translations: number;
        replies_pending: number;
        moderation_pending: number;
        failed_jobs: number;
    };
    usageByModel: Array<{
        model_name: string;
        requests: number;
        successes: number;
        input_tokens: number;
        output_tokens: number;
        thinking_tokens: number;
        estimated_cost_micro_usd: number;
        pricing_verified: boolean;
    }>;
    usageByLanguage: Array<{
        language_code: string;
        requests: number;
        tokens: number;
    }>;
    recentActivity: Array<{
        id: number;
        action: string;
        actor_username: string | null;
        agent_name: string | null;
        metadata: Record<string, unknown> | null;
        created_at: string;
    }>;
    recentUsage: Array<{
        id: number;
        task_type: string;
        model_name: string;
        project_id: string | null;
        agent_name: string | null;
        language_code: string | null;
        status: string;
        http_status: number | null;
        input_tokens: number;
        output_tokens: number;
        thinking_tokens: number;
        estimated_cost_micro_usd: number;
        pricing_verified: boolean;
        duration_ms: number;
        error_code: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();
const emergencyDialogOpen = ref(false);
const emergencyProcessing = ref(false);

const settingsForm = useForm({
    ai_generation_enabled: Boolean(
        props.control.switches.ai_generation_enabled,
    ),
    ai_publishing_enabled: Boolean(
        props.control.switches.ai_publishing_enabled,
    ),
    ai_translation_enabled: Boolean(
        props.control.switches.ai_translation_enabled,
    ),
    ai_replies_enabled: Boolean(props.control.switches.ai_replies_enabled),
    ai_moderation_enabled: Boolean(
        props.control.switches.ai_moderation_enabled,
    ),
    ai_research_enabled: Boolean(props.control.switches.ai_research_enabled),
    compliance_user_facing_verified: Boolean(
        props.control.compliance.compliance_user_facing_verified,
    ),
    compliance_eea_paid_tier_verified: Boolean(
        props.control.compliance.compliance_eea_paid_tier_verified,
    ),
    require_human_review_before_publish: Boolean(
        props.control.compliance.require_human_review_before_publish,
    ),
    allow_paid_model_fallback: Boolean(
        props.control.compliance.allow_paid_model_fallback,
    ),
    max_generated_drafts_per_day:
        props.control.limits.max_generated_drafts_per_day ?? 10,
    max_published_posts_per_day:
        props.control.limits.max_published_posts_per_day ?? 5,
    max_translations_per_day:
        props.control.limits.max_translations_per_day ?? 10,
    max_ai_replies_per_day: props.control.limits.max_ai_replies_per_day ?? 5,
    max_ai_replies_per_post: props.control.limits.max_ai_replies_per_post ?? 2,
    max_daily_tokens: props.control.limits.max_daily_tokens ?? 500000,
    max_daily_cost_cents: props.control.limits.max_daily_cost_cents ?? 0,
    max_monthly_cost_cents: props.control.limits.max_monthly_cost_cents ?? 0,
    max_ai_feed_share_percent:
        props.control.limits.max_ai_feed_share_percent ?? 30,
});

function formatMicroUsd(micro: number): string {
    return `$${(micro / 1_000_000).toFixed(4)}`;
}

const statusMeta = computed(() => {
    switch (props.connectionStatus) {
        case 'connected':
            return {
                label: t('admin.ai.statusConnected'),
                variant: 'default' as const,
            };
        case 'degraded':
            return {
                label: t('admin.ai.statusDegraded'),
                variant: 'destructive' as const,
            };
        case 'untested':
            return {
                label: t('admin.ai.statusUntested'),
                variant: 'secondary' as const,
            };
        default:
            return {
                label: t('admin.ai.statusSetupRequired'),
                variant: 'outline' as const,
            };
    }
});

function confirmEmergencyToggle(): void {
    emergencyProcessing.value = true;
    router.post(
        AiOverviewController.toggleEmergencyStop.url(),
        {
            stop: !props.control.emergency_stop,
            reason: props.control.emergency_stop
                ? 'Released from Admin AI Overview'
                : 'Manual Emergency Stop from Admin AI Overview',
        },
        {
            preserveScroll: true,
            onFinish: () => {
                emergencyProcessing.value = false;
                emergencyDialogOpen.value = false;
            },
        },
    );
}

function submitSettings(): void {
    settingsForm.post(AiOverviewController.updateSettings.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.ai.overviewTitle')" />

        <!-- Header + Emergency Stop Banner -->
        <div
            class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <Bot class="size-5 text-primary" />
                    <h1 class="text-xl font-bold text-foreground">
                        {{ t('admin.ai.overviewTitle') }}
                    </h1>
                    <Badge :variant="statusMeta.variant">
                        {{ statusMeta.label }}
                    </Badge>
                    <Badge
                        v-if="control.emergency_stop"
                        variant="destructive"
                        class="gap-1"
                    >
                        <AlertOctagon class="size-3" />
                        {{ t('admin.ai.emergencyStopActive') }}
                    </Badge>
                </div>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ t('admin.ai.overviewSubtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminAiCredentials.url()">
                        <Key class="size-4" />
                        <span>{{ t('admin.nav.aiCredentials') }}</span>
                    </Link>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminAiAgents.url()">
                        <Sparkles class="size-4" />
                        <span>{{ t('admin.nav.aiAgents') }}</span>
                    </Link>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminAiContent.url()">
                        <ListChecks class="size-4" />
                        <span>{{ t('admin.nav.aiContent') }}</span>
                    </Link>
                </Button>
                <Button
                    :variant="
                        control.emergency_stop ? 'default' : 'destructive'
                    "
                    size="sm"
                    @click="emergencyDialogOpen = true"
                >
                    <Play v-if="control.emergency_stop" class="size-4" />
                    <AlertOctagon v-else class="size-4" />
                    <span>
                        {{
                            control.emergency_stop
                                ? t('admin.ai.releaseEmergencyStop')
                                : t('admin.ai.activateEmergencyStop')
                        }}
                    </span>
                </Button>
            </div>
        </div>

        <!-- Setup Required Callout (Honest status — never fake Active) -->
        <div
            v-if="connectionStatus === 'setup_required'"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm"
        >
            <div class="flex items-start gap-2.5">
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                />
                <div>
                    <p class="font-semibold text-foreground">
                        {{ t('admin.ai.setupRequiredTitle') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.ai.setupRequiredDesc') }}
                    </p>
                </div>
            </div>
            <Button size="sm" as-child>
                <Link :href="adminAiCredentials.url()">
                    {{ t('admin.ai.addFirstKey') }}
                </Link>
            </Button>
        </div>

        <!-- KPI Grid -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('admin.ai.kpiCredentials') }}
                </p>
                <p class="mt-1.5 text-2xl font-bold text-foreground">
                    {{ kpis.healthy_credentials }} /
                    {{ kpis.total_credentials }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.ai.kpiHealthyEnabled') }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('admin.ai.kpiAgents') }}
                </p>
                <p class="mt-1.5 text-2xl font-bold text-foreground">
                    {{ kpis.active_agents }} / {{ kpis.total_agents }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.ai.kpiPublishedPosts') }}:
                    {{ kpis.published_ai_posts }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('admin.ai.kpiReviewQueue') }}
                </p>
                <p class="mt-1.5 text-2xl font-bold text-foreground">
                    {{ kpis.drafts_pending_review }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.ai.kpiScheduled') }}:
                    {{ kpis.drafts_scheduled }} ·
                    {{ t('admin.ai.kpiWaitingQuota') }}:
                    {{ kpis.drafts_waiting_quota }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('admin.ai.kpiTodayUsage') }}
                </p>
                <p class="mt-1.5 text-2xl font-bold text-foreground">
                    {{ control.usage_today.tokens.toLocaleString() }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        t('admin.aiCenter.requestsAndEstCost', {
                            count: control.usage_today.requests,
                            cost: formatMicroUsd(
                                control.usage_today.estimated_cost_micro_usd,
                            ),
                        })
                    }}
                </p>
            </div>
        </div>

        <!-- Feature Switches, Compliance Review & Budget Guardrails -->
        <form
            @submit.prevent="submitSettings"
            class="space-y-5 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-foreground">
                        {{ t('admin.ai.guardrailsTitle') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.ai.guardrailsDesc') }}
                    </p>
                </div>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="settingsForm.processing"
                >
                    {{ t('common.save') }}
                </Button>
            </div>

            <div
                v-if="settingsForm.errors.ai_replies_enabled"
                class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive"
            >
                {{ settingsForm.errors.ai_replies_enabled }}
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <!-- 6 Granular Automation Switches -->
                <div class="space-y-3 rounded-lg border border-border p-4">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('admin.ai.switchesHeading') }}
                    </h3>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchGeneration') }}</span>
                        <input
                            v-model="settingsForm.ai_generation_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchPublishing') }}</span>
                        <input
                            v-model="settingsForm.ai_publishing_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchTranslation') }}</span>
                        <input
                            v-model="settingsForm.ai_translation_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchReplies') }}</span>
                        <input
                            v-model="settingsForm.ai_replies_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchModeration') }}</span>
                        <input
                            v-model="settingsForm.ai_moderation_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ t('admin.ai.switchResearch') }}</span>
                        <input
                            v-model="settingsForm.ai_research_enabled"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                </div>

                <!-- Compliance & Safety Checkboxes -->
                <div class="space-y-3 rounded-lg border border-border p-4">
                    <h3
                        class="flex items-center gap-1.5 text-sm font-semibold text-foreground"
                    >
                        <ShieldAlert class="size-4 text-primary" />
                        {{ t('admin.ai.complianceHeading') }}
                    </h3>
                    <label
                        class="flex items-start justify-between gap-3 text-xs"
                    >
                        <span>{{ t('admin.ai.complianceRequireHuman') }}</span>
                        <input
                            v-model="
                                settingsForm.require_human_review_before_publish
                            "
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-start justify-between gap-3 text-xs"
                    >
                        <span>{{ t('admin.ai.complianceUserFacing') }}</span>
                        <input
                            v-model="
                                settingsForm.compliance_user_facing_verified
                            "
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-start justify-between gap-3 text-xs"
                    >
                        <span>{{ t('admin.ai.complianceEeaPaid') }}</span>
                        <input
                            v-model="
                                settingsForm.compliance_eea_paid_tier_verified
                            "
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                        />
                    </label>
                    <label
                        class="flex items-start justify-between gap-3 text-xs"
                    >
                        <span>{{ t('admin.ai.compliancePaidFallback') }}</span>
                        <input
                            v-model="settingsForm.allow_paid_model_fallback"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                        />
                    </label>
                </div>
            </div>

            <!-- Numeric Limits & Budget Ceilings -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="space-y-1">
                    <Label for="max_drafts">{{
                        t('admin.ai.limitDraftsPerDay')
                    }}</Label>
                    <Input
                        id="max_drafts"
                        v-model.number="
                            settingsForm.max_generated_drafts_per_day
                        "
                        type="number"
                        min="1"
                        max="100"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="max_pub">{{
                        t('admin.ai.limitPublishedPerDay')
                    }}</Label>
                    <Input
                        id="max_pub"
                        v-model.number="
                            settingsForm.max_published_posts_per_day
                        "
                        type="number"
                        min="1"
                        max="50"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="max_trans">{{
                        t('admin.ai.limitTranslationsPerDay')
                    }}</Label>
                    <Input
                        id="max_trans"
                        v-model.number="settingsForm.max_translations_per_day"
                        type="number"
                        min="1"
                        max="150"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="max_replies">{{
                        t('admin.ai.limitRepliesPerDay')
                    }}</Label>
                    <Input
                        id="max_replies"
                        v-model.number="settingsForm.max_ai_replies_per_day"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="max_tokens">{{
                        t('admin.ai.limitDailyTokens')
                    }}</Label>
                    <Input
                        id="max_tokens"
                        v-model.number="settingsForm.max_daily_tokens"
                        type="number"
                        min="10000"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="max_monthly_cents">{{
                        t('admin.ai.limitMonthlyCents')
                    }}</Label>
                    <Input
                        id="max_monthly_cents"
                        v-model.number="settingsForm.max_monthly_cost_cents"
                        type="number"
                        min="0"
                    />
                </div>
            </div>
        </form>

        <!-- Usage Analytics & Activity Logs -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-border bg-card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Activity class="size-4 text-primary" />
                        {{ t('admin.ai.usageByModel') }} ({{
                            t('admin.aiCenter.daysShort', { count: days })
                        }})
                    </h2>
                    <Link
                        :href="adminAiAssist.url()"
                        class="text-xs font-medium text-primary hover:underline"
                    >
                        <Languages class="mr-1 inline size-3.5" />
                        {{ t('admin.nav.aiAssist') }}
                    </Link>
                </div>
                <div
                    v-if="usageByModel.length === 0"
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    {{ t('admin.ai.noUsageYet') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr
                                class="border-b border-border text-muted-foreground"
                            >
                                <th class="py-2 pr-2">
                                    {{ t('admin.aiCenter.model') }}
                                </th>
                                <th class="px-2 py-2">
                                    {{ t('admin.aiCenter.reqShort') }}
                                </th>
                                <th class="px-2 py-2">
                                    {{ t('admin.aiCenter.tokens') }}
                                </th>
                                <th class="py-2 pl-2 text-right">
                                    {{ t('admin.aiCenter.estCost') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in usageByModel"
                                :key="row.model_name"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="py-2 pr-2 font-mono font-medium">
                                    {{ row.model_name }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ row.successes }}/{{ row.requests }}
                                </td>
                                <td class="px-2 py-2">
                                    {{
                                        (
                                            row.input_tokens +
                                            row.output_tokens +
                                            row.thinking_tokens
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="py-2 pl-2 text-right">
                                    <span v-if="row.pricing_verified">
                                        {{
                                            formatMicroUsd(
                                                row.estimated_cost_micro_usd,
                                            )
                                        }}
                                    </span>
                                    <span v-else class="text-muted-foreground">
                                        {{ t('admin.ai.unverifiedPricing') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <CheckCircle2 class="size-4 text-primary" />
                    {{ t('admin.ai.recentActivity') }}
                </h2>
                <div
                    v-if="recentActivity.length === 0"
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    {{ t('admin.ai.noActivityYet') }}
                </div>
                <ul v-else class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="item in recentActivity.slice(0, 10)"
                        :key="item.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate font-mono font-medium text-foreground"
                            >
                                {{ item.action }}
                            </p>
                            <p class="truncate text-muted-foreground">
                                {{
                                    item.actor_username ||
                                    t('admin.aiCenter.systemActor')
                                }}
                                <span v-if="item.agent_name">
                                    · {{ item.agent_name }}
                                </span>
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-[11px] text-muted-foreground"
                        >
                            {{ formatDateTime(item.created_at, locale) }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <ConfirmActionDialog
            v-model:open="emergencyDialogOpen"
            :title="
                control.emergency_stop
                    ? t('admin.ai.releaseEmergencyStop')
                    : t('admin.ai.activateEmergencyStop')
            "
            :description="t('admin.ai.emergencyStopConfirmDesc')"
            :confirm-label="
                control.emergency_stop
                    ? t('admin.ai.releaseEmergencyStop')
                    : t('admin.ai.activateEmergencyStop')
            "
            :destructive="!control.emergency_stop"
            :processing="emergencyProcessing"
            @confirm="confirmEmergencyToggle"
        />
    </div>
</template>
