<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Compass,
    Home,
    LogIn,
    Plus,
    User as UserIcon,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import SearchController from '@/actions/App/Http/Controllers/Social/SearchController';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMobileComposer } from '@/composables/useMobileComposer';
import { useMobileViewport } from '@/composables/useMobileViewport';
import { toUrl } from '@/lib/utils';
import { home, login } from '@/routes';
import { index as communitiesIndex } from '@/routes/communities';
import type { NavItem } from '@/types';

type BottomNavEntry =
    | {
          kind: 'link';
          key: string;
          title: string;
          href: NavItem['href'];
          icon: Component;
      }
    | {
          kind: 'compose';
          key: string;
          title: string;
          icon: Component;
      };

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { isKeyboardOpen } = useMobileViewport();
const { openMobileComposer } = useMobileComposer();

function isMobileItemActive(href: NavItem['href']): boolean {
    const targetUrl = toUrl(href);

    if (targetUrl === toUrl(home())) {
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

function handleComposeClick(): void {
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

const mobileItems = computed<BottomNavEntry[]>(() => {
    if (!user.value) {
        return [
            {
                kind: 'link',
                key: 'home',
                title: t('nav.home'),
                href: home(),
                icon: Home,
            },
            {
                kind: 'link',
                key: 'explore',
                title: t('nav.explore'),
                href: SearchController.url(),
                icon: Compass,
            },
            {
                kind: 'link',
                key: 'communities',
                title: t('nav.communities'),
                href: communitiesIndex(),
                icon: Users,
            },
            {
                kind: 'link',
                key: 'login',
                title: t('nav.login'),
                href: login(),
                icon: LogIn,
            },
        ];
    }

    return [
        {
            kind: 'link',
            key: 'home',
            title: t('nav.home'),
            href: home(),
            icon: Home,
        },
        {
            kind: 'link',
            key: 'explore',
            title: t('nav.explore'),
            href: SearchController.url(),
            icon: Compass,
        },
        {
            kind: 'compose',
            key: 'compose',
            title: t('nav.createPostShort'),
            icon: Plus,
        },
        {
            kind: 'link',
            key: 'communities',
            title: t('nav.communities'),
            href: communitiesIndex(),
            icon: Users,
        },
        {
            kind: 'link',
            key: 'profile',
            title: t('nav.profileShort'),
            href: ProfileViewController.me.url(),
            icon: UserIcon,
        },
    ];
});
</script>

<template>
    <nav
        v-show="!isKeyboardOpen"
        :aria-label="t('nav.mobileNavLabel')"
        class="safe-pb fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-md select-none lg:hidden"
    >
        <div
            class="mx-auto grid max-w-lg px-1"
            :style="{
                gridTemplateColumns: `repeat(${mobileItems.length}, minmax(0, 1fr))`,
            }"
        >
            <template v-for="item in mobileItems" :key="item.key">
                <button
                    v-if="item.kind === 'compose'"
                    type="button"
                    :aria-label="item.title"
                    class="flex min-h-[52px] flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-[10px] font-semibold text-primary transition-transform active:scale-95"
                    @click="handleComposeClick"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-xs"
                    >
                        <component
                            :is="item.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>
                </button>

                <Link
                    v-else
                    :href="item.href"
                    :aria-current="
                        isMobileItemActive(item.href) ? 'page' : undefined
                    "
                    :class="[
                        'relative flex min-h-[52px] min-w-0 flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-[10px] font-medium transition-colors active:scale-95',
                        isMobileItemActive(item.href)
                            ? 'font-semibold text-primary'
                            : 'text-muted-foreground hover:text-foreground',
                    ]"
                >
                    <span
                        v-if="isMobileItemActive(item.href)"
                        class="absolute top-0 h-0.5 w-8 rounded-full bg-primary"
                        aria-hidden="true"
                    />
                    <component
                        :is="item.icon"
                        class="size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="max-w-full truncate leading-tight">{{
                        item.title
                    }}</span>
                </Link>
            </template>
        </div>
    </nav>
</template>
