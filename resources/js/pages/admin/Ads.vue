<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Megaphone,
    Pause,
    Play,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrencyVnd, formatNumber } from '@/lib/formatters';
import {
    index as adminAdsIndex,
    manage as adminAdsManage,
    review as adminAdsReview,
} from '@/routes/admin/ads';
import type { AdCampaignItem } from '@/types';

defineProps<{
    campaigns: AdCampaignItem[];
    statusFilter?: string;
    anomalies?: Array<{ ad_campaign_id: number; clicks: number }>;
}>();

const { t, locale } = useI18n();
const rejectOpen = ref(false);
const activeCampaign = ref<AdCampaignItem | null>(null);

function filterStatus(status: string): void {
    router.get(
        adminAdsIndex.url({ query: status === 'all' ? {} : { status } }),
        {},
        { preserveScroll: true },
    );
}

function approveCampaign(c: AdCampaignItem): void {
    router.post(
        adminAdsReview.url(c.id),
        { decision: 'approve', reason: '' },
        { preserveScroll: true },
    );
}

function openReject(c: AdCampaignItem): void {
    activeCampaign.value = c;
    rejectOpen.value = true;
}

function submitReject(reason: string): void {
    if (!activeCampaign.value) {
        return;
    }
    router.post(
        adminAdsReview.url(activeCampaign.value.id),
        { decision: 'reject', reason },
        {
            preserveScroll: true,
            onFinish: () => {
                rejectOpen.value = false;
            },
        },
    );
}

function manageStatus(
    c: AdCampaignItem,
    action: 'pause' | 'resume' | 'cancel',
): void {
    router.post(adminAdsManage.url(c.id), { action }, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.ads.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Megaphone class="size-5 text-primary" />
                <span>{{ t('admin.ads.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.ads.subtitle') }}
            </p>
        </div>

        <div
            v-if="anomalies && anomalies.length > 0"
            class="flex items-center gap-2 rounded-xl border border-warning/40 bg-warning-soft/50 px-4 py-3 text-xs text-warning-soft-foreground"
        >
            <AlertTriangle class="size-4 shrink-0" />
            <span
                >{{ t('admin.ads.anomalyFlag') }}:
                {{ anomalies.length }} campaign(s) detected with high single-IP
                click bursts in 24h.</span
            >
        </div>

        <div
            class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto rounded-xl border border-border bg-card p-3 sm:flex-wrap sm:overflow-visible"
        >
            <Button
                v-for="st in [
                    'all',
                    'pending_review',
                    'active',
                    'paused',
                    'rejected',
                    'completed',
                    'cancelled',
                ]"
                :key="st"
                size="sm"
                :variant="(statusFilter || 'all') === st ? 'default' : 'ghost'"
                class="min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap sm:min-h-8"
                @click="filterStatus(st)"
            >
                {{ st }}
            </Button>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="campaigns.length === 0"
                :title="t('adminAds.emptyQueue')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-3">
                <div
                    v-for="c in campaigns"
                    :key="c.id"
                    class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="text-sm font-semibold text-foreground"
                                >{{ c.name }}</span
                            >
                            <Badge variant="secondary" class="text-[10px]">{{
                                c.status_label
                            }}</Badge>
                            <Badge variant="outline" class="text-[10px]">{{
                                c.objective_label
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground">
                            {{ t('adminAds.advertiser') }}: @{{
                                c.owner_username
                            }}
                            · Budget:
                            {{ formatCurrencyVnd(c.total_budget, locale) }} ·
                            Spent:
                            {{ formatCurrencyVnd(c.spent_amount, locale) }} ·
                            Held: {{ formatCurrencyVnd(c.held_amount, locale) }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            Impressions:
                            {{ formatNumber(c.impressions_count, locale) }} ·
                            Clicks: {{ formatNumber(c.clicks_count, locale) }} ·
                            CTR: {{ c.ctr_percent }}%
                        </p>
                        <p v-if="c.post" class="text-muted-foreground">
                            {{ c.post.excerpt }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <template v-if="c.status === 'pending_review'">
                            <Button
                                size="sm"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="approveCampaign(c)"
                            >
                                <CheckCircle2 class="size-3.5" />
                                <span>{{ t('adminAds.approve') }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="openReject(c)"
                            >
                                <XCircle class="size-3.5" />
                                <span>{{ t('adminAds.reject') }}</span>
                            </Button>
                        </template>
                        <template v-else-if="c.status === 'active'">
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="manageStatus(c, 'pause')"
                            >
                                <Pause class="size-3.5" />
                                <span>{{ t('ads.pause') }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="manageStatus(c, 'cancel')"
                            >
                                <span>{{ t('admin.ads.stopAndRefund') }}</span>
                            </Button>
                        </template>
                        <template v-else-if="c.status === 'paused'">
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="manageStatus(c, 'resume')"
                            >
                                <Play class="size-3.5" />
                                <span>{{ t('ads.resume') }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-8 gap-1 rounded-lg text-xs"
                                @click="manageStatus(c, 'cancel')"
                            >
                                <span>{{ t('admin.ads.stopAndRefund') }}</span>
                            </Button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <ReasonDialog
            v-model:open="rejectOpen"
            :title="t('adminAds.reject')"
            :description="activeCampaign?.name || ''"
            consequence="Rejecting this campaign immediately refunds 100% of the reserved ad wallet hold."
            @submit="submitReject"
        />
    </div>
</template>
