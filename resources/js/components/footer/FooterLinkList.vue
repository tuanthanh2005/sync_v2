<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { FooterLinkItem } from '@/types';

const { items, variant = 'inline' } = defineProps<{
    items: FooterLinkItem[];
    variant?: 'inline' | 'stacked' | 'dots';
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
                : variant === 'dots'
                  ? 'flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px] leading-relaxed text-muted-foreground'
                  : 'flex flex-wrap items-center gap-x-3 gap-y-1.5'
        "
    >
        <li
            v-for="(item, index) in items"
            :key="item.id"
            class="inline-flex items-center gap-1.5"
        >
            <a
                v-if="isExternalLink(item)"
                :href="item.href"
                :target="item.open_in_new_tab ? '_blank' : undefined"
                :rel="item.open_in_new_tab ? 'noopener noreferrer' : undefined"
                :class="[
                    'inline-flex items-center gap-1 text-muted-foreground transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    variant === 'dots' ? 'text-[11px]' : 'text-xs',
                ]"
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
                :class="[
                    'text-muted-foreground transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    variant === 'dots' ? 'text-[11px]' : 'text-xs',
                ]"
            >
                {{ item.label }}
            </Link>
            <span
                v-if="variant === 'dots' && index < items.length - 1"
                class="text-muted-foreground/50 select-none"
                aria-hidden="true"
            >
                ·
            </span>
        </li>
    </ul>
</template>
