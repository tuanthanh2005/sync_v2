<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Crown, Info, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PaymentConfirmModal from '@/components/monetization/PaymentConfirmModal.vue';
import PendingLimitModal from '@/components/monetization/PendingLimitModal.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/currency';
import { formatDate } from '@/lib/formatters';
import { login } from '@/routes';
import { premium as accountPremiumRoute } from '@/routes/account';
import { checkout as premiumCheckoutRoute } from '@/routes/premium';
import type {
    PaymentMethodPublic,
    PlatformCapabilities,
    PremiumPlanItem,
} from '@/types';

const props = defineProps<{
    plans: PremiumPlanItem[];
    paymentMethods: PaymentMethodPublic[];
    platformCapabilities: PlatformCapabilities;
    isPremium: boolean;
    premiumUntil: string | null;
    pendingOrdersCount: number;
    maxPendingOrders: number;
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

const currency = ref<'VND' | 'USD'>(locale.value === 'vi' ? 'VND' : 'USD');
const selectedPlan = ref<PremiumPlanItem | null>(
    props.plans.find((p) => p.is_featured) ?? props.plans[0] ?? null,
);
const selectedProvider = ref<string>(props.paymentMethods[0]?.code ?? 'vietqr');
const confirmOpen = ref(false);
const limitOpen = ref(false);

const eligibleMethods = computed(() =>
    props.paymentMethods.filter((m) =>
        m.supported_currencies.includes(currency.value),
    ),
);

const activeMethod = computed(
    () =>
        eligibleMethods.value.find((m) => m.code === selectedProvider.value) ??
        eligibleMethods.value[0] ??
        null,
);

function newKey(): string {
    return `premium-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

const form = useForm({
    plan_id: 0,
    currency: 'VND',
    provider: 'vietqr',
    idempotency_key: newKey(),
    confirmed: true,
});

function planPrice(plan: PremiumPlanItem): number {
    return currency.value === 'USD'
        ? plan.effective_usd_cents
        : plan.effective_vnd;
}

function requestBuy(plan: PremiumPlanItem): void {
    selectedPlan.value = plan;

    if (!user.value) {
        router.visit(login.url());

        return;
    }

    if (props.pendingOrdersCount >= props.maxPendingOrders) {
        limitOpen.value = true;

        return;
    }

    if (activeMethod.value) {
        selectedProvider.value = activeMethod.value.code;
    }

    confirmOpen.value = true;
}

function submitPurchase(): void {
    if (!selectedPlan.value || !activeMethod.value || form.processing) {
        return;
    }

    form.plan_id = selectedPlan.value.id;
    form.currency = currency.value;
    form.provider = activeMethod.value.code;
    form.idempotency_key = newKey();
    form.confirmed = true;

    form.post(premiumCheckoutRoute.url(), {
        preserveScroll: true,
        onError: (errors) => {
            confirmOpen.value = false;
            if (errors.pending_limit) {
                limitOpen.value = true;
            }
        },
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.premium.pageTitle')" />

        <!-- Header -->
        <section
            class="rounded-2xl border border-border bg-card p-6 shadow-xs sm:p-8"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl space-y-2">
                    <div class="flex items-center gap-2">
                        <Badge variant="soft" class="gap-1.5 text-xs">
                            <Crown class="size-3.5" />
                            <span>SyncViet Premium</span>
                        </Badge>
                        <PremiumBadge size="md" />
                    </div>
                    <h1
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ t('monetization.premium.heading') }}
                    </h1>
                    <p
                        class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                    >
                        {{ t('monetization.premium.subtitle') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div
                        class="inline-flex rounded-xl border border-border bg-muted/40 p-1"
                    >
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currency === 'VND'
                                    ? 'bg-card text-primary shadow-2xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="currency = 'VND'"
                        >
                            VND (₫)
                        </button>
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currency === 'USD'
                                    ? 'bg-card text-primary shadow-2xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="currency = 'USD'"
                        >
                            USD ($)
                        </button>
                    </div>
                    <Button
                        v-if="user && isPremium"
                        variant="outline"
                        size="sm"
                        class="rounded-xl"
                        as-child
                    >
                        <Link :href="accountPremiumRoute()">
                            {{ t('monetization.premium.manageMyPlan') }}
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="isPremium && premiumUntil"
                class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/25 bg-primary-soft px-4 py-3 text-xs text-accent-foreground"
            >
                <span class="font-medium">
                    {{
                        t('monetization.premium.activeBanner', {
                            date: formatDate(premiumUntil, locale),
                        })
                    }}
                </span>
                <span class="text-[11px] opacity-85">
                    {{ t('monetization.premium.extendPreservesDays') }}
                </span>
            </div>

            <div
                v-if="!platformCapabilities.allow_premium_purchase"
                class="mt-4 rounded-xl border border-warning/40 bg-warning-soft p-3.5 text-xs text-foreground"
            >
                {{
                    platformCapabilities.policy_note ||
                    t('monetization.compliance.storeNotice')
                }}
            </div>
        </section>

        <!-- Payment Method Selector (for authenticated users) -->
        <section
            v-if="
                user &&
                eligibleMethods.length > 0 &&
                platformCapabilities.allow_premium_purchase
            "
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4"
        >
            <div class="text-xs">
                <p class="font-semibold text-foreground">
                    {{ t('monetization.premium.paymentMethodLabel') }}
                </p>
                <p class="text-muted-foreground">
                    {{ t('monetization.premium.noAutoAdBalanceDebit') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="m in eligibleMethods"
                    :key="m.code"
                    type="button"
                    :class="[
                        'min-h-9 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors',
                        activeMethod?.code === m.code
                            ? 'border-primary bg-primary-soft text-accent-foreground'
                            : 'border-border text-muted-foreground hover:text-foreground',
                    ]"
                    @click="selectedProvider = m.code"
                >
                    {{ m.name }}
                </button>
            </div>
        </section>

        <InputError
            :message="
                form.errors.plan_id ||
                form.errors.provider ||
                form.errors.currency
            "
        />

        <!-- Pricing Cards (100% Admin-Configured from DB) -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <article
                v-for="plan in plans"
                :key="plan.id"
                :class="[
                    'flex flex-col justify-between rounded-2xl border bg-card p-5 shadow-xs transition-colors',
                    plan.is_featured
                        ? 'border-primary ring-1 ring-primary/30'
                        : 'border-border',
                ]"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-bold text-foreground">
                            {{ plan.name }}
                        </h2>
                        <Badge
                            v-if="plan.is_featured"
                            variant="soft"
                            class="gap-1 text-[10px]"
                        >
                            <Sparkles class="size-2.5" />
                            {{ t('monetization.premium.popular') }}
                        </Badge>
                    </div>

                    <div>
                        <p class="text-2xl font-bold text-primary">
                            {{ formatMoney(planPrice(plan), currency, locale) }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ plan.duration_days }}
                            {{ t('monetization.common.days') }}
                        </p>
                    </div>

                    <p
                        v-if="plan.description"
                        class="text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ plan.description }}
                    </p>

                    <ul class="space-y-1.5 pt-1 text-xs text-muted-foreground">
                        <li
                            v-for="benefit in plan.benefits"
                            :key="benefit"
                            class="flex items-start gap-2"
                        >
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t(`monetization.benefits.${benefit}`, benefit)
                            }}</span>
                        </li>
                    </ul>
                </div>

                <Button
                    type="button"
                    :variant="plan.is_featured ? 'default' : 'outline'"
                    class="mt-5 min-h-10 w-full rounded-xl"
                    :disabled="
                        !platformCapabilities.allow_premium_purchase ||
                        form.processing
                    "
                    @click="requestBuy(plan)"
                >
                    {{
                        isPremium
                            ? t('monetization.premium.extendButton')
                            : t('monetization.premium.buyButton')
                    }}
                </Button>
            </article>
        </section>

        <!-- Badge Transparency Notice -->
        <section
            class="grid gap-4 rounded-2xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="flex items-start gap-3">
                <PremiumBadge size="md" />
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('monetization.badge.title') }}
                    </h3>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        {{ t('monetization.badge.tooltip') }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-primary" />
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('monetization.premium.moderationRuleTitle') }}
                    </h3>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        {{ t('monetization.premium.moderationRuleDesc') }}
                    </p>
                </div>
            </div>
        </section>

        <div class="flex items-center gap-2 text-xs text-muted-foreground">
            <Info class="size-4 shrink-0 text-primary" />
            <span>{{ t('monetization.premium.manualConfirmHint') }}</span>
        </div>

        <PaymentConfirmModal
            v-if="selectedPlan && activeMethod"
            v-model:open="confirmOpen"
            service-name="SyncViet Premium"
            :item-name="selectedPlan.name"
            :duration-label="`${selectedPlan.duration_days} ${t('monetization.common.days')}`"
            :amount="planPrice(selectedPlan)"
            :currency="currency"
            :method-name="activeMethod.name"
            :processing="form.processing"
            @confirm="submitPurchase"
        />

        <PendingLimitModal
            v-model:open="limitOpen"
            :max-pending="maxPendingOrders"
        />
    </div>
</template>
