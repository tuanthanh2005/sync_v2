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
            d="M33 5H15A8 8 0 0 0 15 21H17.2L22.6 15.6H15A2.6 2.6 0 0 1 15 10.4H27.6Z"
            fill="currentColor"
        />
        <path
            d="M7 35H25A8 8 0 0 0 25 19H22.8L17.4 24.4H25A2.6 2.6 0 0 1 25 29.6H12.4Z"
            fill="currentColor"
        />
    </svg>
</template>
