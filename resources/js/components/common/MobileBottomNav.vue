<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Home,
    LayoutGrid,
    LogIn,
    MessageSquare,
    User as UserIcon,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import { index as communitiesIndex } from '@/routes/communities';
import { index as messagesIndex } from '@/routes/messages';
import type { NavItem } from '@/types';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const unreadMessages = computed(() => page.props.unreadMessagesCount ?? 0);
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

function isMobileItemActive(item: NavItem): boolean {
    const targetUrl = toUrl(item.href);

    if (targetUrl === toUrl(home())) {
        return isCurrentUrl(targetUrl);
    }

    return isCurrentOrParentUrl(targetUrl);
}

const mobileItems = computed<Array<NavItem & { badge?: number }>>(() => {
    if (!user.value) {
        return [
            { title: t('nav.home'), href: home(), icon: Home },
            {
                title: t('nav.communities'),
                href: communitiesIndex(),
                icon: Users,
            },
            { title: t('nav.login'), href: login(), icon: LogIn },
            { title: t('nav.register'), href: register(), icon: UserPlus },
        ];
    }

    return [
        { title: t('nav.home'), href: home(), icon: Home },
        { title: t('nav.communities'), href: communitiesIndex(), icon: Users },
        {
            title: t('nav.messages'),
            href: messagesIndex(),
            icon: MessageSquare,
            badge: unreadMessages.value,
        },
        {
            title: t('nav.profileShort'),
            href: ProfileViewController.me.url(),
            icon: UserIcon,
        },
        {
            title: t('nav.workspaceShort'),
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];
});
</script>

<template>
    <nav
        :aria-label="t('nav.mobileNavLabel')"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card lg:hidden"
    >
        <div
            class="mx-auto grid max-w-lg"
            :style="{
                gridTemplateColumns: `repeat(${mobileItems.length}, minmax(0, 1fr))`,
            }"
        >
            <Link
                v-for="item in mobileItems"
                :key="item.title"
                :href="item.href"
                :class="[
                    'relative flex min-h-14 flex-col items-center justify-center gap-1 px-2 py-1.5 text-[11px] font-medium transition-colors',
                    isMobileItemActive(item)
                        ? 'text-primary'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
            >
                <div class="relative">
                    <component
                        :is="item.icon"
                        class="size-4.5"
                        aria-hidden="true"
                    />
                    <span
                        v-if="item.badge && item.badge > 0"
                        class="absolute -top-1.5 -right-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[9px] font-bold text-destructive-foreground"
                    >
                        {{ item.badge > 99 ? '99+' : item.badge }}
                    </span>
                </div>
                <span class="truncate">{{ item.title }}</span>
            </Link>
        </div>
    </nav>
</template>
