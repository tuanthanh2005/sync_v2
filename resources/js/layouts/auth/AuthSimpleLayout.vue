<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldCheck, Sparkles, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import SimpleFooter from '@/components/footer/SimpleFooter.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const { t } = useI18n();
const page = usePage();
const brandName = computed(() => page.props.name);
</script>

<template>
    <div class="flex min-h-[100dvh] flex-col bg-background text-foreground">
        <header class="safe-pt border-b border-border bg-card">
            <div
                class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-2 px-3 sm:h-16 sm:px-4 lg:px-6"
            >
                <Link :href="home()" class="flex min-w-0 items-center">
                    <AppLogo />
                </Link>
                <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                    <LocaleSwitcher compact />
                    <ThemeToggle />
                </div>
            </div>
        </header>

        <main
            class="mx-auto grid w-full max-w-6xl min-w-0 flex-1 items-center gap-6 px-3 py-6 sm:px-4 sm:py-8 lg:grid-cols-12 lg:gap-8 lg:px-6 lg:py-12"
        >
            <section
                class="hidden space-y-6 rounded-xl border border-border bg-card p-8 lg:col-span-5 lg:block"
            >
                <div
                    class="inline-flex items-center gap-2 rounded-full bg-primary-soft px-3 py-1 text-xs font-medium text-accent-foreground"
                >
                    <Sparkles
                        class="size-3.5 text-primary"
                        aria-hidden="true"
                    />
                    <span>{{ brandName }}</span>
                </div>

                <h2 class="text-2xl leading-snug font-bold text-foreground">
                    {{ t('auth.headline') }}
                </h2>

                <p class="text-sm leading-relaxed text-muted-foreground">
                    {{ t('auth.description') }}
                </p>

                <div class="space-y-3 pt-2 text-sm text-foreground">
                    <div class="flex items-start gap-3">
                        <ShieldCheck
                            class="mt-0.5 size-4.5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <span>{{ t('auth.highlightSecurity') }}</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <Users
                            class="mt-0.5 size-4.5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <span>{{ t('auth.highlightCommunity') }}</span>
                    </div>
                </div>
            </section>

            <section class="mx-auto w-full max-w-md lg:col-span-7">
                <div class="rounded-xl border border-border bg-card p-6 sm:p-8">
                    <div class="mb-6 space-y-1.5 text-center sm:text-left">
                        <h1
                            class="text-xl font-bold tracking-tight text-foreground"
                        >
                            {{ title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </section>
        </main>

        <SimpleFooter />
        <Toaster />
    </div>
</template>
