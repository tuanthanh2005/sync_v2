<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Image as ImageIcon, Monitor, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BannerBookingForm from '@/components/monetization/BannerBookingForm.vue';
import { Badge } from '@/components/ui/badge';
import { defaultCurrencyForLocale, formatMoney } from '@/lib/currency';
import { formatDate, formatNumber } from '@/lib/formatters';
import type {
    BannerCampaignItem,
    BannerPlacementItem,
    PaymentMethodPublic,
    PlatformCapabilities,
} from '@/types';

const props = defineProps<{
    placements: BannerPlacementItem[];
    myCampaigns: BannerCampaignItem[];
    paymentMethods: PaymentMethodPublic[];
    platformCapabilities: PlatformCapabilities;
    pendingOrdersCount: number;
    maxPendingOrders: number;
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

const currency = ref<'VND' | 'USD'>(defaultCurrencyForLocale(locale.value));
const selectedPlacementId = ref<number>(props.placements[0]?.id ?? 0);

const selectedPlacement = computed(
    () =>
        props.placements.find((p) => p.id === selectedPlacementId.value) ??
        props.placements[0] ??
        null,
);

function price7d(p: BannerPlacementItem): number {
    return currency.value === 'USD' ? p.price_7d_usd_cents : p.price_7d_vnd;
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.banner.pageTitle')" />

        <!-- Header -->
        <section
            class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-border bg-card p-6 shadow-xs"
        >
            <div class="max-w-2xl space-y-2">
                <Badge variant="soft" class="gap-1.5 text-xs">
                    <ImageIcon class="size-3.5" />
                    <span>{{ t('monetization.bookingExtra.badgeTitle') }}</span>
                </Badge>
                <h1 class="text-2xl font-bold text-foreground">
                    {{ t('monetization.banner.heading') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('monetization.banner.subtitle') }}
                </p>
            </div>

            <div
                class="inline-flex rounded-xl border border-border bg-muted/40 p-1"
            >
                <button
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1.5 text-xs font-semibold',
                        currency === 'VND'
                            ? 'bg-card text-primary shadow-2xs'
                            : 'text-muted-foreground',
                    ]"
                    @click="currency = 'VND'"
                >
                    {{ t('monetization.checkout.currencyVnd') }}
                </button>
                <button
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1.5 text-xs font-semibold',
                        currency === 'USD'
                            ? 'bg-card text-primary shadow-2xs'
                            : 'text-muted-foreground',
                    ]"
                    @click="currency = 'USD'"
                >
                    {{ t('monetization.checkout.currencyUsd') }}
                </button>
            </div>
        </section>

        <!-- Placement Catalog -->
        <section class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="pl in placements"
                :key="pl.id"
                type="button"
                :class="[
                    'flex flex-col justify-between rounded-xl border p-4 text-start transition-colors',
                    selectedPlacement?.id === pl.id
                        ? 'border-primary bg-primary-soft/50'
                        : 'border-border bg-card hover:border-primary/40',
                ]"
                @click="selectedPlacementId = pl.id"
            >
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Badge variant="outline" class="gap-1 text-[10px]">
                            <Monitor
                                v-if="pl.platform === 'desktop'"
                                class="size-3"
                            />
                            <Smartphone v-else class="size-3" />
                            <span>{{ pl.aspect_ratio }}</span>
                        </Badge>
                        <Badge
                            :variant="
                                pl.delivery_mode === 'exclusive'
                                    ? 'soft'
                                    : 'secondary'
                            "
                            class="text-[10px]"
                        >
                            {{ pl.delivery_mode_label }}
                        </Badge>
                    </div>
                    <p class="text-sm font-bold text-foreground">
                        {{ pl.name }}
                    </p>
                    <p
                        v-if="pl.description"
                        class="line-clamp-2 text-xs text-muted-foreground"
                    >
                        {{ pl.description }}
                    </p>
                </div>

                <div
                    class="mt-3 flex items-center justify-between border-t border-border/70 pt-2.5 text-xs"
                >
                    <span class="font-bold text-primary">
                        {{
                            t('monetization.bookingExtra.pricePer7Days', {
                                price: formatMoney(
                                    price7d(pl),
                                    currency,
                                    locale,
                                ),
                            })
                        }}
                    </span>
                    <span
                        :class="[
                            'text-[11px] font-medium',
                            pl.is_available_now
                                ? 'text-success'
                                : 'text-warning',
                        ]"
                    >
                        {{
                            pl.is_available_now
                                ? t('monetization.banner.available')
                                : t('monetization.banner.bookedNow')
                        }}
                    </span>
                </div>
            </button>
        </section>

        <!-- Booking Form & Live Desktop/Mobile Preview -->
        <BannerBookingForm
            v-if="selectedPlacement"
            :placement="selectedPlacement"
            :currency="currency"
            :payment-methods="paymentMethods"
            :pending-orders-count="pendingOrdersCount"
            :max-pending-orders="maxPendingOrders"
            :is-authenticated="Boolean(user)"
        />

        <!-- My Banner Campaigns -->
        <section
            v-if="user && myCampaigns.length > 0"
            class="space-y-3 rounded-2xl border border-border bg-card p-5"
        >
            <h2 class="text-sm font-bold text-foreground">
                {{ t('monetization.banner.myCampaigns') }}
            </h2>
            <div class="divide-y divide-border">
                <div
                    v-for="c in myCampaigns"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3 text-xs"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-foreground">
                                {{ c.name }}
                            </p>
                            <Badge variant="outline" class="text-[10px]">
                                {{ c.status_label }}
                            </Badge>
                        </div>
                        <p class="mt-0.5 text-muted-foreground">
                            {{ c.placement_name }} ·
                            {{ formatDate(c.starts_at, locale) }} →
                            {{ formatDate(c.ends_at, locale) }}
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="font-semibold text-foreground">
                            {{
                                t('monetization.bookingExtra.campaignStats', {
                                    impressions: formatNumber(
                                        c.impressions_count,
                                        locale,
                                    ),
                                    clicks: formatNumber(
                                        c.clicks_count,
                                        locale,
                                    ),
                                    ctr: c.ctr_percent,
                                })
                            }}
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                formatMoney(c.price_amount, c.currency, locale)
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
