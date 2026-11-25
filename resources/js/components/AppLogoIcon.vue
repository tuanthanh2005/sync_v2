<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="none"
        :class="['text-primary', className]"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            d="M20 9.5L10.5 27.5M20 9.5L29.5 27.5M10.5 27.5H29.5"
            stroke="currentColor"
            stroke-width="3.2"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <circle cx="20" cy="9.5" r="4.5" fill="currentColor" />
        <circle cx="10.5" cy="27.5" r="4.5" fill="currentColor" />
        <circle
            cx="29.5"
            cy="27.5"
            r="4.5"
            fill="currentColor"
            fill-opacity="0.72"
        />
    </svg>
</template>
