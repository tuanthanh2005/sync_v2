<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';
import { home } from '@/routes';

const { t } = useI18n();
const page = usePage();

const brandName = computed(() => page.props.name);
const footer = computed(() => page.props.footer);

const copyrightLine = computed(
    () =>
        footer.value.copyrightText ||
        t('footer.copyright', {
            year: footer.value.year,
            brand: brandName.value,
        }),
);
</script>

<template>
    <footer
        :aria-label="t('accessibility.footer')"
        class="border-t border-border bg-card text-card-foreground"
    >
        <div class="mx-auto max-w-[1360px] px-4 py-8 lg:px-6">
            <div class="grid gap-8 md:grid-cols-12">
                <div class="space-y-3 md:col-span-4">
                    <Link :href="home()" class="inline-flex items-center">
                        <AppLogo />
                    </Link>
                    <p
                        class="max-w-xs text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t('sidebar.guestSummary') }}
                    </p>
                    <div class="flex items-center gap-2 pt-1">
                        <LocaleSwitcher compact />
                        <ThemeToggle />
                    </div>
                </div>

                <div
                    v-if="footer.groups.length > 0"
                    class="grid gap-6 sm:grid-cols-2 md:col-span-8 lg:grid-cols-4"
                >
                    <div
                        v-for="group in footer.groups"
                        :key="group.key"
                        class="space-y-2.5"
                    >
                        <h3
                            class="text-[11px] font-semibold tracking-wider text-foreground uppercase"
                        >
                            {{ group.label }}
                        </h3>
                        <FooterLinkList
                            :items="group.items"
                            variant="stacked"
                        />
                    </div>
                </div>
            </div>

            <div
                class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5 text-xs text-muted-foreground"
            >
                <p>{{ copyrightLine }}</p>
                <div
                    v-if="footer.supportEmail || footer.contactInfo"
                    class="flex flex-wrap items-center gap-3"
                >
                    <span v-if="footer.contactInfo">
                        {{ footer.contactInfo }}
                    </span>
                    <a
                        v-if="footer.supportEmail"
                        :href="`mailto:${footer.supportEmail}`"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ footer.supportEmail }}
                    </a>
                </div>
            </div>
        </div>
    </footer>
</template>
