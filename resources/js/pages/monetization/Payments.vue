<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Copy, ReceiptText, Search, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/currency';
import { formatDateTime } from '@/lib/formatters';
import { appToast } from '@/lib/toast';
import {
    cancel as cancelPaymentRoute,
    index as paymentsIndex,
    sandboxConfirm as sandboxConfirmRoute,
} from '@/routes/payments';
import type { PaymentTransactionItem } from '@/types';

const props = defineProps<{
    payments: {
        data: PaymentTransactionItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        service_type: string;
        status: string;
        search: string;
        highlight: string;
    };
    openPendingTotal: number;
    openPendingTopUpCount: number;
    maxPendingOrders: number;
    sandboxEnabled: boolean;
    paymentMethodInstructions?: Record<
        string,
        Record<string, string | number | boolean | null>
    >;
}>();

function instructionsFor(
    p: PaymentTransactionItem,
): Record<string, string | number | boolean | null> {
    const live = props.paymentMethodInstructions?.[p.provider];
    if (live && Object.keys(live).length > 0) {
        return live;
    }
    const meta = (p.metadata ?? {}) as Record<string, unknown>;
    if (meta.instructions && typeof meta.instructions === 'object') {
        return meta.instructions as Record<
            string,
            string | number | boolean | null
        >;
    }
    return {};
}

function vietQrImageUrl(p: PaymentTransactionItem): string | null {
    const info = instructionsFor(p);
    const bankCode = String(info.bank_code ?? '').trim();
    const accountNumber = String(info.account_number ?? '').trim();
    if (!bankCode || !accountNumber) {
        return null;
    }
    const amount = p.total_amount ?? p.amount;
    const addInfo = encodeURIComponent(p.order_code || `PAY-${p.id}`);
    const accountName = encodeURIComponent(
        String(info.account_holder ?? '').trim(),
    );
    return `https://img.vietqr.io/image/${encodeURIComponent(bankCode)}-${encodeURIComponent(accountNumber)}-compact2.png?amount=${amount}&addInfo=${addInfo}&accountName=${accountName}`;
}

const { t, locale } = useI18n();
const searchInput = ref(props.filters.search);
const serviceFilter = ref(props.filters.service_type);
const statusFilter = ref(props.filters.status);
const cancelTargetId = ref<number | null>(null);
const cancelling = ref(false);

function applyFilters(): void {
    router.get(
        paymentsIndex.url({
            query: {
                service_type: serviceFilter.value,
                status: statusFilter.value,
                search: searchInput.value.trim() || undefined,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

async function copyText(val: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(val);
        appToast.success(t('common.copied'));
    } catch {
        appToast.error(t('common.copyFailed'));
    }
}

function executeCancel(): void {
    if (cancelTargetId.value === null) {
        return;
    }

    cancelling.value = true;
    router.post(
        cancelPaymentRoute.url(cancelTargetId.value),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancelling.value = false;
                cancelTargetId.value = null;
            },
        },
    );
}

function runSandboxConfirm(paymentId: number): void {
    router.post(
        sandboxConfirmRoute.url(paymentId),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('monetization.payments.title')" />

        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border bg-card p-6 shadow-xs"
        >
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <ReceiptText class="size-5 text-primary" />
                    <span>{{ t('monetization.payments.title') }}</span>
                </h1>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('monetization.payments.subtitle') }}
                </p>
            </div>
            <Badge
                :variant="
                    openPendingTopUpCount >= maxPendingOrders
                        ? 'destructive'
                        : 'secondary'
                "
                class="text-xs"
            >
                {{ t('monetization.payments.openPendingLabel') }}:
                {{ openPendingTotal }} / {{ maxPendingOrders }}
            </Badge>
        </section>

        <!-- Filters -->
        <form
            @submit.prevent="applyFilters"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3"
        >
            <div class="relative min-w-[180px] flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="searchInput"
                    type="search"
                    :placeholder="t('monetization.payments.searchPlaceholder')"
                    class="pl-9 text-xs"
                />
            </div>
            <select
                v-model="serviceFilter"
                class="min-h-10 rounded-lg border border-input bg-background px-3 text-xs"
                @change="applyFilters"
            >
                <option value="all">
                    {{ t('monetization.payments.allServices') }}
                </option>
                <option value="premium">SyncViet Premium</option>
                <option value="banner">Banner Ads</option>
                <option value="boost">Boost Post</option>
                <option value="topup">
                    {{ t('monetization.wallet.title') }}
                </option>
            </select>
            <select
                v-model="statusFilter"
                class="min-h-10 rounded-lg border border-input bg-background px-3 text-xs"
                @change="applyFilters"
            >
                <option value="all">
                    {{ t('monetization.payments.allStatuses') }}
                </option>
                <option value="pending">
                    {{ t('monetization.payments.statusPending') }}
                </option>
                <option value="paid">
                    {{ t('monetization.payments.statusPaid') }}
                </option>
                <option value="cancelled">
                    {{ t('monetization.payments.statusCancelled') }}
                </option>
                <option value="refunded">
                    {{ t('monetization.payments.statusRefunded') }}
                </option>
            </select>
            <Button type="submit" size="sm" class="min-h-10 rounded-lg">
                {{ t('search.button') }}
            </Button>
        </form>

        <!-- Orders List -->
        <section v-if="payments.data.length > 0" class="space-y-3">
            <article
                v-for="p in payments.data"
                :key="p.id"
                :class="[
                    'space-y-3 rounded-xl border bg-card p-4 shadow-xs',
                    filters.highlight &&
                    (p.order_code === filters.highlight ||
                        String(p.id) === filters.highlight)
                        ? 'border-primary ring-1 ring-primary/30'
                        : 'border-border',
                ]"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="font-mono text-xs font-bold text-foreground"
                            >
                                {{ p.order_code || `#${p.id}` }}
                            </span>
                            <button
                                type="button"
                                class="inline-flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                                :aria-label="t('common.copy')"
                                @click="copyText(p.order_code || `#${p.id}`)"
                            >
                                <Copy class="size-3.5" />
                            </button>
                            <Badge variant="soft" class="text-[10px]">
                                {{ p.service_type_label || p.service_type }}
                            </Badge>
                            <Badge
                                :variant="
                                    p.status === 'paid'
                                        ? 'success'
                                        : p.status === 'pending'
                                          ? 'secondary'
                                          : 'outline'
                                "
                                class="text-[10px]"
                            >
                                {{ p.status_label }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ p.provider.toUpperCase() }}
                            <span v-if="p.provider_reference">
                                · {{ p.provider_reference }}
                            </span>
                            <span v-if="p.created_at">
                                · {{ formatDateTime(p.created_at, locale) }}
                            </span>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-base font-bold text-foreground">
                            {{
                                formatMoney(
                                    p.total_amount ?? p.amount,
                                    p.currency,
                                    locale,
                                )
                            }}
                        </p>
                        <p
                            v-if="p.refunded_amount && p.refunded_amount > 0"
                            class="text-[11px] text-warning"
                        >
                            {{ t('monetization.payments.refundedLabel') }}:
                            {{
                                formatMoney(
                                    p.refunded_amount,
                                    p.currency,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                </div>

                <!-- Pending Payment Instructions & Actions -->
                <div
                    v-if="p.status === 'pending'"
                    class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border/80 bg-muted/30 p-3.5 text-xs"
                >
                    <div class="flex flex-wrap items-start gap-4">
                        <img
                            v-if="
                                (p.provider === 'vietqr' ||
                                    p.provider === 'bank_transfer') &&
                                vietQrImageUrl(p)
                            "
                            :src="vietQrImageUrl(p) || ''"
                            alt="VietQR"
                            class="size-36 rounded-lg border border-border bg-white object-contain p-1"
                        />
                        <div class="space-y-1 text-muted-foreground">
                            <p class="font-semibold text-foreground">
                                {{
                                    t(
                                        'monetization.payments.pendingInstructionsTitle',
                                    )
                                }}
                            </p>
                            <template
                                v-if="
                                    p.provider === 'vietqr' ||
                                    p.provider === 'bank_transfer'
                                "
                            >
                                <p v-if="instructionsFor(p).bank_name">
                                    Ngân hàng:
                                    <strong class="text-foreground">{{
                                        instructionsFor(p).bank_name
                                    }}</strong>
                                </p>
                                <p
                                    v-if="instructionsFor(p).account_number"
                                    class="flex items-center gap-1.5"
                                >
                                    Số tài khoản:
                                    <strong class="font-mono text-foreground">{{
                                        instructionsFor(p).account_number
                                    }}</strong>
                                    <button
                                        type="button"
                                        class="inline-flex size-5 items-center justify-center rounded hover:bg-muted"
                                        @click="
                                            copyText(
                                                String(
                                                    instructionsFor(p)
                                                        .account_number,
                                                ),
                                            )
                                        "
                                    >
                                        <Copy class="size-3" />
                                    </button>
                                </p>
                                <p v-if="instructionsFor(p).account_holder">
                                    Chủ tài khoản:
                                    <strong class="text-foreground">{{
                                        instructionsFor(p).account_holder
                                    }}</strong>
                                </p>
                            </template>
                            <template v-else-if="p.provider === 'paypal'">
                                <p
                                    v-if="instructionsFor(p).merchant_email"
                                    class="flex items-center gap-1.5"
                                >
                                    PayPal:
                                    <strong class="font-mono text-foreground">{{
                                        instructionsFor(p).merchant_email
                                    }}</strong>
                                    <button
                                        type="button"
                                        class="inline-flex size-5 items-center justify-center rounded hover:bg-muted"
                                        @click="
                                            copyText(
                                                String(
                                                    instructionsFor(p)
                                                        .merchant_email,
                                                ),
                                            )
                                        "
                                    >
                                        <Copy class="size-3" />
                                    </button>
                                </p>
                                <p v-if="instructionsFor(p).paypal_me_url">
                                    <a
                                        :href="
                                            String(
                                                instructionsFor(p)
                                                    .paypal_me_url,
                                            )
                                        "
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold text-primary underline"
                                    >
                                        Mở link thanh toán PayPal.Me
                                    </a>
                                </p>
                            </template>
                            <p class="flex items-center gap-1.5">
                                {{
                                    t(
                                        'monetization.payments.transferNoteLabel',
                                    )
                                }}:
                                <strong class="font-mono text-foreground">{{
                                    p.order_code || `PAY-${p.id}`
                                }}</strong>
                                <button
                                    type="button"
                                    class="inline-flex size-5 items-center justify-center rounded hover:bg-muted"
                                    @click="
                                        copyText(p.order_code || `PAY-${p.id}`)
                                    "
                                >
                                    <Copy class="size-3" />
                                </button>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="sandboxEnabled"
                            type="button"
                            size="sm"
                            class="rounded-lg text-xs"
                            @click="runSandboxConfirm(p.id)"
                        >
                            <CheckCircle2 class="size-3.5" />
                            <span>{{
                                t('monetization.payments.sandboxConfirm')
                            }}</span>
                        </Button>
                        <Button
                            v-if="p.can_cancel"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="rounded-lg text-xs text-destructive"
                            @click="cancelTargetId = p.id"
                        >
                            <XCircle class="size-3.5" />
                            <span>{{
                                t('monetization.payments.cancelOrder')
                            }}</span>
                        </Button>
                    </div>
                </div>
            </article>
        </section>

        <div
            v-else
            class="rounded-2xl border border-border bg-card p-8 text-center text-sm text-muted-foreground"
        >
            {{ t('monetization.payments.empty') }}
        </div>

        <ConfirmActionDialog
            :open="cancelTargetId !== null"
            :title="t('monetization.payments.cancelOrder')"
            :description="t('monetization.payments.cancelOrderConfirm')"
            :confirm-label="t('monetization.payments.cancelOrder')"
            :processing="cancelling"
            destructive
            @update:open="
                (v) => {
                    if (!v) cancelTargetId = null;
                }
            "
            @confirm="executeCancel"
        />
    </div>
</template>
