<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed, ref } from 'vue';
import {
    useAutoHideScrollbar,
    type ScrollbarOrientation,
    type ScrollbarSize,
} from '@/composables/useAutoHideScrollbar';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        as?: 'div' | 'nav' | 'section';
        hideDelay?: number;
        size?: ScrollbarSize;
        orientation?: ScrollbarOrientation;
        autoHide?: boolean;
        alwaysVisible?: boolean;
        nativeMode?: boolean;
        overscrollContain?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        as: 'div',
        hideDelay: 3000,
        size: 'sm',
        orientation: 'vertical',
        autoHide: true,
        alwaysVisible: false,
        nativeMode: false,
        overscrollContain: true,
    },
);

const containerRef = ref<HTMLElement | null>(null);

const { show, hideNow, resetTimer } = useAutoHideScrollbar(containerRef, {
    hideDelay: props.hideDelay,
    size: props.size,
    orientation: props.orientation,
    autoHide: props.autoHide,
    alwaysVisible: props.alwaysVisible,
    nativeMode: props.nativeMode,
});

const overflowClass = computed(() => {
    switch (props.orientation) {
        case 'horizontal':
            return 'overflow-x-auto overflow-y-hidden';
        case 'both':
            return 'overflow-auto';
        default:
            return 'overflow-y-auto overflow-x-hidden';
    }
});

defineExpose({
    el: containerRef,
    show,
    hideNow,
    resetTimer,
});
</script>

<template>
    <component
        :is="props.as"
        ref="containerRef"
        :class="
            cn(
                'sv-scrollbar min-h-0',
                overflowClass,
                props.overscrollContain && 'overscroll-contain',
                props.class,
            )
        "
    >
        <slot />
    </component>
</template>
