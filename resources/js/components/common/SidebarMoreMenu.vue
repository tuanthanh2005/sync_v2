<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    ExternalLink,
    Flag,
    Globe,
    HelpCircle,
    Image as ImageIcon,
    Mail,
    Megaphone,
    MoreHorizontal,
    Palette,
    ReceiptText,
    Settings,
    Shield,
    ShieldCheck,
    Sparkles,
    Wallet as WalletIcon,
    Zap,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { formatVnd } from '@/lib/currency';
import { overview as adminOverview } from '@/routes/admin';
import { edit as editAppearance } from '@/routes/appearance';
import {
    advertising as advertisingRoute,
    banner as bannerRoute,
    boost as boostRoute,
    services as servicesRoute,
} from '@/routes/monetization';
import { index as paymentsIndex } from '@/routes/payments';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as walletIndex } from '@/routes/wallet';
import type { FooterLinkItem } from '@/types';

type MenuGroupId = 'ads' | 'settings' | 'help';

withDefaults(
    defineProps<{
        active?: boolean;
    }>(),
    {
        active: false,
    },
);

const { t, locale } = useI18n();
const page = usePage();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const open = ref(false);
const user = computed(() => page.props.auth.user);
const footer = computed(() => page.props.footer);
const isRtl = computed(() => Boolean(page.props.isRtl));
const walletBalance = computed(() => page.props.walletBalance ?? 0);
const lang = computed<'vi' | 'en'>(() => (locale.value === 'en' ? 'en' : 'vi'));

const expandedGroup = ref<MenuGroupId | null>('ads');

function resolveDefaultGroup(): MenuGroupId {
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
    if (component.startsWith('policies/')) {
        return 'help';
    }

    return user.value ? 'ads' : 'help';
}

watch(open, (isOpen) => {
    if (isOpen) {
        expandedGroup.value = resolveDefaultGroup();
    }
});

function toggleGroup(group: MenuGroupId): void {
    expandedGroup.value = expandedGroup.value === group ? null : group;
}

function closeMenu(): void {
    open.value = false;
}

const helpLinks = computed<FooterLinkItem[]>(() => {
    const allLinks = footer.value?.links ?? [];
    if (allLinks.length === 0) {
        return [];
    }

    const prioritySlugs = [
        'help-center',
        'contact-us',
        'community-guidelines',
        'privacy-policy',
        'terms-of-service',
    ];
    const sorted = [...allLinks].sort((a, b) => {
        const aIdx = prioritySlugs.findIndex((slug) => a.href.includes(slug));
        const bIdx = prioritySlugs.findIndex((slug) => b.href.includes(slug));
        const aScore = aIdx === -1 ? 99 : aIdx;
        const bScore = bIdx === -1 ? 99 : bIdx;

        return aScore - bScore;
    });

    return sorted.slice(0, 5);
});

const hasContactLink = computed(() =>
    helpLinks.value.some((item) => item.href.includes('contact')),
);

function isExternalLink(item: FooterLinkItem): boolean {
    return (
        item.open_in_new_tab ||
        item.href.startsWith('http://') ||
        item.href.startsWith('https://')
    );
}
</script>

<template>
    <DropdownMenu v-model:open="open" :modal="false">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                data-test="sidebar-more-trigger"
                :aria-expanded="open"
                :aria-label="t('nav.more')"
                :title="t('nav.more')"
                :class="[
                    'flex h-11 w-full items-center gap-3 rounded-xl px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:justify-center md:px-0 lg:justify-start lg:px-3',
                    active || open
                        ? 'bg-primary-soft font-semibold text-accent-foreground'
                        : 'text-foreground/85 hover:bg-surface-hover hover:text-foreground',
                ]"
            >
                <MoreHorizontal
                    :class="[
                        'size-5 shrink-0',
                        active || open
                            ? 'text-primary'
                            : 'text-muted-foreground',
                    ]"
                    aria-hidden="true"
                />
                <span
                    class="hidden min-w-0 flex-1 truncate text-start lg:inline"
                >
                    {{ t('nav.more') }}
                </span>
                <ChevronRight
                    :class="[
                        'hidden size-4 shrink-0 text-muted-foreground transition-transform duration-150 lg:inline',
                        isRtl ? 'rotate-180' : '',
                        open ? 'text-primary' : '',
                    ]"
                    aria-hidden="true"
                />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            :side="isRtl ? 'left' : 'right'"
            align="end"
            :side-offset="10"
            class="w-72 rounded-2xl border border-border bg-popover p-2 shadow-lg"
            data-test="sidebar-more-popover"
        >
            <AutoHideScrollArea
                class="max-h-[min(76dvh,480px)] space-y-1 pe-0.5"
            >
                <!-- Group 1: Advertising Center -->
                <div class="rounded-xl">
                    <button
                        type="button"
                        :aria-expanded="expandedGroup === 'ads'"
                        class="flex min-h-10 w-full items-center justify-between gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click.stop="toggleGroup('ads')"
                    >
                        <span class="flex min-w-0 items-center gap-2.5">
                            <Megaphone
                                class="size-4.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                t('nav.advertisingCenter')
                            }}</span>
                        </span>
                        <ChevronDown
                            :class="[
                                'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                expandedGroup === 'ads' ? 'rotate-180' : '',
                            ]"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-show="expandedGroup === 'ads'"
                        class="mt-0.5 space-y-0.5 ps-2 pe-1 pb-1"
                    >
                        <Link
                            :href="advertisingRoute()"
                            @click="closeMenu"
                            :class="[
                                'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                isCurrentUrl(advertisingRoute())
                                    ? 'bg-primary-soft font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                            ]"
                        >
                            <Sparkles
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('nav.advertisingOverview')
                            }}</span>
                        </Link>
                        <Link
                            :href="boostRoute()"
                            @click="closeMenu"
                            :class="[
                                'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                isCurrentOrParentUrl(boostRoute())
                                    ? 'bg-primary-soft font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                            ]"
                        >
                            <Zap class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('monetization.services.boostTitle')
                            }}</span>
                        </Link>
                        <Link
                            :href="bannerRoute()"
                            @click="closeMenu"
                            :class="[
                                'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                isCurrentOrParentUrl(bannerRoute())
                                    ? 'bg-primary-soft font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                            ]"
                        >
                            <ImageIcon
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('monetization.services.bannerTitle')
                            }}</span>
                        </Link>

                        <template v-if="user">
                            <Link
                                :href="walletIndex()"
                                @click="closeMenu"
                                :class="[
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                    isCurrentOrParentUrl(walletIndex())
                                        ? 'bg-primary-soft font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <WalletIcon
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    t('monetization.wallet.title')
                                }}</span>
                                <span
                                    class="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-foreground tabular-nums"
                                >
                                    {{ formatVnd(walletBalance, lang) }}
                                </span>
                            </Link>
                            <Link
                                :href="paymentsIndex()"
                                @click="closeMenu"
                                :class="[
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                    isCurrentOrParentUrl(paymentsIndex())
                                        ? 'bg-primary-soft font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <ReceiptText
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    t('monetization.payments.title')
                                }}</span>
                            </Link>
                        </template>
                    </div>
                </div>

                <!-- Group 2: Settings & Privacy -->
                <div class="rounded-xl">
                    <button
                        type="button"
                        :aria-expanded="expandedGroup === 'settings'"
                        class="flex min-h-10 w-full items-center justify-between gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click.stop="toggleGroup('settings')"
                    >
                        <span class="flex min-w-0 items-center gap-2.5">
                            <Settings
                                class="size-4.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                t('nav.settingsPrivacy')
                            }}</span>
                        </span>
                        <ChevronDown
                            :class="[
                                'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                expandedGroup === 'settings'
                                    ? 'rotate-180'
                                    : '',
                            ]"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-show="expandedGroup === 'settings'"
                        class="mt-0.5 space-y-0.5 ps-2 pe-1 pb-1"
                    >
                        <template v-if="user">
                            <Link
                                :href="editProfile()"
                                @click="closeMenu"
                                :class="[
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                    isCurrentOrParentUrl(editProfile())
                                        ? 'bg-primary-soft font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <Settings
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    t('nav.profile')
                                }}</span>
                            </Link>
                            <Link
                                :href="editSecurity()"
                                @click="closeMenu"
                                :class="[
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                    isCurrentOrParentUrl(editSecurity())
                                        ? 'bg-primary-soft font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <ShieldCheck
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    t('nav.security')
                                }}</span>
                                <span
                                    v-if="!user.two_factor_enabled"
                                    class="size-2 shrink-0 rounded-full bg-warning"
                                    :title="t('sidebar.enable2fa')"
                                />
                            </Link>
                            <Link
                                :href="editAppearance()"
                                @click="closeMenu"
                                :class="[
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                                    isCurrentOrParentUrl(editAppearance())
                                        ? 'bg-primary-soft font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                ]"
                            >
                                <Palette
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    t('nav.appearance')
                                }}</span>
                            </Link>
                        </template>

                        <div
                            v-else
                            class="flex min-h-9 items-center justify-between gap-2 rounded-lg px-2.5 py-1 text-sm text-muted-foreground"
                        >
                            <span class="flex items-center gap-2.5">
                                <Palette
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{{ t('nav.appearance') }}</span>
                            </span>
                            <ThemeToggle />
                        </div>

                        <div
                            class="flex min-h-9 items-center justify-between gap-2 rounded-lg px-2.5 py-1 text-sm text-muted-foreground"
                            @click.stop
                        >
                            <span class="flex min-w-0 items-center gap-2.5">
                                <Globe
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    t('nav.language')
                                }}</span>
                            </span>
                            <LocaleSwitcher compact />
                        </div>
                    </div>
                </div>

                <!-- Group 3: Help & Support -->
                <div class="rounded-xl">
                    <button
                        type="button"
                        :aria-expanded="expandedGroup === 'help'"
                        class="flex min-h-10 w-full items-center justify-between gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click.stop="toggleGroup('help')"
                    >
                        <span class="flex min-w-0 items-center gap-2.5">
                            <HelpCircle
                                class="size-4.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                t('nav.helpSupport')
                            }}</span>
                        </span>
                        <ChevronDown
                            :class="[
                                'size-4 shrink-0 text-muted-foreground transition-transform duration-150',
                                expandedGroup === 'help' ? 'rotate-180' : '',
                            ]"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-show="expandedGroup === 'help'"
                        class="mt-0.5 space-y-0.5 ps-2 pe-1 pb-1"
                    >
                        <template v-for="item in helpLinks" :key="item.id">
                            <a
                                v-if="isExternalLink(item)"
                                :href="item.href"
                                :target="
                                    item.open_in_new_tab ? '_blank' : undefined
                                "
                                :rel="
                                    item.open_in_new_tab
                                        ? 'noopener noreferrer'
                                        : undefined
                                "
                                class="flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                                @click="closeMenu"
                            >
                                <HelpCircle
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    item.label
                                }}</span>
                                <ExternalLink
                                    class="size-3.5 shrink-0 opacity-60"
                                    aria-hidden="true"
                                />
                            </a>
                            <Link
                                v-else
                                :href="item.href"
                                @click="closeMenu"
                                class="flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                <HelpCircle
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    item.label
                                }}</span>
                            </Link>
                        </template>

                        <a
                            v-if="footer.supportEmail"
                            :href="`mailto:${footer.supportEmail}`"
                            class="flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                            @click="closeMenu"
                        >
                            <Flag class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('nav.reportIssue')
                            }}</span>
                        </a>

                        <a
                            v-if="footer.supportEmail && !hasContactLink"
                            :href="`mailto:${footer.supportEmail}`"
                            class="flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                            @click="closeMenu"
                        >
                            <Mail class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('nav.contactSupport')
                            }}</span>
                        </a>

                        <Link
                            :href="servicesRoute()"
                            @click="closeMenu"
                            class="flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground"
                        >
                            <Sparkles
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                t('monetization.services.pageTitle')
                            }}</span>
                        </Link>
                    </div>
                </div>

                <!-- Group 4: Administration (only for authorized staff) -->
                <template v-if="user && user.can?.accessAdmin">
                    <div class="my-1 border-t border-border/70" />
                    <Link
                        :href="adminOverview()"
                        @click="closeMenu"
                        data-test="sidebar-more-admin"
                        class="flex min-h-10 items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <Shield
                            class="size-4.5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            t('nav.adminOverview')
                        }}</span>
                    </Link>
                </template>
            </AutoHideScrollArea>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
