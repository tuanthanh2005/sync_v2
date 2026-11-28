<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Image as ImageIcon,
    Megaphone,
    ReceiptText,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { formatVnd } from '@/lib/currency';
import { toUrl } from '@/lib/utils';
import {
    advertising as advertisingRoute,
    banner as bannerRoute,
    boost as boostRoute,
} from '@/routes/monetization';
import { index as paymentsIndex } from '@/routes/payments';
import { index as walletIndex } from '@/routes/wallet';
import type { NavItem } from '@/types';

type AdTabItem = {
    id: string;
    title: string;
    href: NavItem['href'];
    icon: Component;
    exact?: boolean;
};

const { t, locale } = useI18n();
const page = usePage();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const user = computed(() => page.props.auth.user);
const walletBalance = computed(() => page.props.walletBalance ?? 0);
const lang = computed<'vi' | 'en'>(() => (locale.value === 'en' ? 'en' : 'vi'));

const tabs = computed<AdTabItem[]>(() => {
    const items: AdTabItem[] = [
        {
            id: 'overview',
            title: t('nav.advertisingOverview'),
            href: advertisingRoute(),
            icon: Megaphone,
            exact: true,
        },
        {
            id: 'boost',
            title: t('monetization.services.boostTitle'),
            href: boostRoute(),
            icon: Zap,
        },
        {
            id: 'banner',
            title: t('monetization.services.bannerTitle'),
            href: bannerRoute(),
            icon: ImageIcon,
        },
    ];

    if (user.value) {
        items.push(
            {
                id: 'wallet',
                title: t('monetization.wallet.title'),
                href: walletIndex(),
                icon: WalletIcon,
            },
            {
                id: 'payments',
                title: t('monetization.payments.title'),
                href: paymentsIndex(),
                icon: ReceiptText,
            },
        );
    }

    return items;
});

function isTabActive(tab: AdTabItem): boolean {
    if (tab.exact) {
        return isCurrentUrl(tab.href);
    }
    if (tab.id === 'boost' && page.component === 'Ads') {
        return true;
    }

    return isCurrentOrParentUrl(tab.href);
}
</script>

<template>
    <div
        class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card p-2 sm:px-3"
        data-test="advertising-center-nav"
    >
        <nav
            :aria-label="t('nav.advertisingCenter')"
            class="no-scrollbar flex min-w-0 flex-1 items-center gap-1 overflow-x-auto"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.id"
                :href="toUrl(tab.href)"
                :class="[
                    'inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-2.5 text-[13px] font-medium whitespace-nowrap transition-colors',
                    isTabActive(tab)
                        ? 'bg-primary-soft font-semibold text-accent-foreground'
                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                ]"
            >
                <component
                    :is="tab.icon"
                    :class="[
                        'size-4 shrink-0',
                        isTabActive(tab) ? 'text-primary' : '',
                    ]"
                    aria-hidden="true"
                />
                <span>{{ tab.title }}</span>
            </Link>
        </nav>

        <Link
            v-if="user"
            :href="walletIndex()"
            :title="t('monetization.wallet.title')"
            :aria-label="`${t('monetization.wallet.title')}: ${formatVnd(walletBalance, lang)}`"
            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl border border-border bg-muted/50 px-2.5 text-xs font-semibold text-foreground transition-colors hover:bg-surface-hover"
        >
            <WalletIcon class="size-3.5 text-primary" aria-hidden="true" />
            <span class="tabular-nums">{{
                formatVnd(walletBalance, lang)
            }}</span>
        </Link>
    </div>
</template>
