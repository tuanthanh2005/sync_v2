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
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 lg:px-6"
            >
                <Link :href="home()" class="flex items-center">
                    <AppLogo />
                </Link>
                <div class="flex items-center gap-2">
                    <LocaleSwitcher compact />
                    <ThemeToggle />
                </div>
            </div>
        </header>

        <main
            class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-8 px-4 py-8 lg:grid-cols-12 lg:px-6 lg:py-12"
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
        <Toaster position="top-right" rich-colors />
    </div>
</template>
