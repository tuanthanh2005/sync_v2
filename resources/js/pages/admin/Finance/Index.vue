<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CreditCard, Download, RotateCcw, Search, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminChart from '@/components/admin/AdminChart.vue';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrencyVnd, formatDateTime } from '@/lib/formatters';
import {
    exportCsv as financeExportCsv,
    index as financeIndex,
    refund as financeRefund,
} from '@/routes/admin/finance';
import { adjust as walletAdjustRoute } from '@/routes/admin/finance/wallet';

type AccountRow = {
    id: number;
    user_id: number;
    name: string;
    username: string;
    email: string;
    balance: number;
    promo_balance: number;
    held_balance: number;
    total_deposited: number;
    total_spent: number;
};

type PaymentRow = {
    id: number;
    user_id: number;
    username: string;
    email: string;
    provider: string;
    provider_reference: string | null;
    amount: number;
    refunded_amount: number;
    refundable_remaining: number;
    status: string;
    status_label: string;
    refund_reason: string | null;
    paid_at: string | null;
    created_at: string | null;
};

const props = defineProps<{
    tab: string;
    search: string;
    summary: {
        total_paid_balance: number;
        total_promo_balance: number;
        total_held_balance: number;
        total_deposited: number;
        total_refunded: number;
        total_spent: number;
    };
    accounts: {
        data: AccountRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    payments: {
        data: PaymentRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    entries: Array<{
        id: number;
        username: string | null;
        actor_username: string | null;
        type: string;
        type_label: string;
        wallet_bucket: string;
        amount: number;
        balance_after: number;
        description: string;
        created_at: string;
    }>;
    dailyRevenue: Array<{
        date: string;
        gross: number;
        refunded: number;
        net: number;
    }>;
}>();

const { t, locale } = useI18n();
const activeTab = ref(props.tab || 'balances');
const searchInput = ref(props.search);

function bucketLabel(bucket: string): string {
    const map: Record<string, string> = {
        paid: 'admin.financeExtra.bucket.paid',
        promo: 'admin.financeExtra.bucket.promo',
        held: 'admin.financeExtra.bucket.held',
    };
    const key = map[bucket.toLowerCase()];

    return key ? t(key) : bucket;
}

const adjustTarget = ref<AccountRow | null>(null);
const adjustForm = useForm({
    amount: 100000,
    bucket: 'promo',
    reason: '',
    current_password: '',
});

const refundTarget = ref<PaymentRow | null>(null);
const refundForm = useForm({
    amount: 0,
    reason: '',
    current_password: '',
});

const revenueDatasets = computed(() => [
    {
        label: t('admin.analytics.revenueSeries'),
        data: props.dailyRevenue.map((d) => d.net),
        color: '#16A34A',
    },
    {
        label: t('admin.finance.refundPayment'),
        data: props.dailyRevenue.map((d) => d.refunded),
        color: '#DC2626',
    },
]);

function applySearch(): void {
    router.get(
        financeIndex.url(),
        { tab: activeTab.value, search: searchInput.value.trim() || undefined },
        { preserveState: true },
    );
}

function openAdjust(acc: AccountRow): void {
    adjustTarget.value = acc;
    adjustForm.reset();
    adjustForm.bucket = 'promo';
    adjustForm.amount = 100000;
}

function submitAdjust(): void {
    if (!adjustTarget.value) {
        return;
    }
    adjustForm.post(walletAdjustRoute.url(adjustTarget.value.user_id), {
        preserveScroll: true,
        onSuccess: () => {
            adjustTarget.value = null;
        },
    });
}

function openRefund(p: PaymentRow): void {
    refundTarget.value = p;
    refundForm.reset();
    refundForm.amount = p.refundable_remaining;
}

function submitRefund(): void {
    if (!refundTarget.value) {
        return;
    }
    refundForm.post(financeRefund.url(refundTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            refundTarget.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.finance.title')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <CreditCard class="size-5 text-primary" />
                    <span>{{ t('admin.finance.title') }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.finance.subtitle') }}
                </p>
            </div>
            <Button
                size="sm"
                variant="outline"
                class="gap-1.5 rounded-lg text-xs"
                as-child
            >
                <a :href="financeExportCsv.url()">
                    <Download class="size-3.5" />
                    <span>{{ t('admin.common.exportCsv') }}</span>
                </a>
            </Button>
        </div>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AdminStatCard
                :label="t('admin.finance.paidBalance')"
                :value="formatCurrencyVnd(summary.total_paid_balance, locale)"
                :icon="Wallet"
                tone="primary"
            />
            <AdminStatCard
                :label="t('admin.finance.promoBalance')"
                :value="formatCurrencyVnd(summary.total_promo_balance, locale)"
                :icon="CreditCard"
                tone="success"
            />
            <AdminStatCard
                :label="t('admin.finance.heldBalance')"
                :value="formatCurrencyVnd(summary.total_held_balance, locale)"
                :icon="Wallet"
                tone="warning"
            />
            <AdminStatCard
                :label="t('admin.dashboard.revenue')"
                :value="formatCurrencyVnd(summary.total_deposited, locale)"
                :hint="
                    t('admin.financeExtra.refundedHint', {
                        amount: formatCurrencyVnd(
                            summary.total_refunded,
                            locale,
                        ),
                    })
                "
                :icon="CreditCard"
                tone="success"
            />
        </section>

        <div
            class="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
        >
            <div
                class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto sm:flex-wrap sm:overflow-visible"
            >
                <Button
                    v-for="tk in [
                        'balances',
                        'transactions',
                        'payments',
                        'revenue',
                    ]"
                    :key="tk"
                    size="sm"
                    :variant="activeTab === tk ? 'default' : 'ghost'"
                    class="min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap sm:min-h-8"
                    @click="activeTab = tk"
                >
                    {{ t(`admin.finance.tabs.${tk}`) }}
                </Button>
            </div>
            <form
                @submit.prevent="applySearch"
                class="flex w-full items-center gap-2 sm:w-auto"
            >
                <div class="relative min-w-0 flex-1 sm:flex-initial">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        inputmode="search"
                        :placeholder="t('admin.common.search')"
                        class="min-h-10 w-full rounded-lg border border-input bg-background pr-3 pl-8 text-xs sm:min-h-8 sm:w-52"
                    />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="min-h-10 shrink-0 rounded-lg text-xs sm:min-h-8"
                    >{{ t('admin.common.filter') }}</Button
                >
            </form>
        </div>

        <!-- Tab: Balances -->
        <section
            v-if="activeTab === 'balances'"
            class="rounded-xl border border-border bg-card p-3.5 sm:p-4"
        >
            <EmptyState
                v-if="accounts.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <template v-else>
                <div class="space-y-3 md:hidden">
                    <div
                        v-for="acc in accounts.data"
                        :key="`card-acc-${acc.id}`"
                        class="space-y-2.5 rounded-xl border border-border bg-background/50 p-3.5 text-xs"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p
                                    class="truncate font-semibold text-foreground"
                                >
                                    {{ acc.name }}
                                </p>
                                <p
                                    class="truncate text-[11px] text-muted-foreground"
                                >
                                    @{{ acc.username }} · {{ acc.email }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-3 gap-2 rounded-lg bg-muted/40 p-2 text-[11px]"
                        >
                            <div>
                                <p class="text-muted-foreground">
                                    {{ t('admin.finance.paidBalance') }}
                                </p>
                                <p
                                    class="font-mono font-semibold text-foreground"
                                >
                                    {{ formatCurrencyVnd(acc.balance, locale) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">
                                    {{ t('admin.finance.promoBalance') }}
                                </p>
                                <p class="font-mono font-semibold text-success">
                                    {{
                                        formatCurrencyVnd(
                                            acc.promo_balance,
                                            locale,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">
                                    {{ t('admin.finance.heldBalance') }}
                                </p>
                                <p class="font-mono text-muted-foreground">
                                    {{
                                        formatCurrencyVnd(
                                            acc.held_balance,
                                            locale,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            class="min-h-9 w-full rounded-lg text-xs"
                            @click="openAdjust(acc)"
                        >
                            {{ t('admin.finance.adjustLedger') }}
                        </Button>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-3">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.finance.paidBalance') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.finance.promoBalance') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.finance.heldBalance') }}
                                </th>
                                <th class="py-2 text-right">
                                    {{ t('admin.common.actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="acc in accounts.data" :key="acc.id">
                                <td class="py-2.5 pr-3">
                                    <p class="font-semibold text-foreground">
                                        {{ acc.name }}
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        @{{ acc.username }} · {{ acc.email }}
                                    </p>
                                </td>
                                <td class="py-2.5 pr-3 font-mono font-semibold">
                                    {{ formatCurrencyVnd(acc.balance, locale) }}
                                </td>
                                <td class="py-2.5 pr-3 font-mono text-success">
                                    {{
                                        formatCurrencyVnd(
                                            acc.promo_balance,
                                            locale,
                                        )
                                    }}
                                </td>
                                <td
                                    class="py-2.5 pr-3 font-mono text-muted-foreground"
                                >
                                    {{
                                        formatCurrencyVnd(
                                            acc.held_balance,
                                            locale,
                                        )
                                    }}
                                </td>
                                <td class="py-2.5 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 rounded-lg text-[11px]"
                                        @click="openAdjust(acc)"
                                    >
                                        {{ t('admin.finance.adjustLedger') }}
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- Tab: Ledger Transactions -->
        <section
            v-if="activeTab === 'transactions'"
            class="rounded-xl border border-border bg-card p-3.5 sm:p-4"
        >
            <EmptyState
                v-if="entries.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <template v-else>
                <div class="space-y-2.5 md:hidden">
                    <div
                        v-for="e in entries"
                        :key="`card-e-${e.id}`"
                        class="space-y-1.5 rounded-xl border border-border bg-background/50 p-3 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-muted-foreground"
                                >#{{ e.id }} · @{{ e.username || '-' }}</span
                            >
                            <span
                                class="font-mono font-semibold text-foreground"
                                >{{ formatCurrencyVnd(e.amount, locale) }}</span
                            >
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            <Badge variant="secondary" class="text-[10px]">{{
                                e.type_label
                            }}</Badge>
                            <Badge variant="outline" class="text-[10px]">{{
                                bucketLabel(e.wallet_bucket)
                            }}</Badge>
                        </div>
                        <p class="break-words text-muted-foreground">
                            {{ e.description }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatDateTime(e.created_at, locale) }}
                        </p>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colId') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colTypeBucket') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colAmount') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colDescription') }}
                                </th>
                                <th class="py-2">
                                    {{ t('admin.common.createdAt') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="e in entries" :key="e.id">
                                <td
                                    class="py-2.5 pr-3 font-mono text-muted-foreground"
                                >
                                    #{{ e.id }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    @{{ e.username || '-' }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    <Badge
                                        variant="secondary"
                                        class="text-[10px]"
                                        >{{ e.type_label }}</Badge
                                    >
                                    <Badge
                                        variant="outline"
                                        class="ms-1 text-[10px]"
                                        >{{
                                            bucketLabel(e.wallet_bucket)
                                        }}</Badge
                                    >
                                </td>
                                <td class="py-2.5 pr-3 font-mono font-semibold">
                                    {{ formatCurrencyVnd(e.amount, locale) }}
                                </td>
                                <td class="py-2.5 pr-3 text-muted-foreground">
                                    {{ e.description }}
                                </td>
                                <td class="py-2.5 text-muted-foreground">
                                    {{ formatDateTime(e.created_at, locale) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- Tab: Payments & Refunds -->
        <section
            v-if="activeTab === 'payments'"
            class="rounded-xl border border-border bg-card p-3.5 sm:p-4"
        >
            <EmptyState
                v-if="payments.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <template v-else>
                <div class="space-y-3 md:hidden">
                    <div
                        v-for="p in payments.data"
                        :key="`card-p-${p.id}`"
                        class="space-y-2 rounded-xl border border-border bg-background/50 p-3.5 text-xs"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-foreground">
                                    #{{ p.id }} · @{{ p.username }}
                                </p>
                                <p
                                    class="truncate font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ p.provider.toUpperCase() }} ·
                                    {{ p.provider_reference || '-' }}
                                </p>
                            </div>
                            <Badge
                                variant="secondary"
                                class="shrink-0 text-[10px]"
                                >{{ p.status_label }}</Badge
                            >
                        </div>
                        <div
                            class="flex items-center justify-between gap-2 border-t border-border pt-2"
                        >
                            <div>
                                <p
                                    class="font-mono font-semibold text-foreground"
                                >
                                    {{ formatCurrencyVnd(p.amount, locale) }}
                                </p>
                                <p
                                    v-if="p.refunded_amount > 0"
                                    class="text-[11px] text-destructive"
                                >
                                    {{
                                        t('admin.financeExtra.refundedHint', {
                                            amount: formatCurrencyVnd(
                                                p.refunded_amount,
                                                locale,
                                            ),
                                        })
                                    }}
                                </p>
                            </div>
                            <Button
                                v-if="p.refundable_remaining > 0"
                                size="sm"
                                variant="destructive"
                                class="min-h-9 gap-1 rounded-lg text-xs"
                                @click="openRefund(p)"
                            >
                                <RotateCcw class="size-3.5" />
                                <span>{{
                                    t('admin.finance.refundPayment')
                                }}</span>
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-border text-muted-foreground"
                        >
                            <tr>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colId') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.users.member') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colProvider') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.financeExtra.colAmount') }}
                                </th>
                                <th class="py-2 pr-3">
                                    {{ t('admin.common.status') }}
                                </th>
                                <th class="py-2 text-right">
                                    {{ t('admin.common.actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="p in payments.data" :key="p.id">
                                <td class="py-2.5 pr-3 font-mono">
                                    #{{ p.id }}
                                </td>
                                <td class="py-2.5 pr-3">@{{ p.username }}</td>
                                <td class="py-2.5 pr-3 uppercase">
                                    {{ p.provider }}
                                </td>
                                <td class="py-2.5 pr-3 font-mono">
                                    {{ formatCurrencyVnd(p.amount, locale) }}
                                    <span
                                        v-if="p.refunded_amount > 0"
                                        class="block text-[10px] text-destructive"
                                    >
                                        -{{
                                            formatCurrencyVnd(
                                                p.refunded_amount,
                                                locale,
                                            )
                                        }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <Badge
                                        variant="secondary"
                                        class="text-[10px]"
                                        >{{ p.status_label }}</Badge
                                    >
                                </td>
                                <td class="py-2.5 text-right">
                                    <Button
                                        v-if="
                                            p.status === 'paid' &&
                                            p.refundable_remaining > 0
                                        "
                                        size="sm"
                                        variant="destructive"
                                        class="h-7 gap-1 rounded-lg text-[11px]"
                                        @click="openRefund(p)"
                                    >
                                        <RotateCcw class="size-3" />
                                        <span>{{
                                            t('admin.finance.refundPayment')
                                        }}</span>
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- Tab: Revenue Chart -->
        <section
            v-if="activeTab === 'revenue'"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="mb-4 text-sm font-semibold">
                {{ t('admin.finance.tabs.revenue') }}
            </h2>
            <AdminChart
                type="bar"
                :labels="dailyRevenue.map((d) => d.date)"
                :datasets="revenueDatasets"
                :height="280"
            />
        </section>

        <!-- Password-Confirmed Ledger Adjustment Dialog -->
        <Dialog
            :open="adjustTarget !== null"
            @update:open="(v) => !v && (adjustTarget = null)"
        >
            <DialogContent class="rounded-2xl sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        t('admin.finance.adjustLedger')
                    }}</DialogTitle>
                    <DialogDescription
                        >@{{ adjustTarget?.username }} ·
                        {{
                            t('admin.finance.passwordConfirmHint')
                        }}</DialogDescription
                    >
                </DialogHeader>
                <form @submit.prevent="submitAdjust" class="space-y-3 text-xs">
                    <div class="space-y-1">
                        <Label>{{
                            t('admin.financeExtra.walletBucketLabel')
                        }}</Label>
                        <select
                            v-model="adjustForm.bucket"
                            class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option value="promo">
                                {{ t('admin.finance.promoBalance') }}
                            </option>
                            <option value="paid">
                                {{ t('admin.finance.paidBalance') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label>{{
                            t('admin.financeExtra.signedAmountLabel')
                        }}</Label>
                        <Input
                            v-model.number="adjustForm.amount"
                            type="number"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label>{{ t('admin.common.reason') }}</Label>
                        <Input
                            v-model="adjustForm.reason"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label>{{ t('admin.finance.passwordConfirm') }}</Label>
                        <Input
                            v-model="adjustForm.current_password"
                            type="password"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="adjustTarget = null"
                            >{{ t('dialog.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="adjustForm.processing"
                            >{{ t('admin.common.save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Password-Confirmed Refund Dialog -->
        <Dialog
            :open="refundTarget !== null"
            @update:open="(v) => !v && (refundTarget = null)"
        >
            <DialogContent class="rounded-2xl sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        t('admin.financeExtra.refundDialogTitle', {
                            id: refundTarget?.id ?? '',
                        })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('admin.finance.passwordConfirmHint')
                    }}</DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitRefund" class="space-y-3 text-xs">
                    <div class="space-y-1">
                        <Label>{{
                            t('admin.financeExtra.refundAmountLabel', {
                                max: refundTarget?.refundable_remaining ?? 0,
                            })
                        }}</Label>
                        <Input
                            v-model.number="refundForm.amount"
                            type="number"
                            :max="refundTarget?.refundable_remaining"
                            min="1"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label>{{ t('admin.common.reason') }}</Label>
                        <Input
                            v-model="refundForm.reason"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label>{{ t('admin.finance.passwordConfirm') }}</Label>
                        <Input
                            v-model="refundForm.current_password"
                            type="password"
                            required
                            class="h-9 text-xs"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="refundTarget = null"
                            >{{ t('dialog.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="refundForm.processing"
                            >{{ t('admin.finance.refundPayment') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
