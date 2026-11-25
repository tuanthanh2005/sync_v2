<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import FullFooter from '@/components/footer/FullFooter.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, home, login, register } from '@/routes';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="flex min-h-[100dvh] flex-col bg-background text-foreground">
        <header
            class="safe-pt sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur-md"
        >
            <div
                class="mx-auto flex h-14 max-w-[1360px] items-center justify-between gap-2 px-3 sm:h-16 sm:gap-4 sm:px-4 lg:px-6"
            >
                <Link :href="home()" class="flex min-w-0 items-center">
                    <AppLogo />
                </Link>

                <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                    <LocaleSwitcher compact />
                    <ThemeToggle />

                    <template v-if="user">
                        <Button
                            size="sm"
                            class="min-h-10 rounded-lg sm:min-h-9"
                            as-child
                        >
                            <Link :href="dashboard()">{{
                                t('nav.dashboard')
                            }}</Link>
                        </Button>
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

        <main
            class="mx-auto w-full max-w-4xl flex-1 px-3 py-6 sm:px-4 sm:py-8 lg:px-6"
        >
            <slot />
        </main>

        <FullFooter />
        <Toaster />
    </div>
</template>
