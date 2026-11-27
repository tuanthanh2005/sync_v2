<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bell,
    CheckCircle2,
    ChevronDown,
    Compass,
    Crown,
    Download,
    Gift,
    Home,
    Image as ImageIcon,
    LayoutGrid,
    Megaphone,
    Menu,
    MessageSquare,
    Palette,
    ReceiptText,
    Search,
    Shield,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Trophy,
    User as UserIcon,
    Users,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import SearchController from '@/actions/App/Http/Controllers/Social/SearchController';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import MobileBottomNav from '@/components/common/MobileBottomNav.vue';
import NetworkStatusBanner from '@/components/common/NetworkStatusBanner.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';
import MiniFooter from '@/components/footer/MiniFooter.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
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
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { useCompactScrollHeader } from '@/composables/useMobileViewport';
import { usePwa } from '@/composables/usePwa';
import { formatVnd } from '@/lib/currency';
import { toUrl } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { index as adsIndex } from '@/routes/ads';
import { edit as editAppearance } from '@/routes/appearance';
import { index as communitiesIndex } from '@/routes/communities';
import { index as messagesIndex } from '@/routes/messages';
import {
    banner as bannerRoute,
    boost as boostRoute,
    premium as premiumRoute,
    services as servicesRoute,
} from '@/routes/monetization';
import { index as notificationsIndex } from '@/routes/notifications';
import { index as paymentsIndex } from '@/routes/payments';
import { index as referralIndex } from '@/routes/referral';
import { premium as accountPremiumRoute } from '@/routes/account';
import { edit as editSecurity } from '@/routes/security';
import { index as walletIndex } from '@/routes/wallet';
import type {
    BreadcrumbItem,
    CommunityDetail,
    CommunityMemberItem,
    HashtagItem,
    NavItem,
} from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    hideRightSidebar?: boolean;
};

type SidebarNavItem = NavItem & {
    badgeText?: string;
};

type SidebarGroup = {
    id: string;
    label: string;
    items: SidebarNavItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    hideRightSidebar: false,
});

const { t, locale } = useI18n();
const lang = computed<'vi' | 'en'>(() => (locale.value === 'en' ? 'en' : 'vi'));
const page = usePage();
const user = computed(() => page.props.auth.user);
const footer = computed(() => page.props.footer);
const brandName = computed(() => page.props.name);
const sidebarHighlights = computed(() => page.props.sidebarHighlights);
const unreadNotificationsCount = computed(
    () => page.props.unreadNotificationsCount ?? 0,
);
const unreadMessagesCount = computed(() => page.props.unreadMessagesCount ?? 0);
const walletBalance = computed(() => page.props.walletBalance ?? 0);

const isCommunityPage = computed(() => page.component === 'communities/Show');
/**
 * Wide 2-column service mode (no Right Sidebar) for Premium, Advertising,
 * Banner, Boost, Advertising Balance, and Payment History. Resolved dynamically
 * on the persistent ConnectHubLayout instance so Header & Left Sidebar never
 * unmount or flash across Inertia page navigations.
 */
const isWideServiceLayout = computed(
    () =>
        props.hideRightSidebar ||
        page.component.startsWith('monetization/') ||
        page.component.startsWith('growth/') ||
        page.component === 'Wallet' ||
        page.component === 'Ads',
);
const isAdsOrWalletPage = computed(
    () => page.component === 'Ads' || page.component === 'Wallet',
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

const mobileMenuOpen = ref(false);
const headerSearch = ref('');
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { getInitials } = useInitials();
const { headerHidden } = useCompactScrollHeader();
const { canInstallApp, promptInstallApp } = usePwa();

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

onBeforeUnmount(() => {
    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }
});

const sidebarGroups = computed<SidebarGroup[]>(() => {
    if (!user.value) {
        const guestGroups: SidebarGroup[] = [
            {
                id: 'discover',
                label: t('monetization.sidebar.discover'),
                items: [
                    { title: t('nav.home'), href: home(), icon: Home },
                    {
                        title: t('monetization.sidebar.explore'),
                        href: SearchController.url(),
                        icon: Compass,
                    },
                    {
                        title: t('nav.communities'),
                        href: communitiesIndex(),
                        icon: Users,
                    },
                ],
            },
            {
                id: 'services',
                label: t('monetization.sidebar.services'),
                items: [
                    {
                        title: t('monetization.services.pageTitle'),
                        href: servicesRoute(),
                        icon: Sparkles,
                    },
                    {
                        title: 'SyncViet Premium',
                        href: premiumRoute(),
                        icon: Crown,
                    },
                    {
                        title: t('monetization.services.boostTitle'),
                        href: boostRoute(),
                        icon: Zap,
                    },
                    {
                        title: t('monetization.services.bannerTitle'),
                        href: bannerRoute(),
                        icon: ImageIcon,
                    },
                ],
            },
        ];

        return guestGroups;
    }

    const groups: SidebarGroup[] = [
        {
            id: 'discover',
            label: t('monetization.sidebar.discover'),
            items: [
                { title: t('nav.home'), href: home(), icon: Home },
                {
                    title: t('monetization.sidebar.explore'),
                    href: SearchController.url(),
                    icon: Compass,
                },
                {
                    title: t('nav.communities'),
                    href: communitiesIndex(),
                    icon: Users,
                },
                ...(!user.value.is_ai_agent
                    ? [
                          {
                              title: t('nav.referral'),
                              href: referralIndex(),
                              icon: Gift,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'services',
            label: t('monetization.sidebar.services'),
            items: [
                {
                    title: 'SyncViet Premium',
                    href: user.value.is_premium
                        ? accountPremiumRoute()
                        : premiumRoute(),
                    icon: Crown,
                },
                {
                    title: t('monetization.services.boostTitle'),
                    href: boostRoute(),
                    icon: Zap,
                },
                {
                    title: t('monetization.services.bannerTitle'),
                    href: bannerRoute(),
                    icon: ImageIcon,
                },
                {
                    title: t('monetization.wallet.title'),
                    href: walletIndex(),
                    icon: WalletIcon,
                    badgeText: formatVnd(walletBalance.value, lang.value),
                },
                {
                    title: t('monetization.payments.title'),
                    href: paymentsIndex(),
                    icon: ReceiptText,
                },
            ],
        },
        {
            id: 'account',
            label: t('monetization.sidebar.account'),
            items: [
                {
                    title: t('nav.publicProfile'),
                    href: ProfileViewController.me.url(),
                    icon: UserIcon,
                },
                {
                    title: t('nav.dashboard'),
                    href: dashboard(),
                    icon: LayoutGrid,
                },
                {
                    title: t('nav.security'),
                    href: editSecurity(),
                    icon: ShieldCheck,
                },
                {
                    title: t('nav.appearance'),
                    href: editAppearance(),
                    icon: Palette,
                },
            ],
        },
    ];

    if (user.value.can?.accessAdmin) {
        groups.push({
            id: 'admin',
            label: t('monetization.sidebar.admin'),
            items: [
                {
                    title: t('nav.adminOverview'),
                    href: adminOverview(),
                    icon: Shield,
                },
            ],
        });
    }

    return groups;
});

function isItemActive(item: NavItem): boolean {
    const targetUrl = toUrl(item.href);

    if (targetUrl === toUrl(home()) || targetUrl === toUrl(adminOverview())) {
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
                    ? '-translate-y-full lg:translate-y-0'
                    : 'translate-y-0',
            ]"
        >
            <div
                class="mx-auto flex h-14 max-w-[1360px] items-center justify-between gap-2 px-3 sm:h-16 sm:gap-4 sm:px-4 lg:px-6"
            >
                <div class="flex min-w-0 items-center gap-1.5 sm:gap-3">
                    <Sheet v-model:open="mobileMenuOpen">
                        <SheetTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 rounded-xl lg:hidden"
                                :aria-label="t('nav.openMobileMenu')"
                            >
                                <Menu class="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            class="flex w-[85vw] max-w-72 flex-col justify-between p-0"
                        >
                            <div
                                class="sv-scrollbar min-h-0 flex-1 overflow-y-auto overscroll-contain"
                            >
                                <SheetHeader
                                    class="border-b border-border px-5 py-4 text-left"
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
                                    class="flex flex-col gap-4 p-3"
                                >
                                    <div
                                        v-for="group in sidebarGroups"
                                        :key="group.id"
                                        class="space-y-1"
                                    >
                                        <p
                                            class="px-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            {{ group.label }}
                                        </p>
                                        <Link
                                            v-for="item in group.items"
                                            :key="item.title"
                                            :href="item.href"
                                            @click="mobileMenuOpen = false"
                                            :class="[
                                                'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
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
                                                class="shrink-0 rounded-full bg-primary-soft px-2 py-0.5 text-[11px] font-semibold text-accent-foreground"
                                            >
                                                {{ item.badgeText }}
                                            </span>
                                        </Link>
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
                        class="hidden border-l border-border pl-4 md:block"
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
                                    class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground"
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
                                    class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground"
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
                                    class="h-10 gap-1.5 rounded-full px-1.5 hover:bg-surface-hover sm:gap-2 sm:px-2 sm:pr-3"
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
                                    <div class="hidden text-left sm:block">
                                        <p
                                            class="flex max-w-36 items-center gap-1 truncate text-xs leading-tight font-semibold"
                                        >
                                            <span class="truncate">{{
                                                user.name
                                            }}</span>
                                            <PremiumBadge
                                                v-if="user.is_premium"
                                                size="sm"
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

        <!-- Desktop 3-Column Shell / Mobile 1-Column Full-Width Shell -->
        <div
            class="mx-auto flex w-full max-w-[1360px] items-start gap-6 px-2.5 py-4 pb-[calc(5.25rem+env(safe-area-inset-bottom,0px))] sm:px-4 sm:py-6 lg:px-6 lg:pb-10"
        >
            <!-- Left Sidebar (~240px) -->
            <aside
                :aria-label="t('accessibility.mainNavigation')"
                class="sticky top-22 hidden w-[240px] shrink-0 space-y-4 lg:block"
            >
                <div class="rounded-xl border border-border bg-card p-3">
                    <nav class="space-y-4">
                        <div
                            v-for="group in sidebarGroups"
                            :key="group.id"
                            class="space-y-1"
                        >
                            <p
                                class="px-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                {{ group.label }}
                            </p>
                            <Link
                                v-for="item in group.items"
                                :key="item.title"
                                :href="item.href"
                                :class="[
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-xs font-medium transition-colors',
                                    isItemActive(item)
                                        ? 'bg-primary-soft text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <component
                                    :is="item.icon"
                                    :class="[
                                        'size-4 shrink-0',
                                        isItemActive(item)
                                            ? 'text-primary'
                                            : '',
                                    ]"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    item.title
                                }}</span>
                                <span
                                    v-if="item.badgeText"
                                    class="shrink-0 rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-accent-foreground"
                                >
                                    {{ item.badgeText }}
                                </span>
                            </Link>
                        </div>
                    </nav>
                </div>

                <!-- Left Sidebar Guide / Tablet Promo Card (avoids duplicating Right Sidebar card on xl+) -->
                <div
                    v-if="!isWideServiceLayout && isAdsOrWalletPage"
                    class="rounded-xl border border-border bg-card p-4 text-center"
                >
                    <div
                        class="mx-auto flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Megaphone class="size-5" aria-hidden="true" />
                    </div>
                    <h2 class="mt-2.5 text-xs font-bold text-foreground">
                        {{ t('sidebar.adsGuideCardTitle') }}
                    </h2>
                    <p
                        class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ t('sidebar.adsGuideCardDesc') }}
                    </p>
                    <Button size="sm" class="mt-3.5 w-full rounded-lg" as-child>
                        <Link :href="walletIndex()">
                            <span>{{ t('sidebar.adsGuideCardCta') }}</span>
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </Button>
                    <ul
                        class="mt-3.5 space-y-1.5 text-left text-[11px] text-muted-foreground"
                    >
                        <li class="flex items-center gap-1.5">
                            <CheckCircle2
                                class="size-3.5 shrink-0 text-primary"
                            />
                            <span>{{ t('sidebar.adsBullet1') }}</span>
                        </li>
                        <li class="flex items-center gap-1.5">
                            <CheckCircle2
                                class="size-3.5 shrink-0 text-primary"
                            />
                            <span>{{ t('sidebar.adsBullet2') }}</span>
                        </li>
                        <li class="flex items-center gap-1.5">
                            <CheckCircle2
                                class="size-3.5 shrink-0 text-primary"
                            />
                            <span>{{ t('sidebar.adsBullet3') }}</span>
                        </li>
                    </ul>
                </div>

                <div
                    v-else-if="
                        !isWideServiceLayout && (!isCommunityPage || !user)
                    "
                    class="rounded-xl border border-border bg-card p-4 text-center xl:hidden"
                >
                    <div
                        class="mx-auto flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Megaphone class="size-5" aria-hidden="true" />
                    </div>
                    <h2 class="mt-2.5 text-xs font-bold text-foreground">
                        {{ t('sidebar.promoteCardTitle') }}
                    </h2>
                    <p
                        class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{
                            user
                                ? t('sidebar.promoteCardDesc')
                                : t('sidebar.guestSummary')
                        }}
                    </p>
                    <Button size="sm" class="mt-3.5 w-full rounded-lg" as-child>
                        <Link :href="user ? adsIndex() : register()">
                            <span>{{
                                user
                                    ? t('sidebar.promoteCardCta')
                                    : t('sidebar.createAccount')
                            }}</span>
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="user && !user.two_factor_enabled"
                    class="rounded-xl border border-warning/30 bg-card p-3.5"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="w-full rounded-lg text-[11px]"
                        as-child
                    >
                        <Link :href="editSecurity()">
                            <ShieldCheck class="size-3.5 text-warning" />
                            <span>{{ t('sidebar.enable2fa') }}</span>
                        </Link>
                    </Button>
                </div>

                <!-- Compact Footer below Left Sidebar on wide Service Layout pages -->
                <MiniFooter v-if="isWideServiceLayout" />
            </aside>

            <!-- Main Content Column (760px on 3-column Feed / 1060px on 2-column Service mode) -->
            <main
                id="main-content"
                :class="[
                    'mx-auto w-full min-w-0 flex-1',
                    isWideServiceLayout ? 'max-w-[1060px]' : 'max-w-[760px]',
                ]"
            >
                <slot />
            </main>

            <!-- Right Sidebar (~320px) + MiniFooter (hidden on Service Layout pages) -->
            <aside
                v-if="!isWideServiceLayout"
                :aria-label="t('accessibility.supplementaryInfo')"
                class="sticky top-22 hidden w-[320px] shrink-0 space-y-4 xl:block"
            >
                <!-- Community Context Right Sidebar (Mockup 2) -->
                <template v-if="isCommunityPage && activeCommunity">
                    <div class="rounded-xl border border-border bg-card p-5">
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
                            v-if="activeCommunity.rules.length > 0"
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
                        <p v-else class="mt-2 text-xs text-muted-foreground">
                            {{ t('communities.noRules') }}
                        </p>
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

                    <div class="rounded-xl border border-border bg-card p-5">
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Trophy
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('communities.leaderboardTitle') }}</span>
                        </h2>
                        <div
                            v-if="communityLeaderboard.length > 0"
                            class="mt-3 space-y-2.5"
                        >
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
                                    class="shrink-0 rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-accent-foreground"
                                >
                                    {{
                                        t('communities.points', {
                                            count: member.points,
                                        })
                                    }}
                                </span>
                            </Link>
                        </div>
                        <p v-else class="mt-2 text-xs text-muted-foreground">
                            {{ t('communities.noLeaderboard') }}
                        </p>
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
                        class="rounded-xl border border-border bg-card p-5 shadow-xs"
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
                        <div
                            v-if="communityHashtags.length > 0"
                            class="mt-3 space-y-2"
                        >
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
                                <span class="text-muted-foreground">
                                    {{
                                        t('sidebar.postsCountShort', {
                                            count: tag.posts_count,
                                        })
                                    }}
                                </span>
                            </Link>
                        </div>
                        <p v-else class="mt-2 text-xs text-muted-foreground">
                            {{ t('communities.noHashtags') }}
                        </p>
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

                    <div
                        class="rounded-xl border border-border bg-card p-5 text-center"
                    >
                        <h2 class="text-sm font-bold text-foreground">
                            {{ t('sidebar.hotTopicsTitle') }}
                        </h2>
                        <p
                            class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('sidebar.hotTopicsDesc') }}
                        </p>
                        <Button
                            size="sm"
                            class="mt-4 w-full rounded-lg"
                            as-child
                        >
                            <Link
                                :href="
                                    CommunityController.show.url(
                                        activeCommunity.slug,
                                        { query: { tab: 'topics' } },
                                    )
                                "
                            >
                                {{ t('sidebar.hotTopicsCta') }} →
                            </Link>
                        </Button>
                    </div>
                </template>

                <!-- Global Feed Right Sidebar (Mockup 1 & 4) -->
                <template v-else>
                    <div class="rounded-xl border border-border bg-card p-5">
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <TrendingUp
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('sidebar.trendingHashtags') }}</span>
                        </h2>
                        <div
                            v-if="sidebarHighlights.trendingHashtags.length > 0"
                            class="mt-3 space-y-2.5"
                        >
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
                                <span class="text-muted-foreground">
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
                        <p
                            v-else
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('sidebar.topicsEmpty') }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-border bg-card p-5">
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Users
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ t('sidebar.featuredCommunities') }}</span>
                        </h2>
                        <div
                            v-if="
                                sidebarHighlights.featuredCommunities.length > 0
                            "
                            class="mt-3 space-y-3"
                        >
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
                        <template v-else>
                            <p
                                class="mt-2 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ t('sidebar.communitiesDescription') }}
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                class="mt-4 w-full rounded-lg"
                                as-child
                            >
                                <Link :href="communitiesIndex()">
                                    {{ t('sidebar.seeMoreCommunities') }}
                                </Link>
                            </Button>
                        </template>
                    </div>

                    <!-- Global Right Sidebar Ads CTA Card (Mockup 1 & 4) -->
                    <div class="rounded-xl border border-border bg-card p-5">
                        <div class="text-center">
                            <div
                                class="mx-auto flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"
                            >
                                <TrendingUp class="size-5" aria-hidden="true" />
                            </div>
                            <h2
                                class="mt-2.5 text-sm font-bold text-foreground"
                            >
                                {{ t('sidebar.rightPromoTitle') }}
                            </h2>
                            <p
                                class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ t('sidebar.rightPromoDesc') }}
                            </p>
                        </div>
                        <ul
                            class="mt-3.5 space-y-2 text-xs text-muted-foreground"
                        >
                            <li class="flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 shrink-0 text-primary"
                                />
                                <span>{{
                                    t('sidebar.rightPromoBullet1')
                                }}</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 shrink-0 text-primary"
                                />
                                <span>{{
                                    t('sidebar.rightPromoBullet2')
                                }}</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 shrink-0 text-primary"
                                />
                                <span>{{
                                    t('sidebar.rightPromoBullet3')
                                }}</span>
                            </li>
                        </ul>
                        <Button
                            size="sm"
                            class="mt-4 w-full rounded-lg"
                            as-child
                        >
                            <Link :href="user ? walletIndex() : register()">
                                <span>{{ t('sidebar.rightPromoCta') }}</span>
                                <ArrowRight class="size-3.5" />
                            </Link>
                        </Button>
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
        <Toaster />
    </div>
</template>
