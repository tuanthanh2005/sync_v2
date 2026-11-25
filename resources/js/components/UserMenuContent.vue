<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Palette, Settings, Shield, ShieldCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
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
        <div class="flex items-center gap-2 px-2 py-2 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="editProfile()"
                prefetch
            >
                <Settings class="mr-2 size-4" />
                {{ t('nav.profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="editSecurity()"
                prefetch
            >
                <ShieldCheck class="mr-2 size-4" />
                {{ t('nav.security') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="editAppearance()"
                prefetch
            >
                <Palette class="mr-2 size-4" />
                {{ t('nav.appearance') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="user.can?.accessAdmin" :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="adminOverview()"
                prefetch
            >
                <Shield class="mr-2 size-4" />
                {{ t('nav.adminOverview') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer text-destructive focus:text-destructive"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 size-4" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
