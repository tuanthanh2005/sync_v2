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
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="sticky top-0 z-40 h-16 border-b border-border bg-card">
            <div
                class="mx-auto flex h-full max-w-[1360px] items-center justify-between gap-4 px-4 lg:px-6"
            >
                <Link :href="home()" class="flex items-center">
                    <AppLogo />
                </Link>

                <div class="flex items-center gap-2">
                    <LocaleSwitcher compact />
                    <ThemeToggle />

                    <template v-if="user">
                        <Button size="sm" class="rounded-lg" as-child>
                            <Link :href="dashboard()">{{
                                t('nav.dashboard')
                            }}</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="rounded-lg"
                            as-child
                        >
                            <Link :href="login()">{{ t('nav.login') }}</Link>
                        </Button>
                        <Button size="sm" class="rounded-lg" as-child>
                            <Link :href="register()">{{
                                t('nav.register')
                            }}</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-8 lg:px-6">
            <slot />
        </main>

        <FullFooter />
        <Toaster position="top-right" rich-colors />
    </div>
</template>
