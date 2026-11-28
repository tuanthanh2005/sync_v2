<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Bell,
    ChevronDown,
    Compass,
    Crown,
    Download,
    ExternalLink,
    Flag,
    Gift,
    Globe,
    HelpCircle,
    Home,
    LayoutGrid,
    LogIn,
    Mail,
    Menu,
    MessageSquare,
    Palette,
    PenSquare,
    Search,
    Settings,
    Shield,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Trophy,
    User as UserIcon,
    UserPlus,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import SearchController from '@/actions/App/Http/Controllers/Social/SearchController';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import MobileBottomNav from '@/components/common/MobileBottomNav.vue';
import NetworkStatusBanner from '@/components/common/NetworkStatusBanner.vue';
import SidebarMoreMenu from '@/components/common/SidebarMoreMenu.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';
import MiniFooter from '@/components/footer/MiniFooter.vue';
import AdvertisingCenterNav from '@/components/monetization/AdvertisingCenterNav.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PostComposerModal from '@/components/social/PostComposerModal.vue';
import UserBadges from '@/components/social/UserBadges.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { useMobileComposer } from '@/composables/useMobileComposer';
import { useMobileNavigation } from '@/composables/useMobileNavigation';
import { useCompactScrollHeader } from '@/composables/useMobileViewport';
import {
    useNavigationMenuConfig,
    type NavigationGroupId,
} from '@/composables/useNavigationMenuConfig';
import { usePwa } from '@/composables/usePwa';
import { toUrl } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import { premium as accountPremiumRoute } from '@/routes/account';
import { overview as adminOverview } from '@/routes/admin';
import { edit as editAppearance } from '@/routes/appearance';
import { index as communitiesIndex } from '@/routes/communities';
import { index as messagesIndex } from '@/routes/messages';
import { premium as premiumRoute } from '@/routes/monetization';
import { index as notificationsIndex } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { index as referralIndex } from '@/routes/referral';
import { edit as editSecurity } from '@/routes/security';
import type {
    BreadcrumbItem,
    CommunityDetail,
    CommunityMemberItem,
    HashtagItem,
    NavItem,
    SidebarNotableAccount,
} from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    hideRightSidebar?: boolean;
};

type SidebarNavItem = NavItem & {
    id: string;
    icon: Component;
    badgeText?: string;
    exact?: boolean;
};

type MobileDrawerSection = {
    id: string;
    label: string;
    items: SidebarNavItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    hideRightSidebar: false,
});

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const footer = computed(() => page.props.footer);
const brandName = computed(() => page.props.name);
const isRtl = computed(() => Boolean(page.props.isRtl));
const sidebarHighlights = computed(() => page.props.sidebarHighlights);
const notableAccounts = computed<SidebarNotableAccount[]>(
    () => sidebarHighlights.value?.notableAccounts ?? [],
);
const unreadNotificationsCount = computed(
    () => page.props.unreadNotificationsCount ?? 0,
);
const unreadMessagesCount = computed(() => page.props.unreadMessagesCount ?? 0);

const isCommunityPage = computed(() => page.component === 'communities/Show');
const isAdvertisingCenterPage = computed(
    () =>
        page.component === 'monetization/Advertising' ||
        page.component === 'monetization/BoostPost' ||
        page.component === 'monetization/BannerAds' ||
        page.component === 'monetization/Payments' ||
        page.component === 'Wallet' ||
        page.component === 'Ads',
);
const isWideServiceLayout = computed(
    () =>
        props.hideRightSidebar ||
        page.component.startsWith('monetization/') ||
        page.component.startsWith('growth/') ||
        page.component === 'Wallet' ||
        page.component === 'Ads',
);
const isMoreMenuRouteActive = computed(
    () =>
        isAdvertisingCenterPage.value ||
        page.component.startsWith('settings/') ||
        page.component === 'monetization/Services',
);

const activeCommunity = computed(
    () => (page.props.community as CommunityDetail | undefined) ?? undefined,
);
const communityLeaderboard = computed(
    () => (page.props.leaderboard as CommunityMemberItem[] | undefined) ?? [],
);
const communityHashtags = computed(
    () => (page.props.trending_hashtags as HashtagItem[] | undefined) ?? [],
);

const hasGlobalRightSidebarData = computed(
    () =>
        sidebarHighlights.value.trendingHashtags.length > 0 ||
        sidebarHighlights.value.featuredCommunities.length > 0 ||
        notableAccounts.value.length > 0 ||
        Boolean(sidebarHighlights.value.banner),
);

const mobileMenuOpen = ref(false);
const mobileExpandedSection = ref<string | null>(null);
const headerSearch = ref('');
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { getInitials } = useInitials();
const { headerHidden } = useCompactScrollHeader();
const { canInstallApp, promptInstallApp } = usePwa();
const { openMobileComposer } = useMobileComposer();
const { isRootMobileRoute, goBack: goMobileBack } = useMobileNavigation();
const {
    advertisingItems: sharedAdvertisingItems,
    helpLinks: guestHelpLinks,
    hasContactLink: guestHasContactLink,
    supportEmail: guestSupportEmail,
    servicesNavItem: guestServicesNavItem,
    isExternalHelpLink,
    resolveDefaultGroup: resolveDefaultGuestGroup,
    groupIcons: sharedGroupIcons,
} = useNavigationMenuConfig();

const mobileGuestExpandedSection = ref<NavigationGroupId | null>(null);

watch(mobileMenuOpen, (isOpen) => {
    if (isOpen && !user.value) {
        mobileGuestExpandedSection.value = resolveDefaultGuestGroup();
    }
});

function toggleGuestMobileSection(sectionId: NavigationGroupId): void {
    mobileGuestExpandedSection.value =
        mobileGuestExpandedSection.value === sectionId ? null : sectionId;
}

function handleCreatePostClick(): void {
    if (!user.value) {
        router.visit(login());

        return;
    }

    if (page.component === 'Welcome' || page.component === 'communities/Show') {
        openMobileComposer();

        return;
    }

    router.visit(home(), {
        onFinish: () => {
            openMobileComposer();
        },
    });
}

function handleHeaderSearchInput(): void {
    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }

    const q = headerSearch.value.trim();

    if (q.length < 2) {
        return;
    }

    searchDebounceTimer = setTimeout(() => {
        router.get(
            SearchController.url({ query: { q } }),
            {},
            { preserveState: true, replace: true },
        );
    }, 350);
}

function submitHeaderSearch(): void {
    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }

    router.get(
        SearchController.url({ query: { q: headerSearch.value.trim() } }),
    );
}

function toggleFeaturedCommunityJoin(slug: string): void {
    if (!user.value) {
        router.visit(login());

        return;
    }

    router.post(
        CommunityController.toggleMembership.url(slug),
        {},
        { preserveScroll: true },
    );
}

function toggleMobileSection(sectionId: string): void {
    mobileExpandedSection.value =
        mobileExpandedSection.value === sectionId ? null : sectionId;
}

onBeforeUnmount(() => {
    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }
});

/**
 * Primary social navigation items (strictly concise: 5 for members, 3 for guests).
 */
const primaryNavItems = computed<SidebarNavItem[]>(() => {
    const base: SidebarNavItem[] = [
        {
            id: 'home',
            title: t('nav.home'),
            href: home(),
            icon: Home,
            exact: true,
        },
        {
            id: 'explore',
            title: t('nav.explore'),
            href: SearchController.url(),
            icon: Compass,
        },
        {
            id: 'communities',
            title: t('nav.communities'),
            href: communitiesIndex(),
            icon: Users,
        },
    ];

    if (user.value) {
        base.push(
            {
                id: 'profile',
                title: t('nav.publicProfile'),
                href: ProfileViewController.me.url(),
                icon: UserIcon,
            },
            {
                id: 'workspace',
                title: t('nav.dashboard'),
                href: dashboard(),
                icon: LayoutGrid,
            },
        );
    }

    return base;
});

/**
 * Secondary items below subtle divider (Premium + Referral; More Menu sits immediately after).
 */
const secondaryNavItems = computed<SidebarNavItem[]>(() => {
    const items: SidebarNavItem[] = [
        {
            id: 'premium',
            title: t('nav.brandPremium', { brand: brandName.value }),
            href: user.value?.is_premium
                ? accountPremiumRoute()
                : premiumRoute(),
            icon: Crown,
        },
    ];

    if (user.value && !user.value.is_ai_agent) {
        items.push({
            id: 'referral',
            title: t('nav.referral'),
            href: referralIndex(),
            icon: Gift,
        });
    }

    return items;
});

/**
 * Mobile Drawer quick social shortcuts (complements the 5-tab MobileBottomNav).
 */
const mobileQuickItems = computed<SidebarNavItem[]>(() => {
    const items: SidebarNavItem[] = [];

    if (user.value) {
        items.push({
            id: 'workspace',
            title: t('nav.dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        });
    }

    items.push({
        id: 'premium',
        title: t('nav.brandPremium', { brand: brandName.value }),
        href: user.value?.is_premium ? accountPremiumRoute() : premiumRoute(),
        icon: Crown,
    });

    if (user.value && !user.value.is_ai_agent) {
        items.push({
            id: 'referral',
            title: t('nav.referral'),
            href: referralIndex(),
            icon: Gift,
        });
    }

    return items;
});

/**
 * Mobile Drawer collapsible feature sections (Advertising Center, Settings & Privacy).
 */
const mobileCollapsibleSections = computed<MobileDrawerSection[]>(() => {
    const sections: MobileDrawerSection[] = [
        {
            id: 'ads',
            label: t('nav.advertisingCenter'),
            items: sharedAdvertisingItems.value,
        },
    ];

    if (user.value) {
        sections.push({
            id: 'settings',
            label: t('nav.settingsPrivacy'),
            items: [
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
                },
                {
                    id: 'settings-appearance',
                    title: t('nav.appearanceLanguage'),
                    href: editAppearance(),
                    icon: Palette,
                },
            ],
        });
    }

    return sections;
});

function isItemActive(item: SidebarNavItem): boolean {
    const targetUrl = toUrl(item.href);

    if (
        item.exact ||
        targetUrl === toUrl(home()) ||
        targetUrl === toUrl(adminOverview())
    ) {
        return isCurrentUrl(targetUrl);
    }

    if (user.value && targetUrl === ProfileViewController.me.url()) {
        return (
            isCurrentOrParentUrl(targetUrl) ||
            isCurrentUrl(ProfileViewController.show.url(user.value.username))
        );
    }

    return isCurrentOrParentUrl(targetUrl);
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground"
        >
            {{ t('nav.skipToContent') }}
        </a>

        <!-- Top Sticky Header (56px mobile / 64px desktop + Safe Area) -->
        <header
            :class="[
                'safe-pt sticky top-0 z-40 border-b border-border bg-card/98 transition-transform duration-150',
                headerHidden && !mobileMenuOpen
                    ? '-translate-y-full md:translate-y-0'
                    : 'translate-y-0',
            ]"
        >
            <div
                class="mx-auto flex h-14 max-w-[1360px] items-center justify-between gap-2 px-3 sm:h-16 sm:gap-4 sm:px-4 lg:px-6"
            >
                <div class="flex min-w-0 items-center gap-1.5 sm:gap-3">
                    <Button
                        v-if="!isRootMobileRoute"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 rounded-xl md:hidden"
                        :aria-label="t('nav.back')"
                        data-test="mobile-header-back"
                        @click="goMobileBack"
                    >
                        <ArrowLeft class="size-5 rtl:rotate-180" />
                    </Button>
                    <Sheet v-model:open="mobileMenuOpen">
                        <SheetTrigger v-if="isRootMobileRoute" as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 rounded-xl md:hidden"
                                :aria-label="t('nav.openMobileMenu')"
                                data-test="mobile-drawer-trigger"
                            >
                                <Menu class="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            class="flex w-[85vw] max-w-72 flex-col justify-between p-0"
                        >
                            <!-- Synchronized Guest Mobile Sidebar (Fixed Header -> Scrollable Menu -> Footer) -->
                            <template v-if="!user">
                                <SheetHeader
                                    class="shrink-0 border-b border-border px-5 py-4 pe-12 text-start"
                                >
                                    <SheetTitle>
                                        <AppLogo />
                                    </SheetTitle>
                                    <SheetDescription class="sr-only">
                                        {{ brandName }}
                                    </SheetDescription>
                                </SheetHeader>

                                <div
                                    class="sv-scrollbar min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain"
                                >
                                    <nav
                                        :aria-label="t('nav.mobileNavLabel')"
                                        data-test="mobile-guest-sidebar-nav"
                                        class="flex flex-col gap-1.5 p-3"
                                    >
                                        <!-- Main Item: SysNoxis Premium -->
                                        <Link
                                            :href="premiumRoute()"
                                            data-test="mobile-guest-premium"
                                            @click="mobileMenuOpen = false"
                                            :class="[
                                                'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                                isCurrentOrParentUrl(
                                                    toUrl(premiumRoute()),
                                                )
                                                    ? 'bg-primary-soft font-semibold text-accent-foreground'
                                                    : 'text-foreground/85 hover:bg-surface-hover hover:text-foreground',
                                            ]"
                                        >
                                            <Crown
                                                :class="[
                                                    'size-5 shrink-0',
                                                    isCurrentOrParentUrl(
                                                        toUrl(premiumRoute()),
                                                    )
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                ]"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="min-w-0 flex-1 text-start leading-snug break-words"
                                            >
                                                {{
                                                    t('nav.brandPremium', {
                                                        brand: brandName,
                                                    })
                                                }}
                                            </span>
                                        </Link>

                                        <!-- Accordion 1: Advertising Center (Shared Navigation Config) -->
                                        <div class="space-y-1">
                                            <button
                                                type="button"
                                                data-test="mobile-guest-group-ads"
                                                :aria-expanded="
                                                    mobileGuestExpandedSection ===
                                                    'ads'
                                                "
                                                class="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                @click="
                                                    toggleGuestMobileSection(
                                                        'ads',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="flex min-w-0 flex-1 items-center gap-3"
                                                >
                                                    <component
                                                        :is="
                                                            sharedGroupIcons.ads
                                                        "
                                                        class="size-5 shrink-0 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            t(
                                                                'nav.advertisingCenter',
                                                            )
                                                        }}
                                                    </span>
                                                </span>
                                                <ChevronDown
                                                    :class="[
                                                        'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                                        mobileGuestExpandedSection ===
                                                        'ads'
                                                            ? 'rotate-180'
                                                            : '',
                                                    ]"
                                                    aria-hidden="true"
                                                />
                                            </button>

                                            <div
                                                v-show="
                                                    mobileGuestExpandedSection ===
                                                    'ads'
                                                "
                                                class="space-y-0.5 ps-3 pe-1 pb-1"
                                            >
                                                <Link
                                                    v-for="item in sharedAdvertisingItems"
                                                    :key="item.id"
                                                    :href="item.href"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                    :class="[
                                                        'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                                        isItemActive(item)
                                                            ? 'bg-primary-soft text-accent-foreground'
                                                            : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                                    ]"
                                                >
                                                    <component
                                                        :is="item.icon"
                                                        :class="[
                                                            'size-4.5 shrink-0',
                                                            isItemActive(item)
                                                                ? 'text-primary'
                                                                : '',
                                                        ]"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{ item.title }}
                                                    </span>
                                                </Link>
                                            </div>
                                        </div>

                                        <!-- Accordion 2: Settings & Privacy (Public Guest Controls) -->
                                        <div class="space-y-1">
                                            <button
                                                type="button"
                                                data-test="mobile-guest-group-settings"
                                                :aria-expanded="
                                                    mobileGuestExpandedSection ===
                                                    'settings'
                                                "
                                                class="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                @click="
                                                    toggleGuestMobileSection(
                                                        'settings',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="flex min-w-0 flex-1 items-center gap-3"
                                                >
                                                    <component
                                                        :is="
                                                            sharedGroupIcons.settings
                                                        "
                                                        class="size-5 shrink-0 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            t(
                                                                'nav.settingsPrivacy',
                                                            )
                                                        }}
                                                    </span>
                                                </span>
                                                <ChevronDown
                                                    :class="[
                                                        'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                                        mobileGuestExpandedSection ===
                                                        'settings'
                                                            ? 'rotate-180'
                                                            : '',
                                                    ]"
                                                    aria-hidden="true"
                                                />
                                            </button>

                                            <div
                                                v-show="
                                                    mobileGuestExpandedSection ===
                                                    'settings'
                                                "
                                                class="space-y-1 ps-3 pe-1 pb-1"
                                            >
                                                <div
                                                    class="flex min-h-10 items-center justify-between gap-2 rounded-xl px-3 py-1.5 text-sm text-muted-foreground"
                                                >
                                                    <span
                                                        class="flex min-w-0 flex-1 items-center gap-3"
                                                    >
                                                        <Palette
                                                            class="size-4.5 shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                        <span
                                                            class="min-w-0 flex-1 leading-snug break-words"
                                                        >
                                                            {{
                                                                t(
                                                                    'nav.appearance',
                                                                )
                                                            }}
                                                        </span>
                                                    </span>
                                                    <ThemeToggle />
                                                </div>

                                                <div
                                                    class="flex min-h-10 items-center justify-between gap-2 rounded-xl px-3 py-1.5 text-sm text-muted-foreground"
                                                    @click.stop
                                                >
                                                    <span
                                                        class="flex min-w-0 flex-1 items-center gap-3"
                                                    >
                                                        <Globe
                                                            class="size-4.5 shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                        <span
                                                            class="min-w-0 flex-1 leading-snug break-words"
                                                        >
                                                            {{
                                                                t(
                                                                    'nav.language',
                                                                )
                                                            }}
                                                        </span>
                                                    </span>
                                                    <LocaleSwitcher compact />
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Accordion 3: Help & Support (Shared Navigation Config) -->
                                        <div class="space-y-1">
                                            <button
                                                type="button"
                                                data-test="mobile-guest-group-help"
                                                :aria-expanded="
                                                    mobileGuestExpandedSection ===
                                                    'help'
                                                "
                                                class="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                @click="
                                                    toggleGuestMobileSection(
                                                        'help',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="flex min-w-0 flex-1 items-center gap-3"
                                                >
                                                    <component
                                                        :is="
                                                            sharedGroupIcons.help
                                                        "
                                                        class="size-5 shrink-0 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            t('nav.helpSupport')
                                                        }}
                                                    </span>
                                                </span>
                                                <ChevronDown
                                                    :class="[
                                                        'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                                        mobileGuestExpandedSection ===
                                                        'help'
                                                            ? 'rotate-180'
                                                            : '',
                                                    ]"
                                                    aria-hidden="true"
                                                />
                                            </button>

                                            <div
                                                v-show="
                                                    mobileGuestExpandedSection ===
                                                    'help'
                                                "
                                                class="space-y-0.5 ps-3 pe-1 pb-1"
                                            >
                                                <template
                                                    v-for="item in guestHelpLinks"
                                                    :key="item.id"
                                                >
                                                    <a
                                                        v-if="
                                                            isExternalHelpLink(
                                                                item,
                                                            )
                                                        "
                                                        :href="item.href"
                                                        :target="
                                                            item.open_in_new_tab
                                                                ? '_blank'
                                                                : undefined
                                                        "
                                                        :rel="
                                                            item.open_in_new_tab
                                                                ? 'noopener noreferrer'
                                                                : undefined
                                                        "
                                                        :class="[
                                                            'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                                            !item.open_in_new_tab &&
                                                            isCurrentUrl(
                                                                item.href,
                                                            )
                                                                ? 'bg-primary-soft text-accent-foreground'
                                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                                        ]"
                                                        @click="
                                                            mobileMenuOpen = false
                                                        "
                                                    >
                                                        <HelpCircle
                                                            :class="[
                                                                'size-4.5 shrink-0',
                                                                !item.open_in_new_tab &&
                                                                isCurrentUrl(
                                                                    item.href,
                                                                )
                                                                    ? 'text-primary'
                                                                    : '',
                                                            ]"
                                                            aria-hidden="true"
                                                        />
                                                        <span
                                                            class="min-w-0 flex-1 leading-snug break-words"
                                                        >
                                                            {{ item.label }}
                                                        </span>
                                                        <ExternalLink
                                                            class="size-3.5 shrink-0 opacity-60"
                                                            aria-hidden="true"
                                                        />
                                                    </a>
                                                    <Link
                                                        v-else
                                                        :href="item.href"
                                                        @click="
                                                            mobileMenuOpen = false
                                                        "
                                                        :class="[
                                                            'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                                            isCurrentUrl(
                                                                item.href,
                                                            )
                                                                ? 'bg-primary-soft text-accent-foreground'
                                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                                        ]"
                                                    >
                                                        <HelpCircle
                                                            :class="[
                                                                'size-4.5 shrink-0',
                                                                isCurrentUrl(
                                                                    item.href,
                                                                )
                                                                    ? 'text-primary'
                                                                    : '',
                                                            ]"
                                                            aria-hidden="true"
                                                        />
                                                        <span
                                                            class="min-w-0 flex-1 leading-snug break-words"
                                                        >
                                                            {{ item.label }}
                                                        </span>
                                                    </Link>
                                                </template>

                                                <a
                                                    v-if="guestSupportEmail"
                                                    :href="`mailto:${guestSupportEmail}`"
                                                    data-test="mobile-guest-report-issue"
                                                    class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                >
                                                    <Flag
                                                        class="size-4.5 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            t('nav.reportIssue')
                                                        }}
                                                    </span>
                                                </a>

                                                <a
                                                    v-if="
                                                        guestSupportEmail &&
                                                        !guestHasContactLink
                                                    "
                                                    :href="`mailto:${guestSupportEmail}`"
                                                    data-test="mobile-guest-contact-support"
                                                    class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                >
                                                    <Mail
                                                        class="size-4.5 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            t(
                                                                'nav.contactSupport',
                                                            )
                                                        }}
                                                    </span>
                                                </a>

                                                <Link
                                                    :href="
                                                        guestServicesNavItem.href
                                                    "
                                                    data-test="mobile-guest-services"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                    :class="[
                                                        'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                                        isCurrentOrParentUrl(
                                                            toUrl(
                                                                guestServicesNavItem.href,
                                                            ),
                                                        )
                                                            ? 'bg-primary-soft text-accent-foreground'
                                                            : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                                    ]"
                                                >
                                                    <component
                                                        :is="
                                                            guestServicesNavItem.icon
                                                        "
                                                        :class="[
                                                            'size-4.5 shrink-0',
                                                            isCurrentOrParentUrl(
                                                                toUrl(
                                                                    guestServicesNavItem.href,
                                                                ),
                                                            )
                                                                ? 'text-primary'
                                                                : '',
                                                        ]"
                                                        aria-hidden="true"
                                                    />
                                                    <span
                                                        class="min-w-0 flex-1 leading-snug break-words"
                                                    >
                                                        {{
                                                            guestServicesNavItem.title
                                                        }}
                                                    </span>
                                                </Link>
                                            </div>
                                        </div>

                                        <!-- Guest Auth Buttons in Drawer -->
                                        <div
                                            class="mt-2 grid grid-cols-2 gap-2 border-t border-border/70 pt-3"
                                        >
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                class="min-h-10 rounded-xl"
                                                as-child
                                            >
                                                <Link
                                                    :href="login()"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                    >{{ t('nav.login') }}</Link
                                                >
                                            </Button>
                                            <Button
                                                size="sm"
                                                class="min-h-10 rounded-xl"
                                                as-child
                                            >
                                                <Link
                                                    :href="register()"
                                                    @click="
                                                        mobileMenuOpen = false
                                                    "
                                                    >{{
                                                        t('nav.register')
                                                    }}</Link
                                                >
                                            </Button>
                                        </div>
                                    </nav>

                                    <!-- Optional non-intrusive PWA Install entry -->
                                    <div v-if="canInstallApp" class="px-3 pb-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="min-h-11 w-full justify-start gap-2.5 rounded-xl border-primary/30 bg-primary-soft/50 text-xs font-semibold text-primary"
                                            @click="
                                                promptInstallApp();
                                                mobileMenuOpen = false;
                                            "
                                        >
                                            <Download class="size-4 shrink-0" />
                                            <span class="truncate">{{
                                                t('pwa.installApp')
                                            }}</span>
                                        </Button>
                                    </div>
                                </div>
                            </template>

                            <!-- Authenticated Member Mobile Drawer (Preserved Unchanged) -->
                            <div
                                v-else
                                class="sv-scrollbar min-h-0 flex-1 overflow-y-auto overscroll-contain"
                            >
                                <SheetHeader
                                    class="border-b border-border px-5 py-4 text-start"
                                >
                                    <SheetTitle>
                                        <AppLogo />
                                    </SheetTitle>
                                    <SheetDescription class="sr-only">
                                        {{ brandName }}
                                    </SheetDescription>
                                </SheetHeader>

                                <nav
                                    :aria-label="t('nav.mobileNavLabel')"
                                    class="flex flex-col gap-2 p-3"
                                >
                                    <!-- Quick Shortcuts -->
                                    <div class="space-y-1">
                                        <Link
                                            v-for="item in mobileQuickItems"
                                            :key="item.id"
                                            :href="item.href"
                                            @click="mobileMenuOpen = false"
                                            :class="[
                                                'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                                isItemActive(item)
                                                    ? 'bg-primary-soft font-semibold text-accent-foreground'
                                                    : 'text-foreground/85 hover:bg-surface-hover hover:text-foreground',
                                            ]"
                                        >
                                            <component
                                                :is="item.icon"
                                                :class="[
                                                    'size-5 shrink-0',
                                                    isItemActive(item)
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                ]"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="min-w-0 flex-1 truncate"
                                                >{{ item.title }}</span
                                            >
                                        </Link>
                                    </div>

                                    <div
                                        class="my-1 border-t border-border/70"
                                    />

                                    <!-- Collapsible Feature Groups -->
                                    <div
                                        v-for="section in mobileCollapsibleSections"
                                        :key="section.id"
                                        class="space-y-1"
                                    >
                                        <button
                                            type="button"
                                            :aria-expanded="
                                                mobileExpandedSection ===
                                                section.id
                                            "
                                            class="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover"
                                            @click="
                                                toggleMobileSection(section.id)
                                            "
                                        >
                                            <span class="truncate">{{
                                                section.label
                                            }}</span>
                                            <ChevronDown
                                                :class="[
                                                    'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                                    mobileExpandedSection ===
                                                    section.id
                                                        ? 'rotate-180'
                                                        : '',
                                                ]"
                                                aria-hidden="true"
                                            />
                                        </button>

                                        <div
                                            v-show="
                                                mobileExpandedSection ===
                                                section.id
                                            "
                                            class="space-y-0.5 ps-2"
                                        >
                                            <Link
                                                v-for="item in section.items"
                                                :key="item.id"
                                                :href="item.href"
                                                @click="mobileMenuOpen = false"
                                                :class="[
                                                    'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                                    isItemActive(item)
                                                        ? 'bg-primary-soft text-accent-foreground'
                                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                                ]"
                                            >
                                                <component
                                                    :is="item.icon"
                                                    :class="[
                                                        'size-4.5 shrink-0',
                                                        isItemActive(item)
                                                            ? 'text-primary'
                                                            : '',
                                                    ]"
                                                    aria-hidden="true"
                                                />
                                                <span
                                                    class="min-w-0 flex-1 truncate"
                                                    >{{ item.title }}</span
                                                >
                                                <span
                                                    v-if="item.badgeText"
                                                    class="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-foreground tabular-nums"
                                                >
                                                    {{ item.badgeText }}
                                                </span>
                                            </Link>
                                        </div>
                                    </div>

                                    <!-- Admin Entry (Authorized Only) -->
                                    <template
                                        v-if="user && user.can?.accessAdmin"
                                    >
                                        <div
                                            class="my-1 border-t border-border/70"
                                        />
                                        <Link
                                            :href="adminOverview()"
                                            @click="mobileMenuOpen = false"
                                            class="flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover"
                                        >
                                            <Shield
                                                class="size-5 shrink-0 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="min-w-0 flex-1 truncate"
                                                >{{
                                                    t('nav.adminOverview')
                                                }}</span
                                            >
                                        </Link>
                                    </template>

                                    <!-- Guest Auth Buttons in Drawer -->
                                    <div
                                        v-if="!user"
                                        class="mt-2 grid grid-cols-2 gap-2 border-t border-border/70 pt-3"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="min-h-10 rounded-xl"
                                            as-child
                                        >
                                            <Link
                                                :href="login()"
                                                @click="mobileMenuOpen = false"
                                                >{{ t('nav.login') }}</Link
                                            >
                                        </Button>
                                        <Button
                                            size="sm"
                                            class="min-h-10 rounded-xl"
                                            as-child
                                        >
                                            <Link
                                                :href="register()"
                                                @click="mobileMenuOpen = false"
                                                >{{ t('nav.register') }}</Link
                                            >
                                        </Button>
                                    </div>
                                </nav>

                                <!-- Optional non-intrusive PWA Install entry -->
                                <div v-if="canInstallApp" class="px-3 pb-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="min-h-11 w-full justify-start gap-2.5 rounded-xl border-primary/30 bg-primary-soft/50 text-xs font-semibold text-primary"
                                        @click="
                                            promptInstallApp();
                                            mobileMenuOpen = false;
                                        "
                                    >
                                        <Download class="size-4 shrink-0" />
                                        <span class="truncate">{{
                                            t('pwa.installApp')
                                        }}</span>
                                    </Button>
                                </div>
                            </div>

                            <!-- Mobile Drawer Footer Links & Controls -->
                            <div
                                class="shrink-0 space-y-2.5 border-t border-border p-4"
                            >
                                <FooterLinkList
                                    v-if="footer.links.length > 0"
                                    :items="footer.links"
                                    variant="dots"
                                />
                                <div
                                    class="flex items-center justify-between pt-1"
                                >
                                    <LocaleSwitcher compact />
                                    <ThemeToggle />
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    {{
                                        footer.copyrightText ||
                                        t('footer.copyright', {
                                            year: footer.year,
                                            brand: brandName,
                                        })
                                    }}
                                </p>
                            </div>
                        </SheetContent>
                    </Sheet>

                    <Link
                        :href="home()"
                        class="flex min-w-0 items-center focus-visible:outline-none"
                    >
                        <AppLogo />
                    </Link>

                    <div
                        v-if="breadcrumbs.length > 0"
                        class="hidden border-s border-border ps-4 lg:block"
                    >
                        <Breadcrumbs :breadcrumbs="breadcrumbs" />
                    </div>
                </div>

                <!-- Debounced Global Search Bar (Tablet/Desktop) -->
                <form
                    @submit.prevent="submitHeaderSearch"
                    class="hidden max-w-md flex-1 items-center gap-2 rounded-full border border-border bg-muted/50 px-3.5 py-1.5 transition-colors focus-within:border-primary/50 focus-within:bg-card md:flex"
                >
                    <Search
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="headerSearch"
                        type="search"
                        inputmode="search"
                        enterkeyhint="search"
                        :placeholder="t('nav.searchPlaceholder')"
                        class="w-full bg-transparent text-xs text-foreground placeholder:text-muted-foreground focus:outline-none"
                        @input="handleHeaderSearchInput"
                    />
                </form>

                <div class="flex shrink-0 items-center gap-0.5 sm:gap-2">
                    <!-- Mobile Quick Search Action -->
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-10 rounded-full md:hidden"
                        :aria-label="t('search.title')"
                        as-child
                    >
                        <Link :href="SearchController.url()">
                            <Search class="size-5" aria-hidden="true" />
                        </Link>
                    </Button>

                    <template v-if="user">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="relative size-10 rounded-full sm:size-9"
                            :aria-label="t('nav.notifications')"
                            as-child
                        >
                            <Link :href="notificationsIndex()">
                                <Bell
                                    class="size-5 sm:size-4.5"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="unreadNotificationsCount > 0"
                                    class="absolute end-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground"
                                >
                                    {{
                                        unreadNotificationsCount > 99
                                            ? '99+'
                                            : unreadNotificationsCount
                                    }}
                                </span>
                            </Link>
                        </Button>

                        <Button
                            variant="ghost"
                            size="icon"
                            class="relative size-10 rounded-full sm:size-9"
                            :aria-label="t('nav.messages')"
                            as-child
                        >
                            <Link :href="messagesIndex()">
                                <MessageSquare
                                    class="size-5 sm:size-4.5"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="unreadMessagesCount > 0"
                                    class="absolute end-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground"
                                >
                                    {{
                                        unreadMessagesCount > 99
                                            ? '99+'
                                            : unreadMessagesCount
                                    }}
                                </span>
                            </Link>
                        </Button>
                    </template>

                    <div class="hidden sm:block">
                        <LocaleSwitcher compact />
                    </div>
                    <div class="hidden sm:block">
                        <ThemeToggle />
                    </div>

                    <template v-if="user">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    class="h-10 gap-1.5 rounded-full px-1.5 hover:bg-surface-hover sm:gap-2 sm:px-2 sm:pe-3"
                                    data-test="user-menu-trigger"
                                >
                                    <Avatar class="size-8 rounded-full">
                                        <AvatarImage
                                            v-if="user.avatar"
                                            :src="user.avatar"
                                            :alt="user.name"
                                        />
                                        <AvatarFallback
                                            class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                        >
                                            {{ getInitials(user.name) }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div class="hidden text-start sm:block">
                                        <p
                                            class="flex max-w-36 items-center gap-1 truncate text-xs leading-tight font-semibold"
                                        >
                                            <span class="truncate">{{
                                                user.name
                                            }}</span>
                                            <UserBadges
                                                :badges="
                                                    user.compact_badges ??
                                                    user.badges
                                                "
                                                mode="compact"
                                                size="xs"
                                                :max="2"
                                                :interactive="false"
                                                :fallback-is-premium="
                                                    Boolean(user.is_premium)
                                                "
                                                :fallback-is-ai="
                                                    Boolean(user.is_ai_agent)
                                                "
                                            />
                                        </p>
                                        <p
                                            class="text-[11px] leading-tight text-muted-foreground"
                                        >
                                            @{{ user.username }}
                                        </p>
                                    </div>
                                    <ChevronDown
                                        class="hidden size-4 text-muted-foreground sm:block"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                class="w-64 rounded-xl"
                            >
                                <UserMenuContent :user="user" />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>

                    <template v-else>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="min-h-10 rounded-lg px-2.5 sm:min-h-9 sm:px-3"
                            as-child
                        >
                            <Link :href="login()">{{ t('nav.login') }}</Link>
                        </Button>
                        <Button
                            size="sm"
                            class="hidden min-h-9 rounded-lg sm:inline-flex"
                            as-child
                        >
                            <Link :href="register()">{{
                                t('nav.register')
                            }}</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <NetworkStatusBanner />

        <!-- Responsive 3-Column Desktop / Icon-Rail Tablet / 1-Column Mobile Shell -->
        <div
            class="mx-auto flex w-full max-w-[1360px] items-start gap-4 px-2.5 py-4 pb-[calc(5.25rem+env(safe-area-inset-bottom,0px))] sm:px-4 sm:py-6 md:gap-5 md:pb-10 lg:gap-6 lg:px-6"
        >
            <!-- Left Sidebar: Compact Icon Rail on Tablet (72px), Clean Social Nav on Desktop (260px) -->
            <aside
                :aria-label="t('accessibility.mainNavigation')"
                class="sv-scrollbar sticky top-22 hidden max-h-[calc(100dvh-6rem)] shrink-0 space-y-3 overflow-y-auto md:block md:w-[72px] lg:w-[260px]"
                data-test="main-sidebar"
            >
                <div class="rounded-2xl border border-border bg-card p-2.5">
                    <TooltipProvider :delay-duration="250">
                        <!-- Primary Create Post CTA (Reuses Existing Create Post Modal) -->
                        <div v-if="user" class="mb-2.5">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        data-test="sidebar-create-post"
                                        :aria-label="t('nav.createPost')"
                                        class="h-11 w-full justify-center gap-2.5 rounded-xl px-0 text-sm font-semibold lg:px-4"
                                        @click="handleCreatePostClick"
                                    >
                                        <PenSquare
                                            class="size-5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span class="hidden truncate lg:inline">
                                            {{ t('nav.createPost') }}
                                        </span>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent
                                    :side="isRtl ? 'left' : 'right'"
                                    class="lg:hidden"
                                >
                                    {{ t('nav.createPost') }}
                                </TooltipContent>
                            </Tooltip>
                        </div>

                        <!-- Primary Social Navigation (5 Auth / 3 Guest) -->
                        <nav class="space-y-1">
                            <Tooltip
                                v-for="item in primaryNavItems"
                                :key="item.id"
                            >
                                <TooltipTrigger as-child>
                                    <Link
                                        :href="item.href"
                                        :aria-label="item.title"
                                        :aria-current="
                                            isItemActive(item)
                                                ? 'page'
                                                : undefined
                                        "
                                        :class="[
                                            'flex h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:justify-center md:px-0 lg:justify-start lg:px-3',
                                            isItemActive(item)
                                                ? 'bg-primary-soft font-semibold text-accent-foreground'
                                                : 'text-foreground/85 hover:bg-surface-hover hover:text-foreground',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[
                                                'size-5 shrink-0',
                                                isItemActive(item)
                                                    ? 'text-primary'
                                                    : 'text-muted-foreground',
                                            ]"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="hidden min-w-0 flex-1 truncate lg:inline"
                                            >{{ item.title }}</span
                                        >
                                    </Link>
                                </TooltipTrigger>
                                <TooltipContent
                                    :side="isRtl ? 'left' : 'right'"
                                    class="lg:hidden"
                                >
                                    {{ item.title }}
                                </TooltipContent>
                            </Tooltip>

                            <!-- Subtle Single Divider -->
                            <div
                                class="my-2 border-t border-border/70"
                                role="separator"
                            />

                            <!-- Secondary Items: Premium, Invite & Earn, More Menu -->
                            <Tooltip
                                v-for="item in secondaryNavItems"
                                :key="item.id"
                            >
                                <TooltipTrigger as-child>
                                    <Link
                                        :href="item.href"
                                        :aria-label="item.title"
                                        :aria-current="
                                            isItemActive(item)
                                                ? 'page'
                                                : undefined
                                        "
                                        :class="[
                                            'flex h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:justify-center md:px-0 lg:justify-start lg:px-3',
                                            isItemActive(item)
                                                ? 'bg-primary-soft font-semibold text-accent-foreground'
                                                : 'text-foreground/85 hover:bg-surface-hover hover:text-foreground',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[
                                                'size-5 shrink-0',
                                                isItemActive(item)
                                                    ? 'text-primary'
                                                    : 'text-muted-foreground',
                                            ]"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="hidden min-w-0 flex-1 truncate lg:inline"
                                            >{{ item.title }}</span
                                        >
                                    </Link>
                                </TooltipTrigger>
                                <TooltipContent
                                    :side="isRtl ? 'left' : 'right'"
                                    class="lg:hidden"
                                >
                                    {{ item.title }}
                                </TooltipContent>
                            </Tooltip>

                            <SidebarMoreMenu :active="isMoreMenuRouteActive" />
                        </nav>

                        <!-- Guest Auth Actions (Clean, Focused) -->
                        <template v-if="!user">
                            <div
                                class="my-2.5 border-t border-border/70"
                                role="separator"
                            />
                            <div class="hidden space-y-2 lg:block">
                                <Button
                                    variant="outline"
                                    class="h-10 w-full justify-center gap-2 rounded-xl text-sm font-medium"
                                    as-child
                                >
                                    <Link :href="login()">
                                        <LogIn
                                            class="size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span>{{ t('nav.login') }}</span>
                                    </Link>
                                </Button>
                                <Button
                                    class="h-10 w-full justify-center gap-2 rounded-xl text-sm font-semibold"
                                    as-child
                                >
                                    <Link :href="register()">
                                        <UserPlus
                                            class="size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span>{{ t('nav.register') }}</span>
                                    </Link>
                                </Button>
                            </div>
                            <div class="flex flex-col gap-1.5 lg:hidden">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-11 w-full rounded-xl"
                                            :aria-label="t('nav.login')"
                                            as-child
                                        >
                                            <Link :href="login()">
                                                <LogIn
                                                    class="size-5"
                                                    aria-hidden="true"
                                                />
                                            </Link>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        :side="isRtl ? 'left' : 'right'"
                                    >
                                        {{ t('nav.login') }}
                                    </TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            size="icon"
                                            class="size-11 w-full rounded-xl"
                                            :aria-label="t('nav.register')"
                                            as-child
                                        >
                                            <Link :href="register()">
                                                <UserPlus
                                                    class="size-5"
                                                    aria-hidden="true"
                                                />
                                            </Link>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        :side="isRtl ? 'left' : 'right'"
                                    >
                                        {{ t('nav.register') }}
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </template>
                    </TooltipProvider>
                </div>

                <!-- Compact Footer below Left Sidebar on wide Service Layout pages (Desktop only) -->
                <div v-if="isWideServiceLayout" class="hidden lg:block">
                    <MiniFooter />
                </div>
            </aside>

            <!-- Main Content Column (760px on 3-column Feed / 1060px on 2-column Service mode) -->
            <main
                id="main-content"
                :class="[
                    'mx-auto w-full min-w-0 flex-1',
                    isWideServiceLayout ? 'max-w-[1060px]' : 'max-w-[760px]',
                ]"
            >
                <AdvertisingCenterNav v-if="isAdvertisingCenterPage" />
                <slot />
            </main>

            <!-- Right Sidebar (~320px): Real Social Signals Only, Zero Empty Card Stacks -->
            <aside
                v-if="!isWideServiceLayout"
                :aria-label="t('accessibility.supplementaryInfo')"
                class="sticky top-22 hidden w-[320px] shrink-0 space-y-4 xl:block"
                data-test="right-sidebar"
            >
                <!-- Community Context Right Sidebar -->
                <template v-if="isCommunityPage && activeCommunity">
                    <div
                        v-if="activeCommunity.rules.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <ShieldCheck
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('communities.rulesTitle') }}</span>
                        </h2>
                        <ol
                            class="mt-3 space-y-2.5 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="(
                                    rule, idx
                                ) in activeCommunity.rules.slice(0, 5)"
                                :key="idx"
                                class="flex items-start gap-2"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[11px] font-bold text-accent-foreground"
                                >
                                    {{ idx + 1 }}
                                </span>
                                <span class="leading-relaxed">{{ rule }}</span>
                            </li>
                        </ol>
                        <Link
                            :href="
                                CommunityController.show.url(
                                    activeCommunity.slug,
                                    { query: { tab: 'rules' } },
                                )
                            "
                            class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            <span>{{ t('sidebar.viewFullRules') }} →</span>
                        </Link>
                    </div>

                    <div
                        v-if="communityLeaderboard.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Trophy
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('communities.leaderboardTitle') }}</span>
                        </h2>
                        <div class="mt-3 space-y-2.5">
                            <Link
                                v-for="(
                                    member, idx
                                ) in communityLeaderboard.slice(0, 5)"
                                :key="member.id"
                                :href="
                                    member.user
                                        ? ProfileViewController.show.url(
                                              member.user.username,
                                          )
                                        : '#'
                                "
                                class="flex items-center justify-between gap-2 rounded-lg p-1.5 transition-colors hover:bg-surface-hover"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        :class="[
                                            'flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                            idx === 0
                                                ? 'bg-primary-soft text-accent-foreground'
                                                : idx <= 2
                                                  ? 'bg-muted text-foreground'
                                                  : 'text-muted-foreground',
                                        ]"
                                    >
                                        {{ idx + 1 }}
                                    </span>
                                    <Avatar
                                        class="size-7 shrink-0 rounded-full"
                                    >
                                        <AvatarImage
                                            v-if="member.user?.avatar"
                                            :src="member.user.avatar"
                                            :alt="member.user.name"
                                        />
                                        <AvatarFallback
                                            class="bg-primary-soft text-[10px] font-semibold text-accent-foreground"
                                        >
                                            {{
                                                getInitials(
                                                    member.user?.name ?? 'User',
                                                )
                                            }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-xs font-semibold text-foreground"
                                        >
                                            {{ member.user?.name }}
                                        </p>
                                        <p
                                            class="truncate text-[10px] text-muted-foreground"
                                        >
                                            {{ member.role_label }}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-accent-foreground tabular-nums"
                                >
                                    {{
                                        t('communities.points', {
                                            count: member.points,
                                        })
                                    }}
                                </span>
                            </Link>
                        </div>
                        <Link
                            :href="
                                CommunityController.show.url(
                                    activeCommunity.slug,
                                    { query: { tab: 'members' } },
                                )
                            "
                            class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            <span
                                >{{ t('sidebar.viewFullLeaderboard') }} →</span
                            >
                        </Link>
                    </div>

                    <div
                        v-if="communityHashtags.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <TrendingUp
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{
                                t('communities.trendingHashtagsTitle')
                            }}</span>
                        </h2>
                        <div class="mt-3 space-y-2">
                            <Link
                                v-for="tag in communityHashtags"
                                :key="tag.id"
                                :href="
                                    SearchController.url({
                                        query: { hashtag: tag.slug },
                                    })
                                "
                                class="flex items-center justify-between text-xs transition-colors hover:text-primary"
                            >
                                <span class="font-semibold text-primary">
                                    #{{ tag.name }}
                                </span>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                >
                                    {{
                                        t('sidebar.postsCountShort', {
                                            count: tag.posts_count,
                                        })
                                    }}
                                </span>
                            </Link>
                        </div>
                        <Link
                            :href="
                                CommunityController.show.url(
                                    activeCommunity.slug,
                                    { query: { tab: 'topics' } },
                                )
                            "
                            class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            <span>{{ t('sidebar.seeMoreHashtags') }} →</span>
                        </Link>
                    </div>
                </template>

                <!-- Global Feed Right Sidebar: Prioritize Genuine Data, Hide Empty Cards -->
                <template v-else>
                    <!-- Trending Hashtags (Only rendered when real hashtags exist) -->
                    <div
                        v-if="sidebarHighlights.trendingHashtags.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <TrendingUp
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('sidebar.trendingHashtags') }}</span>
                        </h2>
                        <div class="mt-3 space-y-2.5">
                            <Link
                                v-for="tag in sidebarHighlights.trendingHashtags"
                                :key="tag.id"
                                :href="
                                    SearchController.url({
                                        query: { hashtag: tag.slug },
                                    })
                                "
                                class="flex items-center justify-between text-xs transition-colors hover:text-primary"
                            >
                                <span class="font-semibold text-primary">
                                    #{{ tag.name }}
                                </span>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                >
                                    {{
                                        t('sidebar.postsCountShort', {
                                            count: tag.posts_count,
                                        })
                                    }}
                                </span>
                            </Link>
                            <Link
                                :href="SearchController.url()"
                                class="mt-2 inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline"
                            >
                                <span>{{ t('sidebar.seeMore') }} →</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Suggested Communities (Only rendered when real communities exist) -->
                    <div
                        v-if="sidebarHighlights.featuredCommunities.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Users
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('sidebar.featuredCommunities') }}</span>
                        </h2>
                        <div class="mt-3 space-y-3">
                            <div
                                v-for="community in sidebarHighlights.featuredCommunities"
                                :key="community.id"
                                class="flex items-center justify-between gap-2.5"
                            >
                                <Link
                                    :href="
                                        CommunityController.show.url(
                                            community.slug,
                                        )
                                    "
                                    class="flex min-w-0 items-center gap-2.5"
                                >
                                    <Avatar class="size-9 shrink-0 rounded-xl">
                                        <AvatarImage
                                            v-if="community.avatar"
                                            :src="community.avatar"
                                            :alt="community.name"
                                        />
                                        <AvatarFallback
                                            class="bg-primary-soft text-xs font-bold text-accent-foreground"
                                        >
                                            {{ getInitials(community.name) }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-xs font-semibold text-foreground hover:text-primary"
                                        >
                                            {{ community.name }}
                                        </p>
                                        <p
                                            class="truncate text-[11px] text-muted-foreground"
                                        >
                                            {{
                                                t('sidebar.membersShort', {
                                                    count: community.members_count,
                                                })
                                            }}
                                        </p>
                                    </div>
                                </Link>
                                <Button
                                    type="button"
                                    size="sm"
                                    :variant="
                                        community.is_joined
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    class="h-7 shrink-0 rounded-lg px-2.5 text-[11px]"
                                    @click="
                                        toggleFeaturedCommunityJoin(
                                            community.slug,
                                        )
                                    "
                                >
                                    {{
                                        community.is_joined
                                            ? t('sidebar.joined')
                                            : t('sidebar.join')
                                    }}
                                </Button>
                            </div>
                            <Link
                                :href="communitiesIndex()"
                                class="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline"
                            >
                                <span
                                    >{{
                                        t('sidebar.seeMoreCommunities')
                                    }}
                                    →</span
                                >
                            </Link>
                        </div>
                    </div>

                    <!-- Notable Accounts (Real verified active accounts only) -->
                    <div
                        v-if="notableAccounts.length > 0"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Sparkles
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('sidebar.notableAccounts') }}</span>
                        </h2>
                        <div class="mt-3 space-y-2.5">
                            <Link
                                v-for="account in notableAccounts"
                                :key="account.id"
                                :href="
                                    ProfileViewController.show.url(
                                        account.username,
                                    )
                                "
                                class="-mx-1.5 flex items-center gap-2.5 rounded-xl p-1.5 transition-colors hover:bg-surface-hover"
                            >
                                <Avatar class="size-9 shrink-0 rounded-full">
                                    <AvatarImage
                                        v-if="account.avatar"
                                        :src="account.avatar"
                                        :alt="account.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(account.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="flex items-center gap-1 truncate text-xs font-semibold text-foreground"
                                    >
                                        <span class="truncate">{{
                                            account.name
                                        }}</span>
                                        <UserBadges
                                            :badges="
                                                account.compact_badges ??
                                                account.badges
                                            "
                                            mode="compact"
                                            size="xs"
                                            :max="2"
                                            :fallback-is-premium="
                                                Boolean(account.is_premium)
                                            "
                                            :fallback-is-ai="
                                                Boolean(account.is_ai_agent)
                                            "
                                            :fallback-ai-disclosure="
                                                account.ai_disclosure
                                            "
                                        />
                                    </p>
                                    <p
                                        class="truncate text-[11px] text-muted-foreground"
                                    >
                                        {{
                                            account.headline ||
                                            `@${account.username}`
                                        }}
                                    </p>
                                </div>
                            </Link>
                            <Link
                                :href="SearchController.url()"
                                class="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline"
                            >
                                <span>{{ t('sidebar.seeMore') }} →</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Single Clean Discovery Card ONLY when no real trending/community/account data exists -->
                    <div
                        v-if="!hasGlobalRightSidebarData"
                        class="rounded-2xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Compass
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{
                                t('sidebar.discoverSyncViet', {
                                    brand: brandName,
                                })
                            }}</span>
                        </h2>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('sidebar.discoverDescription') }}
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                class="rounded-lg text-xs"
                                as-child
                            >
                                <Link :href="SearchController.url()">
                                    {{ t('sidebar.exploreNow') }}
                                </Link>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="rounded-lg text-xs"
                                as-child
                            >
                                <Link :href="communitiesIndex()">
                                    {{ t('nav.communities') }}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </template>

                <!-- Right Sidebar Active Direct Banner (auto-collapses cleanly if vacant) -->
                <BannerSlot
                    v-if="sidebarHighlights.banner"
                    :banner="sidebarHighlights.banner"
                    compact
                />

                <!-- Mini Footer below Right Sidebar -->
                <MiniFooter />
            </aside>
        </div>

        <MobileBottomNav />
        <PostComposerModal v-if="user" />
        <Toaster />
    </div>
</template>
