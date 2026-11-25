<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Clock,
    CreditCard,
    Lock,
    Megaphone,
    Plus,
    ReceiptText,
    ShieldCheck,
    Wallet as WalletIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import WalletController from '@/actions/App/Http/Controllers/Billing/WalletController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatVnd } from '@/lib/currency';
import { index as adsIndex } from '@/routes/ads';
import type {
    PaymentTransactionItem,
    WalletAccountSummary,
    WalletLedgerEntry,
} from '@/types';

const props = defineProps<{
    account: WalletAccountSummary;
    entries: WalletLedgerEntry[];
    payments: PaymentTransactionItem[];
    sandboxEnabled: boolean;
    minAmount: number;
    maxAmount: number;
}>();

const { t, locale } = useI18n();
const lang = computed<'vi' | 'en'>(() => (locale.value === 'en' ? 'en' : 'vi'));

const quickPresets = [200_000, 500_000, 1_000_000, 2_450_000];

function newIdempotencyKey(): string {
    return `topup-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

const form = useForm({
    amount: 500_000,
    provider: 'sandbox',
    idempotency_key: newIdempotencyKey(),
});

const selectedAmount = ref(500_000);

function chooseAmount(val: number): void {
    selectedAmount.value = val;
    form.amount = val;
}

function submitTopUp(): void {
    form.amount = Number(selectedAmount.value);
    form.post(WalletController.storeTopUp.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.idempotency_key = newIdempotencyKey();
        },
    });
}

function isCreditType(type: WalletLedgerEntry['type']): boolean {
    return type === 'deposit' || type === 'refund' || type === 'release';
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('wallet.title')" />

        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border bg-card p-6 shadow-xs"
        >
            <div>
                <div class="flex items-center gap-2 text-primary">
                    <WalletIcon class="size-5" aria-hidden="true" />
                    <h1 class="text-xl font-bold text-foreground">
                        {{ t('wallet.title') }}
                    </h1>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('wallet.subtitle') }}
                </p>
            </div>

            <Button class="rounded-lg" as-child>
                <Link :href="adsIndex()">
                    <Megaphone class="size-4" />
                    <span>{{ t('ads.tabs.promote') }}</span>
                </Link>
            </Button>
        </section>

        <!-- Wallet Balances Overview -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('wallet.balance') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-primary">
                    {{ formatVnd(account.balance, lang) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="flex items-center gap-1 text-xs font-medium text-muted-foreground"
                >
                    <Lock class="size-3.5" />
                    <span>{{ t('wallet.heldBalance') }}</span>
                </p>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ formatVnd(account.held_balance, lang) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('wallet.totalDeposited') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-success">
                    {{ formatVnd(account.total_deposited, lang) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('wallet.totalSpent') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ formatVnd(account.total_spent, lang) }}
                </p>
            </div>
        </section>

        <!-- Top-Up Panel -->
        <section class="rounded-xl border border-border bg-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-base font-semibold text-foreground"
                    >
                        <CreditCard class="size-4 text-primary" />
                        <span>{{ t('wallet.topUpTitle') }}</span>
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('wallet.topUpSubtitle') }}
                    </p>
                </div>
                <Badge variant="success" class="gap-1 text-[11px]">
                    <ShieldCheck class="size-3.5" />
                    <span>HMAC-SHA256</span>
                </Badge>
            </div>

            <div
                class="mt-3 rounded-lg border border-primary/20 bg-primary-soft px-3.5 py-2.5 text-xs text-accent-foreground"
            >
                {{
                    sandboxEnabled
                        ? t('wallet.sandboxNotice')
                        : t('wallet.productionNotice')
                }}
            </div>

            <form
                v-if="sandboxEnabled"
                @submit.prevent="submitTopUp"
                class="mt-5 space-y-4"
            >
                <div class="space-y-2">
                    <Label>{{ t('wallet.quickAmounts') }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="preset in quickPresets"
                            :key="preset"
                            type="button"
                            size="sm"
                            :variant="
                                selectedAmount === preset
                                    ? 'default'
                                    : 'outline'
                            "
                            class="rounded-lg"
                            @click="chooseAmount(preset)"
                        >
                            {{ formatVnd(preset, lang) }}
                        </Button>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="topup-amount">{{
                            t('wallet.customAmount')
                        }}</Label>
                        <Input
                            id="topup-amount"
                            v-model.number="selectedAmount"
                            type="number"
                            :min="minAmount"
                            :max="maxAmount"
                            step="10000"
                            required
                        />
                        <InputError :message="form.errors.amount" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="topup-provider">{{
                            t('wallet.paymentChannel')
                        }}</Label>
                        <select
                            id="topup-provider"
                            v-model="form.provider"
                            class="h-9 rounded-lg border border-input bg-background px-3 text-sm"
                        >
                            <option value="sandbox">Sandbox Simulator</option>
                            <option value="vnpay">VNPay QR</option>
                            <option value="momo">MoMo E-Wallet</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                        <InputError :message="form.errors.provider" />
                    </div>
                </div>

                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg"
                >
                    <Spinner v-if="form.processing" />
                    <Plus v-else class="size-4" />
                    <span>{{
                        form.processing
                            ? t('wallet.processingTopUp')
                            : t('wallet.topUpButton')
                    }}</span>
                </Button>
            </form>
        </section>

        <!-- Ledger & Payment Records -->
        <div class="grid gap-5 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <ReceiptText class="size-4 text-primary" />
                    <span>{{ t('wallet.ledgerTitle') }}</span>
                </h2>

                <div v-if="entries.length > 0" class="divide-y divide-border">
                    <div
                        v-for="entry in entries"
                        :key="entry.id"
                        class="flex items-start justify-between gap-3 py-3 text-xs"
                    >
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span
                                :class="[
                                    'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full',
                                    isCreditType(entry.type)
                                        ? 'bg-success-soft text-success-soft-foreground'
                                        : 'bg-primary-soft text-primary',
                                ]"
                            >
                                <ArrowDownLeft
                                    v-if="isCreditType(entry.type)"
                                    class="size-3.5"
                                />
                                <ArrowUpRight v-else class="size-3.5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-foreground">
                                    {{ entry.type_label }}
                                </p>
                                <p class="truncate text-muted-foreground">
                                    {{ entry.description }}
                                </p>
                                <p
                                    class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground"
                                >
                                    <Clock class="size-3" />
                                    <span>{{
                                        new Date(
                                            entry.created_at,
                                        ).toLocaleString()
                                    }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <p
                                :class="[
                                    'font-bold',
                                    isCreditType(entry.type)
                                        ? 'text-success'
                                        : 'text-foreground',
                                ]"
                            >
                                {{ isCreditType(entry.type) ? '+' : '-'
                                }}{{ formatVnd(entry.amount, lang) }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ formatVnd(entry.balance_after, lang) }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    {{ t('wallet.emptyLedger') }}
                </p>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <CreditCard class="size-4 text-primary" />
                    <span>{{ t('wallet.paymentsTitle') }}</span>
                </h2>

                <div v-if="payments.length > 0" class="divide-y divide-border">
                    <div
                        v-for="payment in payments"
                        :key="payment.id"
                        class="flex items-center justify-between gap-3 py-3 text-xs"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span
                                    class="font-semibold text-foreground uppercase"
                                >
                                    {{ payment.provider }}
                                </span>
                                <Badge
                                    :variant="
                                        payment.status === 'paid'
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    class="text-[10px]"
                                >
                                    {{ payment.status_label }}
                                </Badge>
                            </div>
                            <p class="mt-0.5 truncate text-muted-foreground">
                                {{
                                    payment.provider_reference ||
                                    `#${payment.id}`
                                }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="font-bold text-foreground">
                                {{ formatVnd(payment.amount, lang) }}
                            </p>
                            <p
                                v-if="payment.created_at"
                                class="text-[11px] text-muted-foreground"
                            >
                                {{
                                    new Date(
                                        payment.created_at,
                                    ).toLocaleDateString()
                                }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    {{ t('wallet.emptyPayments') }}
                </p>
            </section>
        </div>
    </div>
</template>
