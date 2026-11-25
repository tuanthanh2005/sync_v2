<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Megaphone, XCircle } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AdReviewController from '@/actions/App/Http/Controllers/Admin/AdReviewController';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatVnd } from '@/lib/currency';
import { overview as adminOverview } from '@/routes/admin';
import { index as adminAdsIndex } from '@/routes/admin/ads';
import type { AdCampaignItem } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Quản trị hệ thống', href: adminOverview() },
            { title: 'Duyệt quảng cáo', href: adminAdsIndex() },
        ],
    },
});

defineProps<{
    campaigns: AdCampaignItem[];
}>();

const { t, locale } = useI18n();
const lang = computed<'vi' | 'en'>(() => (locale.value === 'en' ? 'en' : 'vi'));
const reasons = reactive<Record<number, string>>({});

function reviewCampaign(
    campaignId: number,
    decision: 'approve' | 'reject',
): void {
    router.post(
        AdReviewController.review.url(campaignId),
        {
            decision,
            reason: reasons[campaignId] ?? '',
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('adminAds.title')" />

        <section class="rounded-xl border border-border bg-card p-6 shadow-xs">
            <div class="flex items-center gap-2.5">
                <Megaphone class="size-5 text-primary" aria-hidden="true" />
                <h1 class="text-xl font-bold text-foreground">
                    {{ t('adminAds.title') }}
                </h1>
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('adminAds.subtitle') }}
            </p>
        </section>

        <section v-if="campaigns.length > 0" class="space-y-3">
            <article
                v-for="campaign in campaigns"
                :key="campaign.id"
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold text-foreground">
                                {{ campaign.name }}
                            </h2>
                            <Badge
                                :variant="
                                    campaign.status === 'pending_review'
                                        ? 'destructive'
                                        : campaign.status === 'active'
                                          ? 'default'
                                          : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{ campaign.status_label }}
                            </Badge>
                            <Badge variant="outline" class="text-[11px]">
                                {{ campaign.objective_label }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ t('adminAds.advertiser') }}:
                            <span class="font-medium text-foreground">
                                {{ campaign.owner_name }} (@{{
                                    campaign.owner_username
                                }})
                            </span>
                            · {{ formatVnd(campaign.total_budget, lang) }} ({{
                                campaign.duration_days
                            }}d)
                        </p>
                    </div>
                </div>

                <div
                    v-if="campaign.post"
                    class="rounded-lg border border-border bg-muted/30 p-3 text-xs"
                >
                    <p
                        v-if="campaign.post.title"
                        class="font-semibold text-foreground"
                    >
                        {{ campaign.post.title }}
                    </p>
                    <p class="mt-0.5 text-muted-foreground">
                        {{ campaign.post.excerpt }}
                    </p>
                    <p
                        v-if="campaign.target_url"
                        class="mt-1 truncate text-primary"
                    >
                        CTA: {{ campaign.cta_label }} →
                        {{ campaign.target_url }}
                    </p>
                </div>

                <div
                    v-if="campaign.status === 'pending_review'"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Input
                        v-model="reasons[campaign.id]"
                        type="text"
                        :placeholder="t('adminAds.rejectPlaceholder')"
                        class="h-8 max-w-xs text-xs"
                    />
                    <Button
                        type="button"
                        size="sm"
                        class="h-8 rounded-lg bg-success text-xs text-success-foreground hover:bg-success/90"
                        @click="reviewCampaign(campaign.id, 'approve')"
                    >
                        <CheckCircle2 class="size-3.5" />
                        <span>{{ t('adminAds.approve') }}</span>
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        class="h-8 rounded-lg text-xs"
                        @click="reviewCampaign(campaign.id, 'reject')"
                    >
                        <XCircle class="size-3.5" />
                        <span>{{ t('adminAds.reject') }}</span>
                    </Button>
                </div>

                <p
                    v-else-if="campaign.rejection_reason"
                    class="text-xs text-destructive"
                >
                    {{ campaign.rejection_reason }}
                </p>
            </article>
        </section>

        <EmptyState
            v-else
            :icon="Megaphone"
            :title="t('adminAds.title')"
            :description="t('adminAds.emptyQueue')"
        />
    </div>
</template>
