<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Calendar,
    CheckCircle2,
    Crown,
    History,
    ReceiptText,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/currency';
import { formatDate } from '@/lib/formatters';
import { premium as premiumPricingRoute } from '@/routes/monetization';
import { index as paymentsIndex } from '@/routes/payments';
import type { PlatformCapabilities, PremiumPlanItem } from '@/types';

defineProps<{
    isPremium: boolean;
    isSuspended: boolean;
    premiumUntil: string | null;
    daysRemaining: number;
    activeSubscription: {
        id: number;
        plan_name: string | null;
        plan_slug: string | null;
        ad_reduction_mode: string;
        benefits: string[];
        starts_at: string;
        expires_at: string;
        source: string;
    } | null;
    history: Array<{
        id: number;
        plan_name: string;
        status: string;
        status_label: string;
        source: string;
        currency: string;
        amount_paid: number;
        starts_at: string;
        expires_at: string;
        order_code: string | null;
    }>;
    plans: PremiumPlanItem[];
    platformCapabilities: PlatformCapabilities;
}>();

const { t, locale } = useI18n();
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.accountPremium.pageTitle')" />

        <!-- Status Card -->
        <section class="rounded-2xl border border-border bg-card p-6 shadow-xs">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <Crown class="size-5 text-primary" />
                        <h1 class="text-xl font-bold text-foreground">
                            {{ t('monetization.accountPremium.pageTitle') }}
                        </h1>
                        <PremiumBadge v-if="isPremium" size="md" />
                    </div>
                    <p
                        v-if="isPremium && premiumUntil"
                        class="text-sm text-muted-foreground"
                    >
                        {{ activeSubscription?.plan_name }} ·
                        {{
                            t('monetization.accountPremium.expiresOn', {
                                date: formatDate(premiumUntil, locale),
                                days: daysRemaining,
                            })
                        }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('monetization.accountPremium.inactiveNotice') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button class="rounded-xl" as-child>
                        <Link :href="premiumPricingRoute()">
                            {{
                                isPremium
                                    ? t('monetization.premium.extendButton')
                                    : t('monetization.premium.buyButton')
                            }}
                        </Link>
                    </Button>
                    <Button variant="outline" class="rounded-xl" as-child>
                        <Link :href="paymentsIndex()">
                            <ReceiptText class="size-4" />
                            <span>{{ t('monetization.payments.title') }}</span>
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="isPremium && activeSubscription"
                class="mt-5 grid gap-3 border-t border-border pt-4 sm:grid-cols-2"
            >
                <div
                    v-for="benefit in activeSubscription.benefits"
                    :key="benefit"
                    class="flex items-center gap-2 text-xs text-foreground"
                >
                    <CheckCircle2 class="size-4 shrink-0 text-primary" />
                    <span>{{
                        t(`monetization.benefits.${benefit}`, benefit)
                    }}</span>
                </div>
            </div>
        </section>

        <!-- Subscription History -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2
                class="flex items-center gap-2 text-sm font-bold text-foreground"
            >
                <History class="size-4 text-primary" />
                <span>{{ t('monetization.accountPremium.historyTitle') }}</span>
            </h2>

            <div v-if="history.length > 0" class="divide-y divide-border">
                <div
                    v-for="item in history"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3 text-xs"
                >
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">{{
                                item.plan_name
                            }}</span>
                            <Badge variant="outline" class="text-[10px]">
                                {{ item.status_label }}
                            </Badge>
                            <Badge variant="secondary" class="text-[10px]">
                                {{ item.source }}
                            </Badge>
                        </div>
                        <p
                            class="flex items-center gap-1 text-muted-foreground"
                        >
                            <Calendar class="size-3" />
                            <span>
                                {{ formatDate(item.starts_at, locale) }} →
                                {{ formatDate(item.expires_at, locale) }}
                            </span>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-foreground">
                            {{
                                item.amount_paid > 0
                                    ? formatMoney(
                                          item.amount_paid,
                                          item.currency,
                                          locale,
                                      )
                                    : t(
                                          'monetization.accountPremium.complimentary',
                                      )
                            }}
                        </p>
                        <p
                            v-if="item.order_code"
                            class="font-mono text-[11px] text-muted-foreground"
                        >
                            {{ item.order_code }}
                        </p>
                    </div>
                </div>
            </div>

            <p v-else class="text-xs text-muted-foreground">
                {{ t('monetization.accountPremium.emptyHistory') }}
            </p>
        </section>
    </div>
</template>
