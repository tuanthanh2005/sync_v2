<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { FooterLinkItem } from '@/types';

const { items, variant = 'inline' } = defineProps<{
    items: FooterLinkItem[];
    variant?: 'inline' | 'stacked';
}>();

const { t } = useI18n();

function isExternalLink(item: FooterLinkItem): boolean {
    return (
        item.open_in_new_tab ||
        item.href.startsWith('http://') ||
        item.href.startsWith('https://')
    );
}
</script>

<template>
    <ul
        :class="
            variant === 'stacked'
                ? 'flex flex-col gap-2'
                : 'flex flex-wrap items-center gap-x-3 gap-y-1.5'
        "
    >
        <li v-for="item in items" :key="item.id">
            <a
                v-if="isExternalLink(item)"
                :href="item.href"
                :target="item.open_in_new_tab ? '_blank' : undefined"
                :rel="item.open_in_new_tab ? 'noopener noreferrer' : undefined"
                class="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span>{{ item.label }}</span>
                <ExternalLink
                    v-if="item.open_in_new_tab"
                    class="size-3"
                    :aria-label="t('footer.openInNewTab')"
                />
            </a>
            <Link
                v-else
                :href="item.href"
                class="text-xs text-muted-foreground transition-colors hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ item.label }}
            </Link>
        </li>
    </ul>
</template>
