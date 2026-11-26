<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Crown,
    HelpCircle,
    Image as ImageIcon,
    ShieldCheck,
    Sparkles,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { defaultCurrencyForLocale, formatMoney } from '@/lib/currency';
import {
    advertising as advertisingRoute,
    banner as bannerRoute,
    boost as boostRoute,
    premium as premiumRoute,
} from '@/routes/monetization';
import type {
    BannerPlacementItem,
    BoostPackageItem,
    PlatformCapabilities,
    PremiumPlanItem,
} from '@/types';

const props = defineProps<{
    plans: PremiumPlanItem[];
    placements: BannerPlacementItem[];
    boostPackages: BoostPackageItem[];
    platformCapabilities: PlatformCapabilities;
    exchangeRate: number;
}>();

const { t, locale } = useI18n();
const currency = ref<'VND' | 'USD'>(defaultCurrencyForLocale(locale.value));

const minPremiumPrice = computed(() => {
    if (props.plans.length === 0) {
        return 0;
    }

    return Math.min(
        ...props.plans.map((p) =>
            currency.value === 'USD' ? p.effective_usd_cents : p.effective_vnd,
        ),
    );
});

const minBannerPrice = computed(() => {
    if (props.placements.length === 0) {
        return 0;
    }

    return Math.min(
        ...props.placements.map((p) =>
            currency.value === 'USD' ? p.price_7d_usd_cents : p.price_7d_vnd,
        ),
    );
});

const minBoostPrice = computed(() => {
    if (props.boostPackages.length === 0) {
        return 0;
    }

    return Math.min(
        ...props.boostPackages.map((p) =>
            currency.value === 'USD' ? p.price_usd_cents : p.price_vnd,
        ),
    );
});
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.services.pageTitle')" />

        <!-- Hero -->
        <section
            class="rounded-2xl border border-border bg-card p-6 shadow-xs sm:p-8"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl space-y-2">
                    <Badge variant="soft" class="gap-1.5 text-xs">
                        <Sparkles class="size-3.5" />
                        <span>{{ t('monetization.services.eyebrow') }}</span>
                    </Badge>
                    <h1
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ t('monetization.services.heading') }}
                    </h1>
                    <p
                        class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                    >
                        {{ t('monetization.services.subtitle') }}
                    </p>
                </div>

                <!-- Currency Switcher -->
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
                        {{ t('monetization.checkout.currencyVnd') }}
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
                        {{ t('monetization.checkout.currencyUsd') }}
                    </button>
                </div>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-border/80 bg-muted/25 px-4 py-2.5 text-xs text-muted-foreground"
            >
                <ShieldCheck class="size-4 shrink-0 text-primary" />
                <span>{{ t('monetization.services.freeCoreGuarantee') }}</span>
            </div>
        </section>

        <!-- Three Core Monetization Pillars -->
        <section class="grid gap-4 md:grid-cols-3">
            <!-- 1. SyncViet Premium -->
            <article
                class="flex flex-col justify-between rounded-2xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div
                            class="flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                        >
                            <Crown class="size-5" />
                        </div>
                        <PremiumBadge size="md" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-foreground">
                            {{ t('monetization.services.premiumTitle') }}
                        </h2>
                        <p
                            class="mt-1 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('monetization.services.premiumDesc') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('monetization.services.startingFrom') }}
                        </p>
                        <p class="text-xl font-bold text-primary">
                            {{ formatMoney(minPremiumPrice, currency, locale) }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                / {{ t('monetization.common.monthShort') }}
                            </span>
                        </p>
                    </div>
                    <ul class="space-y-1.5 text-xs text-muted-foreground">
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.benefits.blue_badge')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.benefits.reduced_ads')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.benefits.post_analytics')
                            }}</span>
                        </li>
                    </ul>
                </div>

                <div class="mt-5 flex flex-col gap-2">
                    <Button class="min-h-10 w-full rounded-xl" as-child>
                        <Link :href="premiumRoute()">
                            <span>{{
                                t('monetization.services.viewPremium')
                            }}</span>
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </article>

            <!-- 2. SyncViet Banner Ads -->
            <article
                class="flex flex-col justify-between rounded-2xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div
                            class="flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                        >
                            <ImageIcon class="size-5" />
                        </div>
                        <Badge variant="secondary" class="text-[10px]">
                            {{ placements.length }}
                            {{ t('monetization.banner.placementsUnit') }}
                        </Badge>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-foreground">
                            {{ t('monetization.services.bannerTitle') }}
                        </h2>
                        <p
                            class="mt-1 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('monetization.services.bannerDesc') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('monetization.services.startingFrom') }}
                        </p>
                        <p class="text-xl font-bold text-primary">
                            {{ formatMoney(minBannerPrice, currency, locale) }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                / 7 {{ t('monetization.common.days') }}
                            </span>
                        </p>
                    </div>
                    <ul class="space-y-1.5 text-xs text-muted-foreground">
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.banner.bulletExclusive')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.banner.bulletResponsive')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.banner.bulletStats')
                            }}</span>
                        </li>
                    </ul>
                </div>

                <div class="mt-5 flex flex-col gap-2">
                    <Button
                        variant="outline"
                        class="min-h-10 w-full rounded-xl"
                        as-child
                    >
                        <Link :href="bannerRoute()">
                            <span>{{
                                t('monetization.services.viewBanners')
                            }}</span>
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </article>

            <!-- 3. SyncViet Boost Post -->
            <article
                class="flex flex-col justify-between rounded-2xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div
                            class="flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                        >
                            <Zap class="size-5" />
                        </div>
                        <Badge variant="secondary" class="text-[10px]">
                            {{ boostPackages.length }}
                            {{ t('monetization.boost.packagesUnit') }}
                        </Badge>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-foreground">
                            {{ t('monetization.services.boostTitle') }}
                        </h2>
                        <p
                            class="mt-1 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('monetization.services.boostDesc') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/35 p-3">
                        <p class="text-[11px] text-muted-foreground">
                            {{ t('monetization.services.startingFrom') }}
                        </p>
                        <p class="text-xl font-bold text-primary">
                            {{ formatMoney(minBoostPrice, currency, locale) }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                / {{ t('monetization.checkout.hours24') }}
                            </span>
                        </p>
                    </div>
                    <ul class="space-y-1.5 text-xs text-muted-foreground">
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.boost.bulletFixed')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.boost.bulletTransparent')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.boost.bulletSafeRefund')
                            }}</span>
                        </li>
                    </ul>
                </div>

                <div class="mt-5 flex flex-col gap-2">
                    <Button
                        variant="outline"
                        class="min-h-10 w-full rounded-xl"
                        as-child
                    >
                        <Link :href="boostRoute()">
                            <span>{{
                                t('monetization.services.viewBoost')
                            }}</span>
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </article>
        </section>

        <!-- FAQ Section -->
        <section
            class="space-y-4 rounded-2xl border border-border bg-card p-6 shadow-xs"
        >
            <div class="flex items-center justify-between gap-2">
                <h2
                    class="flex items-center gap-2 text-base font-bold text-foreground"
                >
                    <HelpCircle class="size-4.5 text-primary" />
                    <span>{{ t('monetization.faq.title') }}</span>
                </h2>
                <Link
                    :href="advertisingRoute()"
                    class="text-xs font-medium text-primary hover:underline"
                >
                    {{ t('monetization.services.advertisingHubLink') }} →
                </Link>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-border/80 p-4">
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.faq.q1') }}
                    </p>
                    <p
                        class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t('monetization.faq.a1') }}
                    </p>
                </div>
                <div class="rounded-xl border border-border/80 p-4">
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.faq.q2') }}
                    </p>
                    <p
                        class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t('monetization.faq.a2') }}
                    </p>
                </div>
                <div class="rounded-xl border border-border/80 p-4">
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.faq.q3') }}
                    </p>
                    <p
                        class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t('monetization.faq.a3') }}
                    </p>
                </div>
                <div class="rounded-xl border border-border/80 p-4">
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.faq.q4') }}
                    </p>
                    <p
                        class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t('monetization.faq.a4') }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
