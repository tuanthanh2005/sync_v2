<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Flame,
    Play,
    RotateCcw,
    Save,
    Search,
    ShieldAlert,
    Sliders,
    Sparkles,
    TrendingUp,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatNumber } from '@/lib/formatters';
import { appToast } from '@/lib/toast';
import { settings as settingsRoute } from '@/routes/admin/content';
import {
    reset as resetSettingsRoute,
    simulate as simulateRoute,
    update as updateSettingsRoute,
} from '@/routes/admin/content/settings';

type SpamEventRow = {
    id: number;
    event_type: string;
    action_context: string;
    risk_score: number;
    risk_level: string;
    decision: string;
    trust_tier: string | null;
    signals: Record<string, unknown>;
    user: { id: number; name: string; username: string } | null;
    post_id: number | null;
    created_at: string | null;
};

type AuditLogRow = {
    id: number;
    action: string;
    actor_username: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
};

type SimulationResult = {
    effective_human_views: number;
    dampening_applied: boolean;
    age_hours: number;
    decay_factor: number;
    raw_score: number;
    trending_score: number;
    breakdown: {
        human_views_score: number;
        reactions_score: number;
        comments_score: number;
        bookmarks_score: number;
        shares_score: number;
    };
};

const props = defineProps<{
    settings: Record<string, string | number | boolean>;
    defaults: Record<string, string | number | boolean>;
    stats: {
        published_24h: number;
        pending_review_count: number;
        rejected_count: number;
        hidden_count: number;
        spam_events_24h: number;
        rate_limited_24h: number;
        duplicate_blocked_24h: number;
        ranked_active_count: number;
    };
    spamEvents: {
        data: SpamEventRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        event_type: string;
        risk_level: string;
        search: string;
    };
    recentAuditLogs: AuditLogRow[];
    canManage: boolean;
    canSimulate: boolean;
}>();

const { t, locale } = useI18n();

const activeSection = ref<
    'publishing' | 'antispam' | 'moderation' | 'feed' | 'trending' | 'simulator' | 'telemetry'
>('publishing');

type PostFeedSettingsForm = {
    feature_posting: boolean;
    content_post_max_length: number;
    content_comment_max_length: number;
    post_edits_per_day_limit: number;
    post_edit_window_minutes: number;
    posting_new_member_age_days: number;
    posting_limit_24h_new: number;
    posting_limit_24h_standard: number;
    posting_limit_24h_premium: number;
    posting_limit_60m_new: number;
    posting_limit_60m_standard: number;
    posting_limit_60m_premium: number;
    posting_min_interval_new: number;
    posting_min_interval_standard: number;
    posting_min_interval_premium: number;
    comment_limit_24h_new: number;
    comment_limit_24h_standard: number;
    comment_limit_24h_premium: number;
    post_max_images_new: number;
    post_max_images_standard: number;
    post_max_images_premium: number;
    antispam_enabled: boolean;
    duplicate_detection_enabled: boolean;
    duplicate_detection_window_hours: number;
    duplicate_similarity_threshold: number;
    antispam_medium_threshold: number;
    antispam_high_threshold: number;
    antispam_violation_threshold: number;
    antispam_max_links_per_post: number;
    antispam_max_mentions_per_post: number;
    antispam_max_hashtags_per_post: number;
    antispam_temp_restriction_hours: number;
    moderation_risk_based_enabled: boolean;
    moderation_require_manual_review: boolean;
    moderation_review_threshold: number;
    moderation_ai_assist_enabled: boolean;
    moderation_suspicious_link_action: string;
    moderation_violation_action: string;
    feed_enable_trending: boolean;
    feed_enable_recommended: boolean;
    feed_enable_fresh_discovery: boolean;
    feed_candidate_window_hours: number;
    feed_refresh_interval_minutes: number;
    feed_page_size: number;
    feed_author_diversity_max: number;
    feed_category_diversity_enabled: boolean;
    feed_allocation_ranking_pct: number;
    feed_allocation_fresh_pct: number;
    feed_allocation_discovery_pct: number;
    feed_ad_spacing_posts: number;
    trending_weight_human_view: number;
    trending_weight_reaction: number;
    trending_weight_comment: number;
    trending_weight_bookmark: number;
    trending_weight_share: number;
    trending_decay_base_hours: number;
    trending_decay_exponent: number;
    trending_per_user_contribution_cap: number;
    trending_view_engagement_ratio: number;
};

const form = useForm<PostFeedSettingsForm>({
    feature_posting: Boolean(props.settings.feature_posting ?? true),
    content_post_max_length: Number(props.settings.content_post_max_length ?? 3000),
    content_comment_max_length: Number(props.settings.content_comment_max_length ?? 1500),
    post_edits_per_day_limit: Number(props.settings.post_edits_per_day_limit ?? 15),
    post_edit_window_minutes: Number(props.settings.post_edit_window_minutes ?? 0),
    posting_new_member_age_days: Number(props.settings.posting_new_member_age_days ?? 7),
    posting_limit_24h_new: Number(props.settings.posting_limit_24h_new ?? 10),
    posting_limit_24h_standard: Number(props.settings.posting_limit_24h_standard ?? 30),
    posting_limit_24h_premium: Number(props.settings.posting_limit_24h_premium ?? 100),
    posting_limit_60m_new: Number(props.settings.posting_limit_60m_new ?? 3),
    posting_limit_60m_standard: Number(props.settings.posting_limit_60m_standard ?? 10),
    posting_limit_60m_premium: Number(props.settings.posting_limit_60m_premium ?? 20),
    posting_min_interval_new: Number(props.settings.posting_min_interval_new ?? 60),
    posting_min_interval_standard: Number(props.settings.posting_min_interval_standard ?? 30),
    posting_min_interval_premium: Number(props.settings.posting_min_interval_premium ?? 15),
    comment_limit_24h_new: Number(props.settings.comment_limit_24h_new ?? 50),
    comment_limit_24h_standard: Number(props.settings.comment_limit_24h_standard ?? 200),
    comment_limit_24h_premium: Number(props.settings.comment_limit_24h_premium ?? 500),
    post_max_images_new: Number(props.settings.post_max_images_new ?? 4),
    post_max_images_standard: Number(props.settings.post_max_images_standard ?? 4),
    post_max_images_premium: Number(props.settings.post_max_images_premium ?? 8),
    antispam_enabled: Boolean(props.settings.antispam_enabled ?? true),
    duplicate_detection_enabled: Boolean(props.settings.duplicate_detection_enabled ?? true),
    duplicate_detection_window_hours: Number(props.settings.duplicate_detection_window_hours ?? 24),
    duplicate_similarity_threshold: Number(props.settings.duplicate_similarity_threshold ?? 85),
    antispam_medium_threshold: Number(props.settings.antispam_medium_threshold ?? 30),
    antispam_high_threshold: Number(props.settings.antispam_high_threshold ?? 60),
    antispam_violation_threshold: Number(props.settings.antispam_violation_threshold ?? 85),
    antispam_max_links_per_post: Number(props.settings.antispam_max_links_per_post ?? 5),
    antispam_max_mentions_per_post: Number(props.settings.antispam_max_mentions_per_post ?? 10),
    antispam_max_hashtags_per_post: Number(props.settings.antispam_max_hashtags_per_post ?? 10),
    antispam_temp_restriction_hours: Number(props.settings.antispam_temp_restriction_hours ?? 6),
    moderation_risk_based_enabled: Boolean(props.settings.moderation_risk_based_enabled ?? true),
    moderation_require_manual_review: Boolean(props.settings.moderation_require_manual_review ?? false),
    moderation_review_threshold: Number(props.settings.moderation_review_threshold ?? 60),
    moderation_ai_assist_enabled: Boolean(props.settings.moderation_ai_assist_enabled ?? false),
    moderation_suspicious_link_action: String(props.settings.moderation_suspicious_link_action ?? 'flag'),
    moderation_violation_action: String(props.settings.moderation_violation_action ?? 'reject'),
    feed_enable_trending: Boolean(props.settings.feed_enable_trending ?? true),
    feed_enable_recommended: Boolean(props.settings.feed_enable_recommended ?? true),
    feed_enable_fresh_discovery: Boolean(props.settings.feed_enable_fresh_discovery ?? true),
    feed_candidate_window_hours: Number(props.settings.feed_candidate_window_hours ?? 168),
    feed_refresh_interval_minutes: Number(props.settings.feed_refresh_interval_minutes ?? 5),
    feed_page_size: Number(props.settings.feed_page_size ?? 10),
    feed_author_diversity_max: Number(props.settings.feed_author_diversity_max ?? 2),
    feed_category_diversity_enabled: Boolean(props.settings.feed_category_diversity_enabled ?? true),
    feed_allocation_ranking_pct: Number(props.settings.feed_allocation_ranking_pct ?? 70),
    feed_allocation_fresh_pct: Number(props.settings.feed_allocation_fresh_pct ?? 20),
    feed_allocation_discovery_pct: Number(props.settings.feed_allocation_discovery_pct ?? 10),
    feed_ad_spacing_posts: Number(props.settings.feed_ad_spacing_posts ?? 6),
    trending_weight_human_view: Number(props.settings.trending_weight_human_view ?? 0.1),
    trending_weight_reaction: Number(props.settings.trending_weight_reaction ?? 2.0),
    trending_weight_comment: Number(props.settings.trending_weight_comment ?? 4.0),
    trending_weight_bookmark: Number(props.settings.trending_weight_bookmark ?? 5.0),
    trending_weight_share: Number(props.settings.trending_weight_share ?? 6.0),
    trending_decay_base_hours: Number(props.settings.trending_decay_base_hours ?? 24.0),
    trending_decay_exponent: Number(props.settings.trending_decay_exponent ?? 1.5),
    trending_per_user_contribution_cap: Number(props.settings.trending_per_user_contribution_cap ?? 20),
    trending_view_engagement_ratio: Number(props.settings.trending_view_engagement_ratio ?? 200),
});

const resetDialogOpen = ref(false);
const resetSectionTarget = ref<'all' | 'publishing' | 'antispam' | 'moderation' | 'feed' | 'trending'>('all');
const resetting = ref(false);

const simulatorInput = reactive({
    human_views: 1200,
    reactions: 45,
    comments: 14,
    bookmarks: 9,
    shares: 6,
    age_hours: 4,
});
const simLoading = ref(false);
const simResult = ref<SimulationResult | null>(null);

const filterState = reactive({
    event_type: props.filters.event_type || 'all',
    risk_level: props.filters.risk_level || 'all',
    search: props.filters.search || '',
});

const allocationSum = computed(
    () =>
        Number(form.feed_allocation_ranking_pct) +
        Number(form.feed_allocation_fresh_pct) +
        Number(form.feed_allocation_discovery_pct),
);

function submitSettings(): void {
    if (!props.canManage) {
        return;
    }

    form.post(updateSettingsRoute.url(), {
        preserveScroll: true,
    });
}

function promptReset(section: 'all' | 'publishing' | 'antispam' | 'moderation' | 'feed' | 'trending'): void {
    resetSectionTarget.value = section;
    resetDialogOpen.value = true;
}

function executeReset(): void {
    resetting.value = true;
    router.post(
        resetSettingsRoute.url(),
        { section: resetSectionTarget.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['settings'] });
            },
            onFinish: () => {
                resetting.value = false;
                resetDialogOpen.value = false;
            },
        },
    );
}

async function runSimulation(): Promise<void> {
    if (!props.canSimulate) {
        return;
    }

    simLoading.value = true;
    try {
        const csrfToken =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';

        const response = await fetch(simulateRoute.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                ...simulatorInput,
                weights: {
                    human_view: Number(form.trending_weight_human_view),
                    reaction: Number(form.trending_weight_reaction),
                    comment: Number(form.trending_weight_comment),
                    bookmark: Number(form.trending_weight_bookmark),
                    share: Number(form.trending_weight_share),
                    decay_base_hours: Number(form.trending_decay_base_hours),
                    decay_exponent: Number(form.trending_decay_exponent),
                    per_user_cap: Number(form.trending_per_user_contribution_cap),
                    view_engagement_ratio: Number(form.trending_view_engagement_ratio),
                },
            }),
        });

        if (!response.ok) {
            appToast.error(t('admin.postFeed.simulatorError'));
            return;
        }

        const data = (await response.json()) as { simulation?: SimulationResult };
        if (data.simulation) {
            simResult.value = data.simulation;
        }
    } catch {
        appToast.error(t('admin.postFeed.simulatorError'));
    } finally {
        simLoading.value = false;
    }
}

function applyTelemetryFilter(): void {
    activeSection.value = 'telemetry';
    router.get(
        settingsRoute.url(),
        {
            event_type: filterState.event_type !== 'all' ? filterState.event_type : undefined,
            risk_level: filterState.risk_level !== 'all' ? filterState.risk_level : undefined,
            search: filterState.search.trim() || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.postFeed.title')" />

        <!-- Page Header -->
        <div
            class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold text-foreground">
                    <Sliders class="size-5 text-primary" />
                    <span>{{ t('admin.postFeed.title') }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.postFeed.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="canManage"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-9 gap-1.5 rounded-lg text-xs"
                    @click="promptReset('all')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetAll') }}</span>
                </Button>
                <Button
                    v-if="canManage"
                    type="button"
                    size="sm"
                    class="h-9 gap-1.5 rounded-lg text-xs font-semibold"
                    :disabled="form.processing"
                    @click="submitSettings"
                >
                    <Save class="size-3.5" />
                    <span>{{
                        form.processing
                            ? t('admin.common.saving')
                            : t('admin.common.saveChanges')
                    }}</span>
                </Button>
            </div>
        </div>

        <!-- Operational Summary Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.postFeed.stats.published24h') }}
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-foreground">
                    {{ formatNumber(stats.published_24h, locale) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.postFeed.stats.pendingReview') }}
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-warning">
                    {{ formatNumber(stats.pending_review_count, locale) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.postFeed.stats.rateLimited24h') }}
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-destructive">
                    {{ formatNumber(stats.rate_limited_24h, locale) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.postFeed.stats.duplicatesBlocked24h') }}
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-primary">
                    {{ formatNumber(stats.duplicate_blocked_24h, locale) }}
                </p>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div
            class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto rounded-xl border border-border bg-card p-2 sm:flex-wrap"
        >
            <Button
                v-for="section in [
                    { key: 'publishing', icon: BarChart3 },
                    { key: 'antispam', icon: ShieldAlert },
                    { key: 'moderation', icon: Activity },
                    { key: 'feed', icon: Sparkles },
                    { key: 'trending', icon: Flame },
                    { key: 'simulator', icon: TrendingUp },
                    { key: 'telemetry', icon: Search },
                ]"
                :key="section.key"
                type="button"
                size="sm"
                :variant="activeSection === section.key ? 'default' : 'ghost'"
                class="h-9 shrink-0 gap-1.5 rounded-lg px-3 text-xs whitespace-nowrap"
                @click="activeSection = section.key as typeof activeSection"
            >
                <component :is="section.icon" class="size-3.5" />
                <span>{{ t(`admin.postFeed.tabs.${section.key}`) }}</span>
            </Button>
        </div>

        <!-- Section 1: Publishing Limits & Quotas -->
        <form
            v-show="activeSection === 'publishing'"
            @submit.prevent="submitSettings"
            class="space-y-5 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">
                        {{ t('admin.postFeed.publishing.title') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.postFeed.publishing.subtitle') }}
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs"
                    @click="promptReset('publishing')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetSection') }}</span>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs sm:col-span-2 lg:col-span-4">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.publishing.enablePosting') }}</span>
                    <input
                        v-model="form.feature_posting"
                        type="checkbox"
                        :disabled="!canManage"
                        class="size-4 accent-primary"
                    />
                </label>

                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.publishing.maxPostLength') }}</Label>
                    <Input v-model.number="form.content_post_max_length" type="number" min="100" max="10000" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.publishing.maxCommentLength') }}</Label>
                    <Input v-model.number="form.content_comment_max_length" type="number" min="100" max="5000" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.publishing.editsPerDay') }}</Label>
                    <Input v-model.number="form.post_edits_per_day_limit" type="number" min="1" max="200" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.publishing.newMemberAgeDays') }}</Label>
                    <Input v-model.number="form.posting_new_member_age_days" type="number" min="1" max="90" :disabled="!canManage" class="h-9 text-xs" />
                </div>
            </div>

            <!-- Trust Tier Matrix -->
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-start text-xs">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.tier') }}</th>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.posts24h') }}</th>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.posts60m') }}</th>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.minInterval') }}</th>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.comments24h') }}</th>
                            <th class="px-3 py-2.5 text-start font-semibold">{{ t('admin.postFeed.publishing.maxImages') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr>
                            <td class="px-3 py-2.5 font-medium text-foreground">{{ t('admin.postFeed.publishing.tiers.new') }}</td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_24h_new" type="number" min="1" max="200" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_60m_new" type="number" min="1" max="50" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_min_interval_new" type="number" min="0" max="3600" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.comment_limit_24h_new" type="number" min="1" max="1000" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.post_max_images_new" type="number" min="1" max="12" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2.5 font-medium text-foreground">{{ t('admin.postFeed.publishing.tiers.standard') }}</td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_24h_standard" type="number" min="1" max="500" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_60m_standard" type="number" min="1" max="100" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_min_interval_standard" type="number" min="0" max="3600" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.comment_limit_24h_standard" type="number" min="1" max="2000" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.post_max_images_standard" type="number" min="1" max="12" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2.5 font-medium text-foreground">{{ t('admin.postFeed.publishing.tiers.premium') }}</td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_24h_premium" type="number" min="1" max="1000" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_limit_60m_premium" type="number" min="1" max="200" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.posting_min_interval_premium" type="number" min="0" max="3600" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.comment_limit_24h_premium" type="number" min="1" max="5000" :disabled="!canManage" class="h-8 w-24 text-xs" /></td>
                            <td class="px-3 py-2"><Input v-model.number="form.post_max_images_premium" type="number" min="1" max="12" :disabled="!canManage" class="h-8 w-20 text-xs" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </form>

        <!-- Section 2: Anti-Spam & Duplicate Detection -->
        <form
            v-show="activeSection === 'antispam'"
            @submit.prevent="submitSettings"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.antispam.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.antispam.subtitle') }}</p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs"
                    @click="promptReset('antispam')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetSection') }}</span>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.antispam.enableAntiSpam') }}</span>
                    <input v-model="form.antispam_enabled" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.antispam.enableDuplicate') }}</span>
                    <input v-model="form.duplicate_detection_enabled" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.duplicateWindow') }}</Label>
                    <Input v-model.number="form.duplicate_detection_window_hours" type="number" min="1" max="720" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.similarityThreshold') }}</Label>
                    <Input v-model.number="form.duplicate_similarity_threshold" type="number" min="50" max="100" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.mediumThreshold') }}</Label>
                    <Input v-model.number="form.antispam_medium_threshold" type="number" min="10" max="90" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.highThreshold') }}</Label>
                    <Input v-model.number="form.antispam_high_threshold" type="number" min="20" max="95" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.violationThreshold') }}</Label>
                    <Input v-model.number="form.antispam_violation_threshold" type="number" min="30" max="100" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.maxLinks') }}</Label>
                    <Input v-model.number="form.antispam_max_links_per_post" type="number" min="0" max="50" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.maxMentions') }}</Label>
                    <Input v-model.number="form.antispam_max_mentions_per_post" type="number" min="0" max="50" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.antispam.maxHashtags') }}</Label>
                    <Input v-model.number="form.antispam_max_hashtags_per_post" type="number" min="0" max="50" :disabled="!canManage" class="h-9 text-xs" />
                </div>
            </div>
        </form>

        <!-- Section 3: Moderation Policy -->
        <form
            v-show="activeSection === 'moderation'"
            @submit.prevent="submitSettings"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.moderation.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.moderation.subtitle') }}</p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs"
                    @click="promptReset('moderation')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetSection') }}</span>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.moderation.riskBasedEnabled') }}</span>
                    <input v-model="form.moderation_risk_based_enabled" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.moderation.requireManualAll') }}</span>
                    <input v-model="form.moderation_require_manual_review" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.moderation.aiAssistEnabled') }}</span>
                    <input v-model="form.moderation_ai_assist_enabled" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.moderation.reviewThreshold') }}</Label>
                    <Input v-model.number="form.moderation_review_threshold" type="number" min="20" max="95" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.moderation.suspiciousLinkAction') }}</Label>
                    <select v-model="form.moderation_suspicious_link_action" :disabled="!canManage" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs">
                        <option value="allow">{{ t('admin.postFeed.moderation.actions.allow') }}</option>
                        <option value="flag">{{ t('admin.postFeed.moderation.actions.flag') }}</option>
                        <option value="reject">{{ t('admin.postFeed.moderation.actions.reject') }}</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.moderation.violationAction') }}</Label>
                    <select v-model="form.moderation_violation_action" :disabled="!canManage" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs">
                        <option value="pending_review">{{ t('admin.postFeed.moderation.actions.pendingReview') }}</option>
                        <option value="reject">{{ t('admin.postFeed.moderation.actions.reject') }}</option>
                    </select>
                </div>
            </div>
        </form>

        <!-- Section 4: Feed & Distribution -->
        <form
            v-show="activeSection === 'feed'"
            @submit.prevent="submitSettings"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.feed.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.feed.subtitle') }}</p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs"
                    @click="promptReset('feed')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetSection') }}</span>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.feed.enableTrending') }}</span>
                    <input v-model="form.feed_enable_trending" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.feed.enableRecommended') }}</span>
                    <input v-model="form.feed_enable_recommended" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.feed.enableFreshDiscovery') }}</span>
                    <input v-model="form.feed_enable_fresh_discovery" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <label class="flex items-center justify-between rounded-lg border border-border p-3 text-xs">
                    <span class="font-medium text-foreground">{{ t('admin.postFeed.feed.categoryDiversity') }}</span>
                    <input v-model="form.feed_category_diversity_enabled" type="checkbox" :disabled="!canManage" class="size-4 accent-primary" />
                </label>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.candidateWindowHours') }}</Label>
                    <Input v-model.number="form.feed_candidate_window_hours" type="number" min="12" max="720" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.refreshIntervalMinutes') }}</Label>
                    <Input v-model.number="form.feed_refresh_interval_minutes" type="number" min="1" max="60" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.pageSize') }}</Label>
                    <Input v-model.number="form.feed_page_size" type="number" min="5" max="30" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.authorDiversityMax') }}</Label>
                    <Input v-model.number="form.feed_author_diversity_max" type="number" min="1" max="10" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.adSpacing') }}</Label>
                    <Input v-model.number="form.feed_ad_spacing_posts" type="number" min="3" max="20" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.allocationRankingPct') }}</Label>
                    <Input v-model.number="form.feed_allocation_ranking_pct" type="number" min="10" max="100" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.allocationFreshPct') }}</Label>
                    <Input v-model.number="form.feed_allocation_fresh_pct" type="number" min="0" max="80" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.feed.allocationDiscoveryPct') }}</Label>
                    <Input v-model.number="form.feed_allocation_discovery_pct" type="number" min="0" max="50" :disabled="!canManage" class="h-9 text-xs" />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ t('admin.postFeed.feed.allocationSummary', { sum: allocationSum }) }}
            </p>
        </form>

        <!-- Section 5: Trending Formula Weights & Anti-Manipulation -->
        <form
            v-show="activeSection === 'trending'"
            @submit.prevent="submitSettings"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.trending.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.trending.subtitle') }}</p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1 text-xs"
                    @click="promptReset('trending')"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('admin.postFeed.resetSection') }}</span>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.weightHumanView') }}</Label>
                    <Input v-model.number="form.trending_weight_human_view" type="number" step="0.05" min="0" max="5" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.weightReaction') }}</Label>
                    <Input v-model.number="form.trending_weight_reaction" type="number" step="0.5" min="0" max="20" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.weightComment') }}</Label>
                    <Input v-model.number="form.trending_weight_comment" type="number" step="0.5" min="0" max="30" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.weightBookmark') }}</Label>
                    <Input v-model.number="form.trending_weight_bookmark" type="number" step="0.5" min="0" max="30" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.weightShare') }}</Label>
                    <Input v-model.number="form.trending_weight_share" type="number" step="0.5" min="0" max="30" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.decayBaseHours') }}</Label>
                    <Input v-model.number="form.trending_decay_base_hours" type="number" step="1" min="1" max="168" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.decayExponent') }}</Label>
                    <Input v-model.number="form.trending_decay_exponent" type="number" step="0.1" min="0.1" max="4" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.perUserCap') }}</Label>
                    <Input v-model.number="form.trending_per_user_contribution_cap" type="number" min="1" max="100" :disabled="!canManage" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.trending.viewEngagementRatio') }}</Label>
                    <Input v-model.number="form.trending_view_engagement_ratio" type="number" min="0" max="5000" :disabled="!canManage" class="h-9 text-xs" />
                </div>
            </div>
        </form>

        <!-- Section 6: Pure Ranking Simulator (Zero DB writes) -->
        <div
            v-show="activeSection === 'simulator'"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="border-b border-border pb-3">
                <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.simulator.title') }}</h2>
                <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.simulator.subtitle') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.humanViews') }}</Label>
                    <Input v-model.number="simulatorInput.human_views" type="number" min="0" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.reactions') }}</Label>
                    <Input v-model.number="simulatorInput.reactions" type="number" min="0" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.comments') }}</Label>
                    <Input v-model.number="simulatorInput.comments" type="number" min="0" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.bookmarks') }}</Label>
                    <Input v-model.number="simulatorInput.bookmarks" type="number" min="0" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.shares') }}</Label>
                    <Input v-model.number="simulatorInput.shares" type="number" min="0" class="h-9 text-xs" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">{{ t('admin.postFeed.simulator.ageHours') }}</Label>
                    <Input v-model.number="simulatorInput.age_hours" type="number" step="0.5" min="0" class="h-9 text-xs" />
                </div>
            </div>

            <Button
                type="button"
                size="sm"
                class="h-9 gap-1.5 rounded-lg text-xs font-semibold"
                :disabled="simLoading || !canSimulate"
                @click="runSimulation"
            >
                <Play class="size-3.5" />
                <span>{{ t('admin.postFeed.simulator.runButton') }}</span>
            </Button>

            <div
                v-if="simResult"
                class="grid gap-3 rounded-xl border border-primary/25 bg-primary-soft/30 p-4 sm:grid-cols-4"
            >
                <div>
                    <p class="text-[11px] text-muted-foreground">{{ t('admin.postFeed.simulator.trendingScore') }}</p>
                    <p class="text-xl font-bold tabular-nums text-primary">{{ simResult.trending_score }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-muted-foreground">{{ t('admin.postFeed.simulator.rawScore') }}</p>
                    <p class="text-lg font-semibold tabular-nums text-foreground">{{ simResult.raw_score }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-muted-foreground">{{ t('admin.postFeed.simulator.decayFactor') }}</p>
                    <p class="text-lg font-semibold tabular-nums text-foreground">÷ {{ simResult.decay_factor }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-muted-foreground">{{ t('admin.postFeed.simulator.effectiveViews') }}</p>
                    <p class="text-lg font-semibold tabular-nums text-foreground">
                        {{ simResult.effective_human_views }}
                        <Badge v-if="simResult.dampening_applied" variant="destructive" class="ms-1 text-[10px]">
                            {{ t('admin.postFeed.simulator.dampened') }}
                        </Badge>
                    </p>
                </div>
            </div>
        </div>

        <!-- Section 7: Anti-Spam Telemetry & Audit Log -->
        <div
            v-show="activeSection === 'telemetry'"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ t('admin.postFeed.telemetry.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('admin.postFeed.telemetry.subtitle') }}</p>
                </div>
            </div>

            <form @submit.prevent="applyTelemetryFilter" class="flex flex-wrap items-center gap-2">
                <Input
                    v-model="filterState.search"
                    type="search"
                    :placeholder="t('admin.common.search')"
                    class="h-9 min-w-[200px] flex-1 text-xs"
                />
                <select v-model="filterState.risk_level" class="h-9 rounded-lg border border-input bg-background px-3 text-xs" @change="applyTelemetryFilter">
                    <option value="all">{{ t('admin.common.all') }}</option>
                    <option value="medium">{{ t('admin.postFeed.telemetry.riskMedium') }}</option>
                    <option value="high">{{ t('admin.postFeed.telemetry.riskHigh') }}</option>
                    <option value="violation">{{ t('admin.postFeed.telemetry.riskViolation') }}</option>
                </select>
                <Button type="submit" size="sm" class="h-9 rounded-lg text-xs">
                    {{ t('admin.common.filter') }}
                </Button>
            </form>

            <EmptyState
                v-if="spamEvents.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <div v-else class="space-y-2">
                <div
                    v-for="ev in spamEvents.data"
                    :key="ev.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3 text-xs"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="outline" class="text-[10px]">{{ ev.event_type }}</Badge>
                            <Badge :variant="ev.risk_level === 'violation' ? 'destructive' : 'secondary'" class="text-[10px]">
                                {{ ev.risk_level }} ({{ ev.risk_score }})
                            </Badge>
                            <span class="font-medium text-foreground">
                                {{ ev.user ? `@${ev.user.username}` : '—' }}
                            </span>
                            <span class="text-muted-foreground">{{ ev.action_context }}</span>
                        </div>
                    </div>
                    <span class="text-[11px] text-muted-foreground">
                        {{ ev.created_at ? formatDateTime(ev.created_at, locale) : '—' }}
                    </span>
                </div>

                <AdminPagination
                    :current-page="spamEvents.current_page"
                    :last-page="spamEvents.last_page"
                    :total="spamEvents.total"
                    :from="spamEvents.from"
                    :to="spamEvents.to"
                    :base-url="settingsRoute.url()"
                    :query="filterState"
                />
            </div>

            <div v-if="recentAuditLogs.length > 0" class="mt-4 space-y-2 border-t border-border pt-4">
                <h3 class="text-xs font-semibold text-foreground">{{ t('admin.postFeed.telemetry.auditLogs') }}</h3>
                <div
                    v-for="log in recentAuditLogs"
                    :key="log.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 px-3 py-2 text-[11px]"
                >
                    <span>
                        <strong class="text-foreground">{{ log.action }}</strong>
                        · {{ log.actor_username ? `@${log.actor_username}` : 'system' }}
                    </span>
                    <span class="text-muted-foreground">{{ log.created_at ? formatDateTime(log.created_at, locale) : '' }}</span>
                </div>
            </div>
        </div>

        <ConfirmActionDialog
            v-model:open="resetDialogOpen"
            :title="t('admin.postFeed.resetConfirmTitle')"
            :description="t('admin.postFeed.resetConfirmDesc')"
            :confirm-label="t('admin.postFeed.resetConfirmButton')"
            :processing="resetting"
            destructive
            @confirm="executeReset"
        />
    </div>
</template>
