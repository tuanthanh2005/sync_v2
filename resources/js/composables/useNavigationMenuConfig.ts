import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    HelpCircle,
    Image as ImageIcon,
    Megaphone,
    Palette,
    ReceiptText,
    Settings,
    ShieldCheck,
    Sparkles,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatVnd } from '@/lib/currency';
import {
    advertising as advertisingRoute,
    banner as bannerRoute,
    boost as boostRoute,
    services as servicesRoute,
} from '@/routes/monetization';
import { index as paymentsIndex } from '@/routes/payments';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editAppearance } from '@/routes/appearance';
import { index as walletIndex } from '@/routes/wallet';
import type { FooterLinkItem, NavItem } from '@/types';

export type NavigationGroupId = 'ads' | 'settings' | 'help';

export type SharedMenuNavItem = NavItem & {
    id: string;
    icon: Component;
    badgeText?: string;
    exact?: boolean;
    warningDot?: boolean;
    warningTitle?: string;
};

const HELP_LINK_PRIORITY_SLUGS = [
    'help-center',
    'contact-us',
    'community-guidelines',
    'privacy-policy',
    'terms-of-service',
];

/**
 * Single source of truth for Desktop More Menu and Mobile Sidebar navigation groups,
 * strictly enforcing guest vs authenticated item visibility rules.
 */
export function useNavigationMenuConfig() {
    const { t, locale } = useI18n();
    const page = usePage();

    const user = computed(() => page.props.auth.user);
    const footer = computed(() => page.props.footer);
    const walletBalance = computed(() => page.props.walletBalance ?? 0);
    const lang = computed<'vi' | 'en'>(() =>
        locale.value === 'en' ? 'en' : 'vi',
    );

    const advertisingItems = computed<SharedMenuNavItem[]>(() => {
        const items: SharedMenuNavItem[] = [
            {
                id: 'ad-overview',
                title: t('nav.advertisingOverview'),
                href: advertisingRoute(),
                icon: Sparkles,
                exact: true,
            },
            {
                id: 'ad-boost',
                title: t('monetization.services.boostTitle'),
                href: boostRoute(),
                icon: Zap,
            },
            {
                id: 'ad-banner',
                title: t('monetization.services.bannerTitle'),
                href: bannerRoute(),
                icon: ImageIcon,
            },
        ];

        if (user.value) {
            items.push(
                {
                    id: 'ad-wallet',
                    title: t('monetization.wallet.title'),
                    href: walletIndex(),
                    icon: WalletIcon,
                    badgeText: formatVnd(walletBalance.value, lang.value),
                },
                {
                    id: 'ad-payments',
                    title: t('monetization.payments.title'),
                    href: paymentsIndex(),
                    icon: ReceiptText,
                },
            );
        }

        return items;
    });

    const authSettingsItems = computed<SharedMenuNavItem[]>(() => {
        if (!user.value) {
            return [];
        }

        return [
            {
                id: 'settings-profile',
                title: t('nav.profile'),
                href: editProfile(),
                icon: Settings,
            },
            {
                id: 'settings-security',
                title: t('nav.security'),
                href: editSecurity(),
                icon: ShieldCheck,
                warningDot: !user.value.two_factor_enabled,
                warningTitle: t('sidebar.enable2fa'),
            },
            {
                id: 'settings-appearance',
                title: t('nav.appearance'),
                href: editAppearance(),
                icon: Palette,
            },
        ];
    });

    const helpLinks = computed<FooterLinkItem[]>(() => {
        const allLinks = footer.value?.links ?? [];
        if (allLinks.length === 0) {
            return [];
        }

        const sorted = [...allLinks].sort((a, b) => {
            const aIdx = HELP_LINK_PRIORITY_SLUGS.findIndex((slug) =>
                a.href.includes(slug),
            );
            const bIdx = HELP_LINK_PRIORITY_SLUGS.findIndex((slug) =>
                b.href.includes(slug),
            );
            const aScore = aIdx === -1 ? 99 : aIdx;
            const bScore = bIdx === -1 ? 99 : bIdx;

            return aScore - bScore;
        });

        return sorted.slice(0, 5);
    });

    const hasContactLink = computed<boolean>(() =>
        helpLinks.value.some((item) => item.href.includes('contact')),
    );

    const supportEmail = computed<string | null>(
        () => footer.value?.supportEmail ?? null,
    );

    const servicesNavItem = computed<{
        id: string;
        title: string;
        href: NonNullable<InertiaLinkProps['href']>;
        icon: Component;
    }>(() => ({
        id: 'help-services',
        title: t('monetization.services.pageTitle'),
        href: servicesRoute(),
        icon: Sparkles,
    }));

    function isExternalHelpLink(item: FooterLinkItem): boolean {
        return (
            item.open_in_new_tab ||
            item.href.startsWith('http://') ||
            item.href.startsWith('https://')
        );
    }

    function resolveDefaultGroup(): NavigationGroupId {
        const component = page.component;
        if (component.startsWith('settings/')) {
            return 'settings';
        }
        if (
            component === 'Ads' ||
            component === 'Wallet' ||
            component === 'monetization/Advertising' ||
            component === 'monetization/BoostPost' ||
            component === 'monetization/BannerAds' ||
            component === 'monetization/Payments'
        ) {
            return 'ads';
        }
        if (
            component.startsWith('policies/') ||
            component === 'monetization/Services'
        ) {
            return 'help';
        }

        return user.value ? 'ads' : 'help';
    }

    const groupIcons: Record<NavigationGroupId, Component> = {
        ads: Megaphone,
        settings: Settings,
        help: HelpCircle,
    };

    return {
        advertisingItems,
        authSettingsItems,
        helpLinks,
        hasContactLink,
        supportEmail,
        servicesNavItem,
        isExternalHelpLink,
        resolveDefaultGroup,
        groupIcons,
    };
}
