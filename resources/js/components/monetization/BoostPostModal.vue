<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, Sparkles, Wallet as WalletIcon, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import InputError from '@/components/InputError.vue';
import PaymentConfirmModal from '@/components/monetization/PaymentConfirmModal.vue';
import PendingLimitModal from '@/components/monetization/PendingLimitModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMoney, formatVnd } from '@/lib/currency';
import type { BoostPackageItem } from '@/types';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    postId: number;
    postExcerpt: string;
}>();

const { t, locale } = useI18n();
const page = usePage();
const walletBalance = computed(() => page.props.walletBalance ?? 0);
const packages = computed(
    () => page.props.monetizationQuick?.boostPackages ?? [],
);
const paymentMethods = computed(
    () => page.props.monetizationQuick?.paymentMethods ?? [],
);
const maxPending = computed(
    () => page.props.monetizationQuick?.maxPendingOrders ?? 5,
);

const selectedPackage = ref<BoostPackageItem | null>(null);
const paymentSource = ref<'ad_balance' | 'direct'>('ad_balance');
const currency = ref<'VND' | 'USD'>('VND');
const provider = ref<string>('vietqr');
const confirmOpen = ref(false);
const limitOpen = ref(false);

const activePkg = computed<BoostPackageItem | null>(
    () => selectedPackage.value ?? packages.value[0] ?? null,
);

const eligibleDirectMethods = computed(() =>
    paymentMethods.value.filter((m) =>
        m.supported_currencies.includes(currency.value),
    ),
);

const activeAmount = computed(() => {
    if (!activePkg.value) {
        return 0;
    }

    if (paymentSource.value === 'ad_balance' || currency.value === 'VND') {
        return activePkg.value.price_vnd;
    }

    return activePkg.value.price_usd_cents;
});

function newKey(): string {
    return `boost-${props.postId}-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

const form = useForm({
    boost_package_id: 0,
    payment_source: 'ad_balance',
    currency: 'VND',
    provider: 'vietqr',
    idempotency_key: newKey(),
    confirmed: true,
});

const formErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function openConfirm(): void {
    if (!activePkg.value) {
        return;
    }
    if (
        paymentSource.value === 'direct' &&
        eligibleDirectMethods.value.length > 0 &&
        !eligibleDirectMethods.value.some((m) => m.code === provider.value)
    ) {
        provider.value = eligibleDirectMethods.value[0].code;
    }
    confirmOpen.value = true;
}

function executeBoost(): void {
    if (!activePkg.value || form.processing) {
        return;
    }

    form.boost_package_id = activePkg.value.id;
    form.payment_source = paymentSource.value;
    form.currency =
        paymentSource.value === 'ad_balance' ? 'VND' : currency.value;
    form.provider =
        paymentSource.value === 'ad_balance' ? 'ad_balance' : provider.value;
    form.idempotency_key = newKey();
    form.confirmed = true;

    form.post(`/posts/${props.postId}/boost`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
            open.value = false;
        },
        onError: (errors) => {
            confirmOpen.value = false;
            if (errors.pending_limit) {
                limitOpen.value = true;
            }
        },
    });
}
</script>

<template>
    <div>
        <Dialog v-model:open="open">
            <DialogContent structured class="max-w-lg rounded-2xl">
                <!-- Fixed Modal Header -->
                <div
                    class="shrink-0 border-b border-border/70 px-5 pe-12 pt-4 pb-3.5 sm:px-6 sm:pt-5 sm:pb-4"
                >
                    <div
                        class="mx-auto mb-3 h-1 w-10 rounded-full bg-border sm:hidden"
                        aria-hidden="true"
                    />
                    <DialogHeader class="text-start">
                        <DialogTitle class="flex items-center gap-2 text-base">
                            <Zap class="size-5 shrink-0 text-primary" />
                            <span>{{
                                t('monetization.boost.modalTitle')
                            }}</span>
                        </DialogTitle>
                        <DialogDescription class="line-clamp-2 text-xs">
                            {{ postExcerpt }}
                        </DialogDescription>
                    </DialogHeader>
                </div>

                <!-- Independently Scrollable Modal Body (4px auto-hide scrollbar) -->
                <AutoHideScrollArea class="flex-1 space-y-4 px-5 py-4 sm:px-6">
                    <div class="grid gap-2.5">
                        <button
                            v-for="pkg in packages"
                            :key="pkg.id"
                            type="button"
                            :class="[
                                'flex w-full items-center justify-between gap-3 rounded-xl border p-3.5 text-start transition-colors',
                                activePkg?.id === pkg.id
                                    ? 'border-primary bg-primary-soft/60 ring-1 ring-primary/20'
                                    : 'border-border bg-card hover:border-primary/40',
                            ]"
                            @click="selectedPackage = pkg"
                        >
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ pkg.name }}
                                    </span>
                                    <Badge
                                        v-if="pkg.is_featured_slot"
                                        variant="soft"
                                        class="gap-1 text-[10px]"
                                    >
                                        <Sparkles class="size-2.5" />
                                        {{ t('monetization.boost.featured') }}
                                    </Badge>
                                </div>
                                <p
                                    v-if="pkg.description"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    {{ pkg.description }}
                                </p>
                            </div>
                            <div class="shrink-0 text-end tabular-nums">
                                <p class="text-sm font-bold text-primary">
                                    {{ formatVnd(pkg.price_vnd, locale) }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{
                                        formatMoney(
                                            pkg.price_usd_cents,
                                            'USD',
                                            locale,
                                        )
                                    }}
                                </p>
                            </div>
                        </button>
                    </div>

                    <div
                        class="space-y-2 rounded-xl border border-border p-3.5"
                    >
                        <p class="text-xs font-semibold text-foreground">
                            {{ t('monetization.boost.paymentSource') }}
                        </p>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                :class="[
                                    'flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium transition-colors',
                                    paymentSource === 'ad_balance'
                                        ? 'border-primary bg-primary-soft text-accent-foreground'
                                        : 'border-border text-muted-foreground',
                                ]"
                                @click="paymentSource = 'ad_balance'"
                            >
                                <WalletIcon class="size-4 shrink-0" />
                                <span class="truncate">{{
                                    t('monetization.wallet.title')
                                }}</span>
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium transition-colors',
                                    paymentSource === 'direct'
                                        ? 'border-primary bg-primary-soft text-accent-foreground'
                                        : 'border-border text-muted-foreground',
                                ]"
                                @click="paymentSource = 'direct'"
                            >
                                <Check class="size-4 shrink-0" />
                                <span class="truncate">{{
                                    t('monetization.boost.directCheckout')
                                }}</span>
                            </button>
                        </div>

                        <p
                            v-if="paymentSource === 'ad_balance'"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ t('monetization.wallet.availableShort') }}:
                            <strong class="text-foreground tabular-nums">{{
                                formatVnd(walletBalance, locale)
                            }}</strong>
                        </p>

                        <div v-else class="grid grid-cols-2 gap-2 pt-1">
                            <select
                                v-model="currency"
                                class="min-h-10 rounded-lg border border-input bg-background px-2.5 text-xs"
                            >
                                <option value="VND">VND</option>
                                <option value="USD">USD</option>
                            </select>
                            <select
                                v-model="provider"
                                class="min-h-10 rounded-lg border border-input bg-background px-2.5 text-xs"
                            >
                                <option
                                    v-for="m in eligibleDirectMethods"
                                    :key="m.code"
                                    :value="m.code"
                                >
                                    {{ m.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <InputError
                        :message="
                            formErrors.post_id ||
                            formErrors.boost_package_id ||
                            formErrors.budget ||
                            formErrors.provider
                        "
                    />
                </AutoHideScrollArea>

                <!-- Fixed Modal Footer (Total & CTA always visible) -->
                <div
                    class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border/70 bg-card/95 px-5 py-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:px-6 sm:py-4"
                >
                    <div v-if="activePkg" class="min-w-0">
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('monetization.confirmModal.total') }}
                        </p>
                        <p
                            class="text-base font-bold text-primary tabular-nums"
                        >
                            {{
                                formatMoney(
                                    activeAmount,
                                    paymentSource === 'ad_balance'
                                        ? 'VND'
                                        : currency,
                                    locale,
                                )
                            }}
                        </p>
                    </div>
                    <div class="ms-auto flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-10 rounded-xl sm:min-h-9"
                            @click="open = false"
                        >
                            {{ t('common.cancel') }}
                        </Button>
                        <Button
                            type="button"
                            class="min-h-10 rounded-xl sm:min-h-9"
                            :disabled="!activePkg || form.processing"
                            @click="openConfirm"
                        >
                            <Zap class="size-4" />
                            <span>{{ t('monetization.boost.ctaButton') }}</span>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <PaymentConfirmModal
            v-if="activePkg"
            v-model:open="confirmOpen"
            :service-name="t('monetization.services.boostTitle')"
            :item-name="activePkg.name"
            :duration-label="`${activePkg.duration_hours}h`"
            :amount="activeAmount"
            :currency="paymentSource === 'ad_balance' ? 'VND' : currency"
            :method-name="
                paymentSource === 'ad_balance'
                    ? t('monetization.wallet.title')
                    : provider.toUpperCase()
            "
            :processing="form.processing"
            @confirm="executeBoost"
        />

        <PendingLimitModal v-model:open="limitOpen" :max-pending="maxPending" />
    </div>
</template>
