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
</script>

<template>
    <footer
        aria-label="Authentication Footer"
        class="border-t border-border bg-card px-4 py-4"
    >
        <div
            class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 sm:flex-row lg:px-2"
        >
            <div class="flex flex-wrap items-center justify-center gap-4">
                <p class="text-xs text-muted-foreground">
                    {{
                        t('footer.copyright', {
                            year: footer.year,
                            brand: brandName,
                        })
                    }}
                </p>
                <FooterLinkList
                    v-if="footer.links.length > 0"
                    :items="footer.links"
                    variant="inline"
                />
                <span
                    v-if="footer.supportEmail"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('footer.supportContact') }}:
                    <a
                        :href="`mailto:${footer.supportEmail}`"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ footer.supportEmail }}
                    </a>
                </span>
            </div>

            <LocaleSwitcher compact />
        </div>
    </footer>
</template>
