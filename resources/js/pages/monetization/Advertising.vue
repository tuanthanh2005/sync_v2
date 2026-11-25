<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Image as ImageIcon,
    Megaphone,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as adsIndex } from '@/routes/ads';
import {
    banner as bannerRoute,
    boost as boostRoute,
} from '@/routes/monetization';
import { index as walletIndex } from '@/routes/wallet';
import type {
    BannerPlacementItem,
    BoostPackageItem,
    PlatformCapabilities,
} from '@/types';

defineProps<{
    placements: BannerPlacementItem[];
    boostPackages: BoostPackageItem[];
    platformCapabilities: PlatformCapabilities;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.advertising.pageTitle')" />

        <section
            class="rounded-2xl border border-border bg-card p-6 shadow-xs sm:p-8"
        >
            <Badge variant="soft" class="gap-1.5 text-xs">
                <Megaphone class="size-3.5" />
                <span>{{ t('monetization.advertising.eyebrow') }}</span>
            </Badge>
            <h1
                class="mt-2.5 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
            >
                {{ t('monetization.advertising.heading') }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                {{ t('monetization.advertising.subtitle') }}
            </p>
            <div class="mt-5 flex flex-wrap gap-2.5">
                <Button class="rounded-xl" as-child>
                    <Link :href="bannerRoute()">
                        <ImageIcon class="size-4" />
                        <span>{{
                            t('monetization.services.bannerTitle')
                        }}</span>
                    </Link>
                </Button>
                <Button variant="outline" class="rounded-xl" as-child>
                    <Link :href="boostRoute()">
                        <Zap class="size-4" />
                        <span>{{ t('monetization.services.boostTitle') }}</span>
                    </Link>
                </Button>
                <Button variant="ghost" class="rounded-xl" as-child>
                    <Link :href="walletIndex()">
                        <WalletIcon class="size-4" />
                        <span>{{ t('monetization.wallet.title') }}</span>
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2">
            <div class="space-y-3 rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-foreground">
                        {{ t('monetization.services.bannerTitle') }}
                    </h2>
                    <Badge variant="secondary" class="text-[11px]">
                        {{ placements.length }}
                        {{ t('monetization.banner.placementsUnit') }}
                    </Badge>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ t('monetization.services.bannerDesc') }}
                </p>
                <Button variant="outline" size="sm" class="rounded-lg" as-child>
                    <Link :href="bannerRoute()">
                        <span>{{
                            t('monetization.services.viewBanners')
                        }}</span>
                        <ArrowRight class="size-3.5" />
                    </Link>
                </Button>
            </div>

            <div class="space-y-3 rounded-2xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-foreground">
                        {{ t('monetization.services.boostTitle') }}
                    </h2>
                    <Badge variant="secondary" class="text-[11px]">
                        {{ boostPackages.length }}
                        {{ t('monetization.boost.packagesUnit') }}
                    </Badge>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ t('monetization.services.boostDesc') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-lg"
                        as-child
                    >
                        <Link :href="boostRoute()">
                            <span>{{
                                t('monetization.services.viewBoost')
                            }}</span>
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="rounded-lg"
                        as-child
                    >
                        <Link :href="adsIndex()">
                            <span>{{ t('nav.ads') }}</span>
                        </Link>
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
