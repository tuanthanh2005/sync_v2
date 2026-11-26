<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';

const { t } = useI18n();
const page = usePage();

const brandName = computed(() => page.props.name);
const footer = computed(() => page.props.footer);
</script>

<template>
    <footer
        :aria-label="t('accessibility.miniFooter')"
        class="rounded-xl border border-border bg-card p-4 shadow-xs"
    >
        <FooterLinkList
            v-if="footer.links.length > 0"
            :items="footer.links"
            variant="inline"
        />
        <p v-else class="text-[11px] leading-relaxed text-muted-foreground">
            {{ t('footer.noPublishedLinks') }}
        </p>

        <div
            v-if="footer.supportEmail"
            class="mt-2.5 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground"
        >
            <span>{{ t('footer.supportContact') }}:</span>
            <a
                :href="`mailto:${footer.supportEmail}`"
                class="font-medium text-primary hover:underline"
            >
                {{ footer.supportEmail }}
            </a>
        </div>

        <div
            class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3"
        >
            <LocaleSwitcher compact />
            <ThemeToggle />
        </div>

        <p class="mt-2.5 text-[11px] text-muted-foreground">
            {{ t('footer.copyright', { year: footer.year, brand: brandName }) }}
        </p>
    </footer>
</template>
