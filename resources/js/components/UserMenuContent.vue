<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    LogOut,
    Megaphone,
    Palette,
    Settings,
    Shield,
    ShieldCheck,
    User as UserIcon,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { edit as editAppearance } from '@/routes/appearance';
import { advertising as advertisingRoute } from '@/routes/monetization';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { User } from '@/types';

type Props = {
    user: User;
};

const { t } = useI18n();

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-2 py-2 text-start text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="ProfileViewController.me.url()"
                prefetch
            >
                <UserIcon class="me-2 size-4" />
                {{ t('nav.publicProfile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="editProfile()"
                prefetch
            >
                <Settings class="me-2 size-4" />
                {{ t('nav.profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="editSecurity()"
                prefetch
            >
                <ShieldCheck class="me-2 size-4" />
                {{ t('nav.security') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="editAppearance()"
                prefetch
            >
                <Palette class="me-2 size-4" />
                {{ t('nav.appearanceLanguage') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="advertisingRoute()"
                prefetch
            >
                <Megaphone class="me-2 size-4" />
                {{ t('nav.advertisingCenter') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="user.can?.accessAdmin" :as-child="true">
            <Link
                class="flex w-full cursor-pointer items-center"
                :href="adminOverview()"
                prefetch
            >
                <Shield class="me-2 size-4" />
                {{ t('nav.adminOverview') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="flex w-full cursor-pointer items-center text-destructive focus:text-destructive"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="me-2 size-4" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
