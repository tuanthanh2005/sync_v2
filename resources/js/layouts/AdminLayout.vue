<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    BadgeCheck,
    BarChart3,
    Bell,
    ChevronDown,
    CreditCard,
    DatabaseBackup,
    FileText,
    Flag,
    Globe,
    Hash,
    HeartPulse,
    Image as ImageIcon,
    KeyRound,
    LayoutDashboard,
    Megaphone,
    Menu,
    MessageSquareText,
    Newspaper,
    PanelLeftClose,
    PanelLeftOpen,
    Scale,
    Search,
    Settings,
    Shield,
    ShieldAlert,
    ShieldBan,
    Users,
    Vote,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
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
import { useInitials } from '@/composables/useInitials';
import { home } from '@/routes';
import {
    analytics as adminAnalytics,
    health as adminHealth,
    overview as adminOverview,
} from '@/routes/admin';
import { index as adminAds } from '@/routes/admin/ads';
import { index as adminAppeals } from '@/routes/admin/appeals';
import { index as adminBackups } from '@/routes/admin/backups';
import {
    comments as adminComments,
    communities as adminCommunities,
    hashtags as adminHashtags,
    keywords as adminKeywords,
    media as adminMedia,
    polls as adminPolls,
    posts as adminPosts,
} from '@/routes/admin/content';
import { index as adminFinance } from '@/routes/admin/finance';
import { index as adminLanguages } from '@/routes/admin/languages';
import { index as adminModeration } from '@/routes/admin/moderation';
import { index as adminNotifications } from '@/routes/admin/notifications';
import { index as adminPolicies } from '@/routes/admin/policies';
import { index as adminRoles } from '@/routes/admin/roles';
import { index as adminSecurity } from '@/routes/admin/security';
import { index as adminSettings } from '@/routes/admin/settings';
import { index as adminUsers } from '@/routes/admin/users';
import { index as adminVerifications } from '@/routes/admin/verifications';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

type AdminMenuItem = {
    label: string;
    href: string;
    icon: Component;
    badge?: number;
    exact?: boolean;
};

type AdminMenuGroup = {
    id: string;
    label: string;
    items: AdminMenuItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const { t } = useI18n();
const page = usePage();
const { getInitials } = useInitials();

const user = computed(() => page.props.auth.user);
const can = computed(() => user.value?.can);
const brandName = computed(() => page.props.name || 'SyncViet');
const pending = computed(() => page.props.adminPendingCounts);

const collapsed = ref(false);
const mobileOpen = ref(false);
const quickQuery = ref('');

onMounted(() => {
    try {
        collapsed.value =
            window.localStorage.getItem('syncviet_admin_sidebar_collapsed') ===
            '1';
    } catch {
        // Ignore storage errors
    }
});

function toggleCollapse(): void {
    collapsed.value = !collapsed.value;
    try {
        window.localStorage.setItem(
            'syncviet_admin_sidebar_collapsed',
            collapsed.value ? '1' : '0',
        );
    } catch {
        // Ignore storage errors
    }
}

function submitQuickSearch(): void {
    const q = quickQuery.value.trim();
    if (!q) {
        return;
    }

    router.get(adminUsers.url({ query: { search: q } }));
}

function isActive(item: AdminMenuItem): boolean {
    const current = page.url;

    if (item.exact) {
        return current === item.href || current.split('?')[0] === item.href;
    }

    if (item.href.includes('?')) {
        return current === item.href;
    }

    return (
        current === item.href ||
        current.startsWith(`${item.href}/`) ||
        current.startsWith(`${item.href}?`)
    );
}

const navGroups = computed<AdminMenuGroup[]>(() => {
    const perms = can.value;
    if (!perms) {
        return [];
    }

    const groups: AdminMenuGroup[] = [
        {
            id: 'overview',
            label: t('admin.groups.overview'),
            items: [
                {
                    label: t('admin.nav.dashboard'),
                    href: adminOverview.url(),
                    icon: LayoutDashboard,
                    exact: true,
                },
                ...(perms.viewAnalytics
                    ? [
                          {
                              label: t('admin.nav.analytics'),
                              href: adminAnalytics.url(),
                              icon: BarChart3,
                          },
                      ]
                    : []),
                ...(perms.viewSystemHealth
                    ? [
                          {
                              label: t('admin.nav.systemHealth'),
                              href: adminHealth.url(),
                              icon: HeartPulse,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'users',
            label: t('admin.groups.users'),
            items: [
                ...(perms.manageUsers
                    ? [
                          {
                              label: t('admin.nav.allUsers'),
                              href: adminUsers.url(),
                              icon: Users,
                              exact: true,
                          },
                          {
                              label: t('admin.nav.suspendedUsers'),
                              href: adminUsers.url({
                                  query: { filter: 'suspended' },
                              }),
                              icon: ShieldAlert,
                          },
                          {
                              label: t('admin.nav.bannedUsers'),
                              href: adminUsers.url({
                                  query: { filter: 'banned' },
                              }),
                              icon: ShieldBan,
                          },
                      ]
                    : []),
                ...(perms.manageRoles
                    ? [
                          {
                              label: t('admin.nav.roles'),
                              href: adminRoles.url(),
                              icon: KeyRound,
                          },
                      ]
                    : []),
                ...(perms.manageVerifications
                    ? [
                          {
                              label: t('admin.nav.verifications'),
                              href: adminVerifications.url(),
                              icon: BadgeCheck,
                              badge: pending.value?.verifications || 0,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'content',
            label: t('admin.groups.content'),
            items: [
                ...(perms.managePosts
                    ? [
                          {
                              label: t('admin.nav.posts'),
                              href: adminPosts.url(),
                              icon: Newspaper,
                          },
                      ]
                    : []),
                ...(perms.manageComments
                    ? [
                          {
                              label: t('admin.nav.comments'),
                              href: adminComments.url(),
                              icon: MessageSquareText,
                          },
                      ]
                    : []),
                ...(perms.manageCommunities
                    ? [
                          {
                              label: t('admin.nav.communities'),
                              href: adminCommunities.url(),
                              icon: Users,
                          },
                      ]
                    : []),
                ...(perms.managePolls
                    ? [
                          {
                              label: t('admin.nav.polls'),
                              href: adminPolls.url(),
                              icon: Vote,
                          },
                      ]
                    : []),
                ...(perms.manageHashtags
                    ? [
                          {
                              label: t('admin.nav.hashtags'),
                              href: adminHashtags.url(),
                              icon: Hash,
                          },
                      ]
                    : []),
                ...(perms.manageMedia
                    ? [
                          {
                              label: t('admin.nav.media'),
                              href: adminMedia.url(),
                              icon: ImageIcon,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'moderation',
            label: t('admin.groups.moderation'),
            items: [
                ...(perms.accessModeration
                    ? [
                          {
                              label: t('admin.nav.reportCenter'),
                              href: adminModeration.url(),
                              icon: Flag,
                              badge: pending.value?.reports || 0,
                          },
                      ]
                    : []),
                ...(perms.manageAppeals
                    ? [
                          {
                              label: t('admin.nav.appeals'),
                              href: adminAppeals.url(),
                              icon: Scale,
                              badge: pending.value?.appeals || 0,
                          },
                      ]
                    : []),
                ...(perms.manageKeywords
                    ? [
                          {
                              label: t('admin.nav.blockedKeywords'),
                              href: adminKeywords.url(),
                              icon: ShieldBan,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'advertising',
            label: t('admin.groups.advertising'),
            items: perms.reviewAds
                ? [
                      {
                          label: t('admin.nav.campaigns'),
                          href: adminAds.url(),
                          icon: Megaphone,
                          exact: true,
                      },
                      {
                          label: t('admin.nav.pendingAds'),
                          href: adminAds.url({
                              query: { status: 'pending_review' },
                          }),
                          icon: Activity,
                          badge: pending.value?.ads || 0,
                      },
                  ]
                : [],
        },
        {
            id: 'finance',
            label: t('admin.groups.finance'),
            items: perms.manageFinance
                ? [
                      {
                          label: t('admin.nav.finance'),
                          href: adminFinance.url(),
                          icon: CreditCard,
                      },
                  ]
                : [],
        },
        {
            id: 'system',
            label: t('admin.groups.system'),
            items: [
                ...(perms.manageSettings
                    ? [
                          {
                              label: t('admin.nav.settings'),
                              href: adminSettings.url(),
                              icon: Settings,
                          },
                      ]
                    : []),
                ...(perms.manageLanguages
                    ? [
                          {
                              label: t('admin.nav.languages'),
                              href: adminLanguages.url(),
                              icon: Globe,
                          },
                      ]
                    : []),
                ...(perms.manageNotifications
                    ? [
                          {
                              label: t('admin.nav.notifications'),
                              href: adminNotifications.url(),
                              icon: Bell,
                          },
                      ]
                    : []),
                ...(perms.managePolicies
                    ? [
                          {
                              label: t('admin.nav.policies'),
                              href: adminPolicies.url(),
                              icon: FileText,
                          },
                      ]
                    : []),
                ...(perms.viewSecurity
                    ? [
                          {
                              label: t('admin.nav.security'),
                              href: adminSecurity.url(),
                              icon: Shield,
                          },
                      ]
                    : []),
                ...(perms.manageBackups
                    ? [
                          {
                              label: t('admin.nav.backups'),
                              href: adminBackups.url(),
                              icon: DatabaseBackup,
                          },
                      ]
                    : []),
            ],
        },
    ];

    return groups.filter((group) => group.items.length > 0);
});
</script>

<template>
    <div class="flex min-h-screen bg-background text-foreground">
        <!-- Desktop Collapsible Admin Sidebar -->
        <aside
            :class="[
                'sticky top-0 hidden h-screen shrink-0 flex-col border-e border-border bg-card transition-all duration-200 lg:flex',
                collapsed ? 'w-[72px]' : 'w-[264px]',
            ]"
        >
            <div
                class="flex h-16 items-center justify-between border-b border-border px-3.5"
            >
                <Link
                    :href="adminOverview()"
                    class="flex min-w-0 items-center gap-2.5"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <AppLogoIcon class="size-5" />
                    </div>
                    <div v-if="!collapsed" class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">
                            {{ brandName }}
                        </p>
                        <p
                            class="truncate text-[11px] font-medium text-primary"
                        >
                            {{ t('admin.centerTitle') }}
                        </p>
                    </div>
                </Link>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 shrink-0 rounded-lg text-muted-foreground hover:text-foreground"
                    :aria-label="
                        collapsed
                            ? t('admin.expandSidebar')
                            : t('admin.collapseSidebar')
                    "
                    @click="toggleCollapse"
                >
                    <PanelLeftOpen v-if="collapsed" class="size-4" />
                    <PanelLeftClose v-else class="size-4" />
                </Button>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-2.5 py-4">
                <div
                    v-for="group in navGroups"
                    :key="group.id"
                    class="space-y-1"
                >
                    <p
                        v-if="!collapsed"
                        class="px-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </p>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        :title="collapsed ? item.label : undefined"
                        :class="[
                            'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs font-medium transition-colors',
                            collapsed ? 'justify-center' : '',
                            isActive(item)
                                ? 'bg-primary-soft text-accent-foreground'
                                : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                        ]"
                    >
                        <component
                            :is="item.icon"
                            :class="[
                                'size-4 shrink-0',
                                isActive(item) ? 'text-primary' : '',
                            ]"
                            aria-hidden="true"
                        />
                        <span v-if="!collapsed" class="min-w-0 flex-1 truncate">
                            {{ item.label }}
                        </span>
                        <span
                            v-if="!collapsed && item.badge && item.badge > 0"
                            class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground"
                        >
                            {{ item.badge > 99 ? '99+' : item.badge }}
                        </span>
                    </Link>
                </div>
            </nav>

            <div class="border-t border-border p-3">
                <Button
                    variant="outline"
                    size="sm"
                    class="w-full justify-center gap-2 rounded-lg text-xs"
                    as-child
                >
                    <Link :href="home()">
                        <ArrowLeft class="size-3.5" aria-hidden="true" />
                        <span v-if="!collapsed">{{
                            t('admin.backToSite')
                        }}</span>
                    </Link>
                </Button>
            </div>
        </aside>

        <!-- Main Workspace Column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-border bg-card px-4 lg:px-6"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <!-- Mobile Sidebar Drawer -->
                    <Sheet v-model:open="mobileOpen">
                        <SheetTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-9 rounded-lg lg:hidden"
                                :aria-label="t('nav.openMobileMenu')"
                            >
                                <Menu class="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            class="flex w-72 flex-col p-0"
                        >
                            <SheetHeader
                                class="border-b border-border px-4 py-4 text-left"
                            >
                                <SheetTitle
                                    class="flex items-center gap-2 text-sm font-bold"
                                >
                                    <AppLogoIcon class="size-5 text-primary" />
                                    <span
                                        >{{ brandName }} ·
                                        {{ t('admin.centerTitle') }}</span
                                    >
                                </SheetTitle>
                                <SheetDescription class="sr-only">
                                    {{ t('admin.centerTitle') }}
                                </SheetDescription>
                            </SheetHeader>
                            <nav class="flex-1 space-y-4 overflow-y-auto p-3">
                                <div
                                    v-for="group in navGroups"
                                    :key="group.id"
                                    class="space-y-1"
                                >
                                    <p
                                        class="px-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        {{ group.label }}
                                    </p>
                                    <Link
                                        v-for="item in group.items"
                                        :key="item.href"
                                        :href="item.href"
                                        @click="mobileOpen = false"
                                        :class="[
                                            'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs font-medium',
                                            isActive(item)
                                                ? 'bg-primary-soft text-accent-foreground'
                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            class="size-4 shrink-0"
                                        />
                                        <span class="flex-1 truncate">{{
                                            item.label
                                        }}</span>
                                        <span
                                            v-if="item.badge && item.badge > 0"
                                            class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground"
                                        >
                                            {{ item.badge }}
                                        </span>
                                    </Link>
                                </div>
                            </nav>
                            <div class="border-t border-border p-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="w-full rounded-lg text-xs"
                                    as-child
                                >
                                    <Link
                                        :href="home()"
                                        @click="mobileOpen = false"
                                    >
                                        <ArrowLeft class="size-3.5" />
                                        <span>{{ t('admin.backToSite') }}</span>
                                    </Link>
                                </Button>
                            </div>
                        </SheetContent>
                    </Sheet>

                    <div v-if="breadcrumbs.length > 0" class="min-w-0 truncate">
                        <Breadcrumbs :breadcrumbs="breadcrumbs" />
                    </div>
                    <h2
                        v-else
                        class="truncate text-sm font-semibold text-foreground"
                    >
                        {{ t('admin.centerTitle') }}
                    </h2>
                </div>

                <!-- Quick Search -->
                <form
                    @submit.prevent="submitQuickSearch"
                    class="hidden max-w-sm flex-1 items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-1.5 focus-within:border-primary/50 focus-within:bg-card md:flex"
                >
                    <Search
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="quickQuery"
                        type="search"
                        :placeholder="t('admin.quickSearch')"
                        class="w-full bg-transparent text-xs text-foreground placeholder:text-muted-foreground focus:outline-none"
                    />
                </form>

                <!-- Right Controls -->
                <div class="flex items-center gap-2">
                    <LocaleSwitcher compact />
                    <ThemeToggle />

                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                class="h-9 gap-2 rounded-full px-2 pr-2.5 hover:bg-surface-hover"
                            >
                                <Avatar class="size-7 rounded-full">
                                    <AvatarImage
                                        v-if="user.avatar"
                                        :src="user.avatar"
                                        :alt="user.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-[11px] font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="hidden text-left sm:block">
                                    <p
                                        class="max-w-28 truncate text-xs leading-tight font-semibold"
                                    >
                                        {{ user.name }}
                                    </p>
                                    <p
                                        class="text-[10px] leading-tight text-muted-foreground"
                                    >
                                        {{ user.role_label }}
                                    </p>
                                </div>
                                <ChevronDown
                                    class="size-3.5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="w-60 rounded-xl"
                        >
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1440px] flex-1 p-4 lg:p-6">
                <slot />
            </main>
        </div>

        <Toaster />
    </div>
</template>
