<script setup lang="ts">
import { Bot } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        disclosure?: string | null;
        size?: 'sm' | 'md';
        label?: string | null;
    }>(),
    {
        disclosure: null,
        size: 'sm',
        label: null,
    },
);

const { t } = useI18n();

const displayLabel = computed(() => props.label || t('ai.badge.label'));
const tooltipText = computed(
    () => props.disclosure || t('ai.badge.defaultDisclosure'),
);
</script>

<template>
    <span
        :title="tooltipText"
        :aria-label="tooltipText"
        :class="[
            'inline-flex shrink-0 items-center gap-1 rounded-md border border-indigo-500/30 bg-indigo-500/10 font-semibold text-indigo-700 dark:border-indigo-400/30 dark:bg-indigo-500/15 dark:text-indigo-300',
            size === 'md' ? 'px-2 py-0.5 text-xs' : 'h-5 px-1.5 text-[10px]',
        ]"
    >
        <Bot
            :class="size === 'md' ? 'size-3.5' : 'size-3'"
            aria-hidden="true"
        />
        <span>{{ displayLabel }}</span>
    </span>
</template>
