<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Check,
    EyeOff,
    Headset,
    Lock,
    ShieldCheck,
    Sparkles,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PaymentConfirmModal from '@/components/monetization/PaymentConfirmModal.vue';
import PendingLimitModal from '@/components/monetization/PendingLimitModal.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import { Button } from '@/components/ui/button';
import { defaultCurrencyForLocale, formatMoney } from '@/lib/currency';
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

type PlanPricingCard = {
    plan: PremiumPlanItem;
    months: number;
    durationLabel: string;
    displayTitle: string;
    effectivePrice: number;
    listPrice: number;
    monthlyAverage: number;
    compareAtPrice: number;
    savingsAmount: number;
    savingsPercent: number;
    isBestValue: boolean;
    isPopular: boolean;
    isAdFree: boolean;
    hasReducedAds: boolean;
};

type BenefitPresentation = {
    key: string;
    title: string;
    description: string;
    icon: Component | null;
    isBlueBadge: boolean;
    isAdFreeHighlight: boolean;
};

const { t, te, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

const currency = ref<'VND' | 'USD'>(defaultCurrencyForLocale(locale.value));
const selectedProvider = ref<string>(props.paymentMethods[0]?.code ?? 'vietqr');
const confirmOpen = ref(false);
const limitOpen = ref(false);
const radioRefs = ref<Array<HTMLButtonElement | null>>([]);

function monthsForDuration(days: number): number {
    if (days >= 360 && days <= 370) {
        return 12;
    }

    if (days >= 28 && days <= 31) {
        return 1;
    }

    if (days % 30 === 0 && days >= 30) {
        return days / 30;
    }

    return Math.max(1, Math.round(days / 30));
}

function isExactMonthDuration(days: number): boolean {
    return (
        (days >= 28 && days <= 31) ||
        (days >= 360 && days <= 370) ||
        (days >= 30 && days % 30 === 0)
    );
}

function formatDurationLabel(days: number): string {
    if (isExactMonthDuration(days)) {
        const months = monthsForDuration(days);
        if (months === 1) {
            return t('monetization.premium.durationOneMonth');
        }

        return t('monetization.premium.durationMonths', { count: months });
    }

    return t('monetization.premium.durationDays', { count: days });
}

function localizedPlanTitle(
    plan: PremiumPlanItem,
    durationLabel: string,
): string {
    const isSeededEnglishName = /^Premium\s+\d+\s+Months?$/i.test(
        plan.name.trim(),
    );

    if (isSeededEnglishName) {
        return t('monetization.checkout.premiumPlanTitle', {
            duration: durationLabel,
        });
    }

    return plan.name;
}

const orderedPlans = computed<PremiumPlanItem[]>(() =>
    [...props.plans].sort(
        (a, b) =>
            a.sort_order - b.sort_order || a.duration_days - b.duration_days,
    ),
);

const baselinePlan = computed<PremiumPlanItem | null>(() => {
    if (orderedPlans.value.length === 0) {
        return null;
    }

    return (
        orderedPlans.value.find(
            (p) => monthsForDuration(p.duration_days) === 1,
        ) ?? orderedPlans.value[0]
    );
});

const pricingCards = computed<PlanPricingCard[]>(() => {
    const base = baselinePlan.value;
    const baseMonths = base ? monthsForDuration(base.duration_days) : 1;
    const baseEffective = base
        ? currency.value === 'USD'
            ? base.effective_usd_cents
            : base.effective_vnd
        : 0;
    const baseMonthlyUnit = baseMonths > 0 ? baseEffective / baseMonths : 0;

    const rawCards = orderedPlans.value.map((plan) => {
        const months = monthsForDuration(plan.duration_days);
        const durationLabel = formatDurationLabel(plan.duration_days);
        const effectivePrice =
            currency.value === 'USD'
                ? plan.effective_usd_cents
                : plan.effective_vnd;
        const listPrice =
            currency.value === 'USD' ? plan.price_usd_cents : plan.price_vnd;
        const monthlyAverage = Math.round(effectivePrice / months);
        const baselineTotal = Math.round(baseMonthlyUnit * months);

        let compareAtPrice = listPrice;
        if (base && plan.id !== base.id && baselineTotal > effectivePrice) {
            compareAtPrice = Math.max(listPrice, baselineTotal);
        }

        const savingsAmount = Math.max(0, compareAtPrice - effectivePrice);
        const savingsPercent =
            compareAtPrice > 0 && savingsAmount > 0
                ? Math.round((savingsAmount / compareAtPrice) * 100)
                : 0;

        const isAdFree =
            plan.ad_reduction_mode === 'ad_free' ||
            plan.benefits.includes('ad_free');
        const hasReducedAds =
            !isAdFree &&
            (plan.ad_reduction_mode === 'reduced' ||
                plan.benefits.includes('reduced_ads'));

        return {
            plan,
            months,
            durationLabel,
            displayTitle: localizedPlanTitle(plan, durationLabel),
            effectivePrice,
            listPrice,
            monthlyAverage,
            compareAtPrice,
            savingsAmount,
            savingsPercent,
            isBestValue: false,
            isPopular: false,
            isAdFree,
            hasReducedAds,
        };
    });

    const maxSavings = rawCards.reduce(
        (max, card) => Math.max(max, card.savingsPercent),
        0,
    );

    let bestValueAssigned = false;

    return rawCards.map((card) => {
        const isBestValue =
            !bestValueAssigned &&
            maxSavings > 0 &&
            card.savingsPercent === maxSavings;
        if (isBestValue) {
            bestValueAssigned = true;
        }

        const isPopular = !isBestValue && card.plan.is_featured;

        return {
            ...card,
            isBestValue,
            isPopular,
        };
    });
});

const defaultPlanId = computed<number | null>(() => {
    const bestValue = pricingCards.value.find((c) => c.isBestValue);
    if (bestValue) {
        return bestValue.plan.id;
    }

    const featured = pricingCards.value.find((c) => c.plan.is_featured);
    if (featured) {
        return featured.plan.id;
    }

    return pricingCards.value[0]?.plan.id ?? null;
});

const selectedPlanId = ref<number | null>(defaultPlanId.value);

watch(
    () => props.plans,
    () => {
        if (
            selectedPlanId.value === null ||
            !props.plans.some((p) => p.id === selectedPlanId.value)
        ) {
            selectedPlanId.value = defaultPlanId.value;
        }
    },
);

const selectedCard = computed<PlanPricingCard | null>(
    () =>
        pricingCards.value.find((c) => c.plan.id === selectedPlanId.value) ??
        pricingCards.value[0] ??
        null,
);

const adFreePlanCard = computed<PlanPricingCard | null>(
    () => pricingCards.value.find((c) => c.isAdFree) ?? null,
);

const showAdFreeUpgradeHint = computed<boolean>(
    () =>
        adFreePlanCard.value !== null &&
        selectedCard.value !== null &&
        !selectedCard.value.isAdFree,
);

const benefitIcons: Record<string, Component> = {
    post_analytics: BarChart3,
    profile_highlights: Sparkles,
    reduced_ads: EyeOff,
    ad_free: ShieldCheck,
    priority_support: Headset,
};

const activeBenefits = computed<BenefitPresentation[]>(() => {
    const plan = selectedCard.value?.plan;
    if (!plan) {
        return [];
    }

    const keys = [...plan.benefits];
    if (plan.ad_reduction_mode === 'ad_free' && !keys.includes('ad_free')) {
        keys.push('ad_free');
    } else if (
        plan.ad_reduction_mode === 'reduced' &&
        !keys.includes('reduced_ads') &&
        !keys.includes('ad_free')
    ) {
        keys.push('reduced_ads');
    }

    return keys
        .filter((key) => {
            if (key === 'reduced_ads' && keys.includes('ad_free')) {
                return false;
            }

            return true;
        })
        .map((key) => {
            const titleKey = `monetization.benefitDetails.${key}.title`;
            const descKey = `monetization.benefitDetails.${key}.desc`;
            const fallbackTitle = te(`monetization.benefits.${key}`)
                ? t(`monetization.benefits.${key}`)
                : key;
            const title = te(titleKey) ? t(titleKey) : fallbackTitle;
            const description = te(descKey) ? t(descKey) : '';

            return {
                key,
                title,
                description,
                icon: benefitIcons[key] ?? Check,
                isBlueBadge: key === 'blue_badge',
                isAdFreeHighlight: key === 'ad_free',
            };
        });
});

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

function selectPlan(planId: number): void {
    selectedPlanId.value = planId;
}

function handlePlanKeydown(event: KeyboardEvent, currentIndex: number): void {
    const total = pricingCards.value.length;
    if (total === 0) {
        return;
    }

    const isRtl =
        typeof document !== 'undefined' &&
        document.documentElement.dir === 'rtl';

    let nextIndex: number | null = null;
    if (event.key === 'ArrowDown') {
        nextIndex = (currentIndex + 1) % total;
    } else if (event.key === 'ArrowUp') {
        nextIndex = (currentIndex - 1 + total) % total;
    } else if (event.key === 'ArrowRight') {
        nextIndex = isRtl
            ? (currentIndex - 1 + total) % total
            : (currentIndex + 1) % total;
    } else if (event.key === 'ArrowLeft') {
        nextIndex = isRtl
            ? (currentIndex + 1) % total
            : (currentIndex - 1 + total) % total;
    }

    if (nextIndex !== null) {
        event.preventDefault();
        const nextCard = pricingCards.value[nextIndex];
        if (nextCard) {
            selectedPlanId.value = nextCard.plan.id;
            radioRefs.value[nextIndex]?.focus();
        }
    }
}

function setRadioRef(el: unknown, idx: number): void {
    radioRefs.value[idx] = el instanceof HTMLButtonElement ? el : null;
}

function requestUpgrade(): void {
    if (!selectedCard.value) {
        return;
    }

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
    if (!selectedCard.value || !activeMethod.value || form.processing) {
        return;
    }

    form.plan_id = selectedCard.value.plan.id;
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

        <!-- Hero Section -->
        <section
            class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-8"
        >
            <!-- Top utility row: Manage link + Currency switcher -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Button
                        v-if="user && isPremium"
                        variant="outline"
                        size="sm"
                        class="min-h-9 rounded-xl text-xs"
                        as-child
                    >
                        <Link :href="accountPremiumRoute()">
                            {{ t('monetization.premium.manageMyPlan') }}
                        </Link>
                    </Button>
                </div>

                <div
                    role="group"
                    :aria-label="t('monetization.premium.summaryTotal')"
                    class="inline-flex rounded-xl border border-border bg-muted/40 p-1"
                >
                    <button
                        type="button"
                        :aria-pressed="currency === 'VND'"
                        :class="[
                            'min-h-8 rounded-lg px-3 py-1 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            currency === 'VND'
                                ? 'bg-card text-primary shadow-2xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="currency = 'VND'"
                    >
                        {{ t('monetization.checkout.currencyVnd') }}
                    </button>
                    <button
                        type="button"
                        :aria-pressed="currency === 'USD'"
                        :class="[
                            'min-h-8 rounded-lg px-3 py-1 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            currency === 'USD'
                                ? 'bg-card text-primary shadow-2xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="currency = 'USD'"
                    >
                        {{ t('monetization.checkout.currencyUsd') }}
                    </button>
                </div>
            </div>

            <!-- Centered Premium Brand Centerpiece -->
            <div class="mx-auto mt-5 max-w-2xl text-center">
                <div
                    class="mx-auto inline-flex size-16 items-center justify-center rounded-2xl bg-[#1D9BF0]/10 ring-1 ring-[#1D9BF0]/20 sm:size-18"
                >
                    <PremiumBadge size="xl" :interactive="false" />
                </div>

                <h1
                    class="mt-4 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                >
                    {{ t('monetization.services.premiumTitle') }}
                </h1>

                <p
                    class="mt-2 text-base font-medium text-foreground/90 sm:text-lg"
                >
                    {{ t('monetization.premium.slogan') }}
                </p>

                <p
                    class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-muted-foreground"
                >
                    {{ t('monetization.premium.subtitle') }}
                </p>
            </div>

            <!-- Active Subscription Banner -->
            <div
                v-if="isPremium && premiumUntil"
                class="mx-auto mt-6 flex max-w-2xl flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/25 bg-primary-soft px-4 py-3 text-xs text-accent-foreground"
            >
                <span class="font-semibold">
                    {{
                        t('monetization.premium.activeBanner', {
                            date: formatDate(premiumUntil, locale),
                        })
                    }}
                </span>
                <span class="text-[11px] opacity-90">
                    {{ t('monetization.premium.extendPreservesDays') }}
                </span>
            </div>

            <!-- Platform Compliance Notice -->
            <div
                v-if="!platformCapabilities.allow_premium_purchase"
                class="mx-auto mt-5 max-w-2xl rounded-xl border border-warning/40 bg-warning-soft p-3.5 text-center text-xs text-foreground"
            >
                {{
                    platformCapabilities.policy_note ||
                    t('monetization.compliance.storeNotice')
                }}
            </div>
        </section>

        <!-- Step 1: Selectable Duration Pricing Cards -->
        <section aria-labelledby="premium-duration-heading" class="space-y-3.5">
            <div
                class="flex flex-wrap items-baseline justify-between gap-2 px-0.5"
            >
                <div>
                    <h2
                        id="premium-duration-heading"
                        class="text-base font-bold text-foreground sm:text-lg"
                    >
                        {{ t('monetization.premium.chooseDurationTitle') }}
                    </h2>
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        {{ t('monetization.premium.chooseDurationSubtitle') }}
                    </p>
                </div>
            </div>

            <div
                role="radiogroup"
                aria-labelledby="premium-duration-heading"
                class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-4"
            >
                <button
                    v-for="(card, index) in pricingCards"
                    :key="card.plan.id"
                    :ref="(el) => setRadioRef(el, index)"
                    type="button"
                    role="radio"
                    :aria-checked="selectedCard?.plan.id === card.plan.id"
                    :tabindex="selectedCard?.plan.id === card.plan.id ? 0 : -1"
                    :class="[
                        'group relative flex min-w-0 flex-col rounded-2xl border p-3.5 text-start transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none sm:p-5',
                        selectedCard?.plan.id === card.plan.id
                            ? 'border-primary bg-primary-soft/50 ring-2 ring-primary/20 dark:bg-primary-soft/65'
                            : 'border-border bg-card hover:border-primary/45 hover:bg-surface-hover/50',
                    ]"
                    @click="selectPlan(card.plan.id)"
                    @keydown="handlePlanKeydown($event, index)"
                >
                    <!-- Top Row: Inline Badge (never overflows) + Radio Check Circle -->
                    <div
                        class="flex min-h-6 w-full items-center justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <span
                                v-if="card.isBestValue"
                                class="inline-flex max-w-full items-center truncate rounded-full bg-primary px-2.5 py-0.5 text-[11px] leading-tight font-semibold text-primary-foreground"
                            >
                                {{ t('monetization.premium.bestValue') }}
                            </span>
                            <span
                                v-else-if="card.isPopular"
                                class="inline-flex max-w-full items-center truncate rounded-full bg-primary-soft px-2.5 py-0.5 text-[11px] leading-tight font-semibold text-accent-foreground"
                            >
                                {{ t('monetization.premium.popular') }}
                            </span>
                        </div>

                        <span
                            :class="[
                                'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                selectedCard?.plan.id === card.plan.id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background group-hover:border-primary/60',
                            ]"
                            aria-hidden="true"
                        >
                            <Check
                                v-if="selectedCard?.plan.id === card.plan.id"
                                class="size-3 stroke-[3]"
                            />
                        </span>
                    </div>

                    <div class="w-full flex-1">
                        <!-- Duration Label -->
                        <p class="mt-3 text-base font-bold text-foreground">
                            {{ card.durationLabel }}
                        </p>

                        <!-- Total Price -->
                        <p
                            class="mt-1.5 text-lg font-bold tracking-tight whitespace-nowrap text-foreground tabular-nums sm:text-2xl"
                        >
                            {{
                                formatMoney(
                                    card.effectivePrice,
                                    currency,
                                    locale,
                                )
                            }}
                        </p>

                        <!-- Monthly Average -->
                        <p
                            class="mt-0.5 text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                card.months > 1
                                    ? t('monetization.premium.perMonth', {
                                          price: formatMoney(
                                              card.monthlyAverage,
                                              currency,
                                              locale,
                                          ),
                                      })
                                    : t('monetization.premium.billedOnce')
                            }}
                        </p>
                    </div>

                    <!-- Savings + Ad Experience Differentiator -->
                    <div
                        class="mt-4 flex w-full flex-col items-start gap-2 border-t border-border/60 pt-3.5"
                    >
                        <span
                            v-if="card.savingsPercent > 0"
                            class="inline-flex items-center rounded-md bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success"
                        >
                            {{
                                t('monetization.premium.savePercent', {
                                    percent: card.savingsPercent,
                                })
                            }}
                        </span>

                        <span
                            v-if="card.isAdFree"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary"
                        >
                            <ShieldCheck
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{
                                t('monetization.premium.adFreeTag')
                            }}</span>
                        </span>
                        <span
                            v-else-if="card.hasReducedAds"
                            class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <EyeOff
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{
                                t('monetization.premium.reducedAdsTag')
                            }}</span>
                        </span>
                    </div>
                </button>
            </div>
        </section>

        <!-- Step 2: Unified Benefits (Left) + Checkout Summary (Right) -->
        <div
            v-if="selectedCard"
            class="grid gap-6 lg:grid-cols-12 lg:items-start"
        >
            <!-- Unified Benefits Section -->
            <div class="space-y-4 lg:col-span-7">
                <section
                    aria-labelledby="premium-benefits-heading"
                    class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            id="premium-benefits-heading"
                            class="text-base font-bold text-foreground sm:text-lg"
                        >
                            {{ t('monetization.premium.benefitsHeading') }}
                        </h2>
                        <span
                            class="inline-flex items-center rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-accent-foreground"
                        >
                            {{ selectedCard.durationLabel }}
                        </span>
                    </div>

                    <ul class="mt-4 space-y-2.5">
                        <li
                            v-for="benefit in activeBenefits"
                            :key="benefit.key"
                            :class="[
                                'flex items-start gap-3.5 rounded-xl border p-3.5 transition-colors',
                                benefit.isAdFreeHighlight
                                    ? 'border-primary/30 bg-primary-soft/35'
                                    : 'border-border/70 bg-background/40',
                            ]"
                        >
                            <div
                                :class="[
                                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                    benefit.isBlueBadge
                                        ? 'bg-[#1D9BF0]/12'
                                        : 'bg-primary-soft text-primary',
                                ]"
                            >
                                <PremiumBadge
                                    v-if="benefit.isBlueBadge"
                                    size="md"
                                    :interactive="false"
                                />
                                <component
                                    :is="benefit.icon"
                                    v-else
                                    class="size-4.5"
                                    aria-hidden="true"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ benefit.title }}
                                    </p>
                                    <span
                                        v-if="benefit.isAdFreeHighlight"
                                        class="inline-flex items-center rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-primary-foreground"
                                    >
                                        {{
                                            t(
                                                'monetization.premium.adFreeIncludedBadge',
                                            )
                                        }}
                                    </span>
                                </div>
                                <p
                                    v-if="benefit.description"
                                    class="mt-0.5 text-xs leading-relaxed text-muted-foreground"
                                >
                                    {{ benefit.description }}
                                </p>
                            </div>
                        </li>
                    </ul>

                    <!-- Clear notice when a longer plan includes Ad-Free -->
                    <div
                        v-if="showAdFreeUpgradeHint && adFreePlanCard"
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/25 bg-primary-soft/40 px-4 py-3 text-xs"
                    >
                        <div class="flex items-start gap-2 text-foreground">
                            <ShieldCheck
                                class="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="leading-relaxed">
                                {{
                                    t(
                                        'monetization.premium.adFreeUpgradeHint',
                                        {
                                            duration:
                                                adFreePlanCard.durationLabel,
                                        },
                                    )
                                }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 font-semibold text-primary hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            @click="selectPlan(adFreePlanCard.plan.id)"
                        >
                            {{
                                t('monetization.premium.switchToAdFree', {
                                    duration: adFreePlanCard.durationLabel,
                                })
                            }}
                        </button>
                    </div>
                </section>

                <!-- Trust & Transparency Strip -->
                <section
                    class="grid gap-4 rounded-2xl border border-border bg-card p-5 sm:grid-cols-2"
                >
                    <div class="flex items-start gap-3">
                        <PremiumBadge size="md" :interactive="false" />
                        <div class="space-y-1">
                            <h3 class="text-xs font-semibold text-foreground">
                                {{ t('monetization.badge.title') }}
                            </h3>
                            <p
                                class="text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ t('monetization.badge.tooltip') }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div class="space-y-1">
                            <h3 class="text-xs font-semibold text-foreground">
                                {{
                                    t(
                                        'monetization.premium.moderationRuleTitle',
                                    )
                                }}
                            </h3>
                            <p
                                class="text-xs leading-relaxed text-muted-foreground"
                            >
                                {{
                                    t('monetization.premium.moderationRuleDesc')
                                }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Checkout Summary Card (Sticky on Desktop) -->
            <aside
                aria-labelledby="premium-summary-heading"
                class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6 lg:sticky lg:top-22 lg:col-span-5"
            >
                <h2
                    id="premium-summary-heading"
                    class="text-base font-bold text-foreground sm:text-lg"
                >
                    {{ t('monetization.premium.summaryTitle') }}
                </h2>

                <dl class="mt-4 space-y-2.5 text-xs sm:text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">
                            {{ t('monetization.premium.summarySelectedPlan') }}
                        </dt>
                        <dd class="text-end font-semibold text-foreground">
                            {{ selectedCard.displayTitle }}
                        </dd>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">
                            {{ t('monetization.premium.summaryDuration') }}
                        </dt>
                        <dd class="text-end font-medium text-foreground">
                            {{ selectedCard.durationLabel }} ({{
                                selectedCard.plan.duration_days
                            }}
                            {{ t('monetization.common.days') }})
                        </dd>
                    </div>

                    <div
                        v-if="selectedCard.savingsAmount > 0"
                        class="flex items-center justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('monetization.premium.summaryOriginalPrice') }}
                        </dt>
                        <dd
                            class="text-end text-muted-foreground tabular-nums line-through"
                        >
                            {{
                                formatMoney(
                                    selectedCard.compareAtPrice,
                                    currency,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>

                    <div
                        v-if="selectedCard.savingsAmount > 0"
                        class="flex items-center justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('monetization.premium.summarySavings') }}
                        </dt>
                        <dd
                            class="text-end font-semibold text-success tabular-nums"
                        >
                            -{{
                                formatMoney(
                                    selectedCard.savingsAmount,
                                    currency,
                                    locale,
                                )
                            }}
                            ({{ selectedCard.savingsPercent }}%)
                        </dd>
                    </div>

                    <div
                        class="flex items-baseline justify-between gap-3 border-t border-border pt-3.5"
                    >
                        <dt class="font-semibold text-foreground">
                            {{ t('monetization.premium.summaryTotal') }}
                        </dt>
                        <dd class="text-end">
                            <p
                                class="text-2xl font-bold tracking-tight text-primary tabular-nums"
                            >
                                {{
                                    formatMoney(
                                        selectedCard.effectivePrice,
                                        currency,
                                        locale,
                                    )
                                }}
                            </p>
                            <p
                                v-if="selectedCard.months > 1"
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                {{
                                    t('monetization.premium.perMonth', {
                                        price: formatMoney(
                                            selectedCard.monthlyAverage,
                                            currency,
                                            locale,
                                        ),
                                    })
                                }}
                            </p>
                        </dd>
                    </div>
                </dl>

                <!-- Payment Method Selector (Logged-in users) -->
                <div
                    v-if="
                        user &&
                        eligibleMethods.length > 0 &&
                        platformCapabilities.allow_premium_purchase
                    "
                    class="mt-5 space-y-2 border-t border-border pt-4"
                >
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.premium.paymentMethodLabel') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="method in eligibleMethods"
                            :key="method.code"
                            type="button"
                            :aria-pressed="activeMethod?.code === method.code"
                            :class="[
                                'min-h-9 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                activeMethod?.code === method.code
                                    ? 'border-primary bg-primary-soft text-accent-foreground'
                                    : 'border-border text-muted-foreground hover:text-foreground',
                            ]"
                            @click="selectedProvider = method.code"
                        >
                            {{ method.name }}
                        </button>
                    </div>
                    <p
                        class="text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ t('monetization.premium.noAutoAdBalanceDebit') }}
                    </p>
                </div>

                <!-- Renewal Conditions -->
                <div
                    class="mt-4 rounded-xl bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground"
                >
                    {{ t('monetization.premium.renewalPolicy') }}
                </div>

                <InputError
                    class="mt-3"
                    :message="
                        form.errors.plan_id ||
                        form.errors.provider ||
                        form.errors.currency
                    "
                />

                <!-- Single Primary CTA Button -->
                <Button
                    type="button"
                    class="mt-5 min-h-11 w-full rounded-xl text-sm font-semibold"
                    :disabled="
                        !platformCapabilities.allow_premium_purchase ||
                        form.processing
                    "
                    @click="requestUpgrade"
                >
                    {{
                        !user
                            ? t('monetization.premium.signInToUpgrade')
                            : isPremium
                              ? t('monetization.premium.extendButton')
                              : t('monetization.premium.upgradeCta')
                    }}
                </Button>

                <p
                    class="mt-3 flex items-start justify-center gap-1.5 text-center text-[11px] leading-relaxed text-muted-foreground"
                >
                    <Lock
                        class="mt-0.5 size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span>{{
                        t('monetization.premium.manualConfirmHint')
                    }}</span>
                </p>
            </aside>
        </div>

        <!-- Preserved Manual Yes/No Order Confirmation Modal -->
        <PaymentConfirmModal
            v-if="selectedCard && activeMethod"
            v-model:open="confirmOpen"
            :service-name="t('monetization.services.premiumTitle')"
            :item-name="selectedCard.displayTitle"
            :duration-label="selectedCard.durationLabel"
            :amount="selectedCard.effectivePrice"
            :currency="currency"
            :method-name="activeMethod.name"
            :processing="form.processing"
            @confirm="submitPurchase"
        />

        <!-- Preserved Pending Orders Limit Modal -->
        <PendingLimitModal
            v-model:open="limitOpen"
            :max-pending="maxPendingOrders"
        />
    </div>
</template>
