<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Crown,
    DollarSign,
    Download,
    Image as ImageIcon,
    ReceiptText,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, formatUsdCents, formatVnd } from '@/lib/currency';
import { revenue as adminRevenueRoute } from '@/routes/admin/monetization';
import { exportCsv as revenueExportCsvRoute } from '@/routes/admin/monetization/revenue';

type CurrencyBucket = {
    recognized_service_gross: number;
    recognized_service_refunded: number;
    prepaid_deposits_gross: number;
    prepaid_deposits_refunded: number;
    total_fees: number;
    total_refunded: number;
    net_cash_collected: number;
    service_orders: number;
    prepaid_orders: number;
    by_service: {
        premium: number;
        banner: number;
        boost: number;
    };
};

const props = defineProps<{
    filters: {
        range: string;
        from: string;
        to: string;
    };
    currencies: {
        VND: CurrencyBucket;
        USD: CurrencyBucket;
    };
    dailySeries: Array<{
        date: string;
        vnd_service: number;
        vnd_topup: number;
        usd_cents_service: number;
        usd_cents_topup: number;
    }>;
    operations: {
        active_premium_members: number;
        expiring_7d_premium: number;
        total_banner_placements: number;
        occupied_banner_placements: number;
        vacant_banner_placements: number;
        pending_banner_reviews: number;
        active_boost_campaigns: number;
        pending_orders_count: number;
        ad_balance_liability_vnd: number;
        ad_balance_promo_vnd: number;
        ad_balance_recognized_spend_vnd: number;
    };
}>();

const { t, locale } = useI18n();
const range = ref(props.filters.range);
const fromDate = ref(props.filters.from);
const toDate = ref(props.filters.to);

function switchRange(nextRange: string): void {
    range.value = nextRange;
    router.get(
        adminRevenueRoute.url({
            query:
                nextRange === 'custom'
                    ? {
                          range: 'custom',
                          from: fromDate.value,
                          to: toDate.value,
                      }
                    : { range: nextRange },
        }),
        {},
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.nav.revenueDashboard')" />

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <DollarSign class="size-5 text-primary" />
                    <span>{{ t('admin.nav.revenueDashboard') }}</span>
                </h1>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ t('monetization.admin.revenueSubtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-for="r in ['day', 'week', 'month', 'year']"
                    :key="r"
                    type="button"
                    size="sm"
                    :variant="range === r ? 'default' : 'outline'"
                    class="rounded-lg text-xs capitalize"
                    @click="switchRange(r)"
                >
                    {{ r }}
                </Button>
                <a
                    :href="
                        revenueExportCsvRoute.url({
                            query: {
                                range,
                                from: fromDate,
                                to: toDate,
                            },
                        })
                    "
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-medium text-foreground hover:bg-surface-hover"
                >
                    <Download class="size-3.5" />
                    <span>CSV</span>
                </a>
            </div>
        </div>

        <!-- Custom Date Filter -->
        <form
            @submit.prevent="switchRange('custom')"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3 text-xs"
        >
            <Input v-model="fromDate" type="date" class="w-40 text-xs" />
            <span class="text-muted-foreground">→</span>
            <Input v-model="toDate" type="date" class="w-40 text-xs" />
            <Button type="submit" size="sm" variant="secondary">
                {{ t('common.apply') }}
            </Button>
        </form>

        <!-- Dual Currency Revenue Separation (VND vs USD) -->
        <section class="grid gap-4 lg:grid-cols-2">
            <div class="space-y-3 rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-foreground">
                        VND Accounting (Vietnam)
                    </h2>
                    <Badge variant="soft">VND</Badge>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-muted-foreground">
                            {{ t('monetization.admin.recognizedRevenue') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-primary">
                            {{
                                formatVnd(
                                    currencies.VND.recognized_service_gross,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-muted-foreground">
                            {{ t('monetization.admin.prepaidDeposits') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                formatVnd(
                                    currencies.VND.prepaid_deposits_gross,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                </div>
                <dl
                    class="grid grid-cols-3 gap-2 border-t border-border pt-3 text-xs"
                >
                    <div>
                        <dt class="text-muted-foreground">Premium</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatVnd(
                                    currencies.VND.by_service.premium,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Banner</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatVnd(
                                    currencies.VND.by_service.banner,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Boost</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatVnd(
                                    currencies.VND.by_service.boost,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="text-[11px] text-muted-foreground">
                    Refunded:
                    {{ formatVnd(currencies.VND.total_refunded, locale) }} ·
                    Fees: {{ formatVnd(currencies.VND.total_fees, locale) }}
                </p>
            </div>

            <div class="space-y-3 rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-foreground">
                        USD Accounting (International)
                    </h2>
                    <Badge variant="soft">USD</Badge>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-muted-foreground">
                            {{ t('monetization.admin.recognizedRevenue') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-primary">
                            {{
                                formatUsdCents(
                                    currencies.USD.recognized_service_gross,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-muted-foreground">
                            {{ t('monetization.admin.prepaidDeposits') }}
                        </p>
                        <p class="mt-1 text-lg font-bold text-foreground">
                            {{
                                formatUsdCents(
                                    currencies.USD.prepaid_deposits_gross,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                </div>
                <dl
                    class="grid grid-cols-3 gap-2 border-t border-border pt-3 text-xs"
                >
                    <div>
                        <dt class="text-muted-foreground">Premium</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatUsdCents(
                                    currencies.USD.by_service.premium,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Banner</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatUsdCents(
                                    currencies.USD.by_service.banner,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Boost</dt>
                        <dd class="font-semibold text-foreground">
                            {{
                                formatUsdCents(
                                    currencies.USD.by_service.boost,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="text-[11px] text-muted-foreground">
                    Refunded:
                    {{ formatUsdCents(currencies.USD.total_refunded, locale) }}
                    · Fees:
                    {{ formatUsdCents(currencies.USD.total_fees, locale) }}
                </p>
            </div>
        </section>

        <!-- Operational KPIs -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Crown class="size-3.5 text-primary" />
                    <span>Active Premium</span>
                </p>
                <p class="mt-1.5 text-xl font-bold text-foreground">
                    {{ operations.active_premium_members }}
                </p>
                <p class="text-[11px] text-muted-foreground">
                    Expiring ≤7d: {{ operations.expiring_7d_premium }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <ImageIcon class="size-3.5 text-primary" />
                    <span>Banner Slots</span>
                </p>
                <p class="mt-1.5 text-xl font-bold text-foreground">
                    {{ operations.occupied_banner_placements }} /
                    {{ operations.total_banner_placements }}
                </p>
                <p class="text-[11px] text-muted-foreground">
                    Pending review: {{ operations.pending_banner_reviews }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Zap class="size-3.5 text-primary" />
                    <span>Active Boosts</span>
                </p>
                <p class="mt-1.5 text-xl font-bold text-foreground">
                    {{ operations.active_boost_campaigns }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <ReceiptText class="size-3.5 text-warning" />
                    <span>Pending Orders</span>
                </p>
                <p class="mt-1.5 text-xl font-bold text-foreground">
                    {{ operations.pending_orders_count }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <WalletIcon class="size-3.5 text-primary" />
                    <span>Ad Balance Liability</span>
                </p>
                <p class="mt-1.5 text-base font-bold text-foreground">
                    {{ formatVnd(operations.ad_balance_liability_vnd, locale) }}
                </p>
                <p class="text-[11px] text-muted-foreground">
                    Spent:
                    {{
                        formatVnd(
                            operations.ad_balance_recognized_spend_vnd,
                            locale,
                        )
                    }}
                </p>
            </div>
        </section>

        <!-- Daily Ledger Breakdown Table -->
        <section class="rounded-2xl border border-border bg-card p-5">
            <h2 class="mb-3 text-sm font-bold text-foreground">
                Daily Settled Cashflow
            </h2>
            <div v-if="dailySeries.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-4">Date</th>
                            <th class="py-2 pr-4">Service (VND)</th>
                            <th class="py-2 pr-4">Ad Top-Up (VND)</th>
                            <th class="py-2 pr-4">Service (USD)</th>
                            <th class="py-2">Ad Top-Up (USD)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in dailySeries" :key="row.date">
                            <td class="py-2 pr-4 font-mono">{{ row.date }}</td>
                            <td class="py-2 pr-4 font-semibold text-primary">
                                {{
                                    formatMoney(row.vnd_service, 'VND', locale)
                                }}
                            </td>
                            <td class="py-2 pr-4">
                                {{ formatMoney(row.vnd_topup, 'VND', locale) }}
                            </td>
                            <td class="py-2 pr-4 font-semibold text-primary">
                                {{
                                    formatMoney(
                                        row.usd_cents_service,
                                        'USD',
                                        locale,
                                    )
                                }}
                            </td>
                            <td class="py-2">
                                {{
                                    formatMoney(
                                        row.usd_cents_topup,
                                        'USD',
                                        locale,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                No settled transactions recorded in this date range.
            </p>
        </section>
    </div>
</template>
