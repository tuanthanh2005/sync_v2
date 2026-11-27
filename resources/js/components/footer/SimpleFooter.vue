<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';

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
        :aria-label="t('accessibility.authFooter')"
        class="border-t border-border bg-card px-4 py-3.5"
    >
        <div
            class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 sm:flex-row lg:px-2"
        >
            <div
                class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5"
            >
                <p class="text-xs text-muted-foreground">
                    {{ copyrightLine }}
                </p>
                <FooterLinkList
                    v-if="footer.links.length > 0"
                    :items="footer.links"
                    variant="dots"
                />
                <a
                    v-if="footer.supportEmail"
                    :href="`mailto:${footer.supportEmail}`"
                    class="text-xs font-medium text-primary hover:underline"
                >
                    {{ footer.supportEmail }}
                </a>
            </div>

            <LocaleSwitcher compact />
        </div>
    </footer>
</template>
