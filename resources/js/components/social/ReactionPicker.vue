<script setup lang="ts">
import { Heart, Lightbulb, ThumbsUp } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { formatCompactNumber, formatNumber } from '@/lib/formatters';

export type SyncVietReactionType = 'like' | 'love' | 'insightful';

type ReactionDefinition = {
    type: SyncVietReactionType;
    labelKey: string;
    icon: Component;
    activeTextClass: string;
    hoverBgClass: string;
    iconBgClass: string;
};

const REACTIONS: ReactionDefinition[] = [
    {
        type: 'like',
        labelKey: 'post.like',
        icon: ThumbsUp,
        activeTextClass: 'text-primary',
        hoverBgClass: 'hover:bg-primary-soft',
        iconBgClass: 'bg-primary-soft text-primary',
    },
    {
        type: 'love',
        labelKey: 'post.love',
        icon: Heart,
        activeTextClass: 'text-destructive',
        hoverBgClass: 'hover:bg-destructive-soft',
        iconBgClass: 'bg-destructive-soft text-destructive',
    },
    {
        type: 'insightful',
        labelKey: 'post.insightful',
        icon: Lightbulb,
        activeTextClass: 'text-warning',
        hoverBgClass: 'hover:bg-warning-soft',
        iconBgClass: 'bg-warning-soft text-warning',
    },
];

const props = withDefaults(
    defineProps<{
        postId: number;
        activeReaction: SyncVietReactionType | null;
        count: number;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const emit = defineEmits<{
    select: [type: SyncVietReactionType];
}>();

const { t, locale } = useI18n();

const triggerRef = ref<HTMLElement | null>(null);
const pickerRef = ref<HTMLElement | null>(null);
const optionRefs = ref<Array<HTMLButtonElement | null>>([]);
const pickerOpen = ref(false);
const pickerStyle = ref<{ top: string; left: string }>({
    top: '0px',
    left: '0px',
});
const hoveredType = ref<SyncVietReactionType | null>(null);

let openTimer: ReturnType<typeof setTimeout> | null = null;
let closeTimer: ReturnType<typeof setTimeout> | null = null;
let longPressTimer: ReturnType<typeof setTimeout> | null = null;
let pointerStart = { x: 0, y: 0 };
let suppressNextClick = false;
let isTouchPress = false;

const pickerId = computed(() => `sv-reaction-picker-${props.postId}`);

const activeDef = computed<ReactionDefinition>(() => {
    const found = REACTIONS.find((r) => r.type === props.activeReaction);

    return found ?? REACTIONS[0];
});

const buttonAriaLabel = computed<string>(() => {
    const label = t(activeDef.value.labelKey);

    return t('post.reactionTriggerAria', {
        reaction: label,
        count: formatNumber(props.count, locale.value),
    });
});

function clearTimers(): void {
    if (openTimer !== null) {
        clearTimeout(openTimer);
        openTimer = null;
    }
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
    if (longPressTimer !== null) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    }
}

function positionPicker(): void {
    const triggerEl = triggerRef.value;
    if (!triggerEl || typeof window === 'undefined') {
        return;
    }

    const rect = triggerEl.getBoundingClientRect();
    const pickerWidth = pickerRef.value?.offsetWidth || 168;
    const pickerHeight = pickerRef.value?.offsetHeight || 48;
    const margin = 12;

    const fitsAbove = rect.top >= pickerHeight + margin;
    const top = fitsAbove
        ? Math.max(margin, rect.top - pickerHeight - 8)
        : Math.min(window.innerHeight - pickerHeight - margin, rect.bottom + 8);

    const isRtl = document.documentElement.dir === 'rtl';
    let left = isRtl ? rect.right - pickerWidth : rect.left;
    left = Math.max(
        margin,
        Math.min(window.innerWidth - pickerWidth - margin, left),
    );

    pickerStyle.value = {
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
    };
}

function openPicker(focusFirst = false): void {
    if (props.disabled) {
        return;
    }

    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }

    pickerOpen.value = true;
    void nextTick(() => {
        positionPicker();
        if (focusFirst) {
            optionRefs.value[0]?.focus();
        }
    });
    attachDismissListeners();
}

function closePicker(restoreFocus = false): void {
    clearTimers();
    pickerOpen.value = false;
    hoveredType.value = null;
    detachDismissListeners();

    if (restoreFocus) {
        triggerRef.value?.focus();
    }
}

function scheduleClose(): void {
    if (openTimer !== null) {
        clearTimeout(openTimer);
        openTimer = null;
    }
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
    }
    closeTimer = setTimeout(() => {
        closePicker(false);
    }, 220);
}

function cancelClose(): void {
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
}

function onPointerEnter(event: PointerEvent): void {
    if (event.pointerType !== 'mouse' || props.disabled) {
        return;
    }

    cancelClose();
    if (pickerOpen.value) {
        return;
    }

    openTimer = setTimeout(() => {
        openPicker(false);
    }, 350);
}

function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType !== 'mouse') {
        return;
    }

    scheduleClose();
}

function onPointerDown(event: PointerEvent): void {
    if (event.pointerType === 'mouse' || props.disabled) {
        isTouchPress = false;
        return;
    }

    isTouchPress = true;
    suppressNextClick = false;
    pointerStart = { x: event.clientX, y: event.clientY };

    if (longPressTimer !== null) {
        clearTimeout(longPressTimer);
    }

    longPressTimer = setTimeout(() => {
        longPressTimer = null;
        suppressNextClick = true;
        openPicker(false);
    }, 450);
}

function onPointerMove(event: PointerEvent): void {
    if (!isTouchPress || longPressTimer === null) {
        return;
    }

    const dx = Math.abs(event.clientX - pointerStart.x);
    const dy = Math.abs(event.clientY - pointerStart.y);

    // Cancel long-press immediately if user is scrolling or swiping.
    if (dx > 10 || dy > 10) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    }
}

function onPointerUpOrCancel(): void {
    if (longPressTimer !== null) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    }
    isTouchPress = false;
}

function onMainClick(): void {
    if (suppressNextClick) {
        suppressNextClick = false;
        return;
    }

    if (props.disabled) {
        return;
    }

    closePicker(false);
    emit('select', props.activeReaction || 'like');
}

function onTriggerKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        openPicker(true);
    } else if (event.key === 'Escape' && pickerOpen.value) {
        event.preventDefault();
        closePicker(true);
    }
}

function onOptionKeydown(event: KeyboardEvent, index: number): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
        return;
    }

    if (event.key === 'ArrowRight') {
        event.preventDefault();
        optionRefs.value[(index + 1) % REACTIONS.length]?.focus();
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        optionRefs.value[
            (index - 1 + REACTIONS.length) % REACTIONS.length
        ]?.focus();
    } else if (event.key === 'Tab') {
        closePicker(false);
    }
}

function pickReaction(type: SyncVietReactionType): void {
    closePicker(false);
    emit('select', type);
}

function handleOutsidePointerDown(event: PointerEvent): void {
    const target = event.target as Node | null;
    if (!target) {
        return;
    }

    if (
        triggerRef.value?.contains(target) ||
        pickerRef.value?.contains(target)
    ) {
        return;
    }

    closePicker(false);
}

function handleWindowScrollOrResize(): void {
    if (pickerOpen.value) {
        positionPicker();
    }
}

function attachDismissListeners(): void {
    if (typeof window === 'undefined') {
        return;
    }
    window.addEventListener('pointerdown', handleOutsidePointerDown, {
        capture: true,
    });
    window.addEventListener('scroll', handleWindowScrollOrResize, {
        passive: true,
        capture: true,
    });
    window.addEventListener('resize', handleWindowScrollOrResize, {
        passive: true,
    });
}

function detachDismissListeners(): void {
    if (typeof window === 'undefined') {
        return;
    }
    window.removeEventListener('pointerdown', handleOutsidePointerDown, {
        capture: true,
    });
    window.removeEventListener('scroll', handleWindowScrollOrResize, {
        capture: true,
    });
    window.removeEventListener('resize', handleWindowScrollOrResize);
}

onBeforeUnmount(() => {
    clearTimers();
    detachDismissListeners();
});
</script>

<template>
    <div ref="triggerRef" class="inline-flex items-center">
        <Button
            type="button"
            variant="ghost"
            size="sm"
            data-test="reaction-trigger"
            :disabled="disabled"
            :aria-label="buttonAriaLabel"
            :aria-haspopup="'menu'"
            :aria-expanded="pickerOpen"
            :aria-controls="pickerOpen ? pickerId : undefined"
            :title="t('post.reactionTriggerHint')"
            :class="[
                'min-h-10 gap-1.5 rounded-lg px-2.5 text-xs transition-colors select-none [-webkit-touch-callout:none] sm:min-h-9',
                activeReaction
                    ? 'bg-primary-soft/80 font-semibold text-accent-foreground hover:bg-primary-soft'
                    : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
            ]"
            @click="onMainClick"
            @pointerenter="onPointerEnter"
            @pointerleave="onPointerLeave"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUpOrCancel"
            @pointercancel="onPointerUpOrCancel"
            @contextmenu.prevent="openPicker(false)"
            @keydown="onTriggerKeydown"
        >
            <component
                :is="activeDef.icon"
                :class="[
                    'size-4 shrink-0',
                    activeReaction ? activeDef.activeTextClass : '',
                ]"
                aria-hidden="true"
            />
            <span class="tabular-nums">{{
                formatCompactNumber(count, locale)
            }}</span>
        </Button>

        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
                enter-from-class="opacity-0 scale-95 translate-y-1"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-active-class="transition duration-120 ease-in motion-reduce:transition-none"
                leave-from-class="opacity-100 scale-100 translate-y-0"
                leave-to-class="opacity-0 scale-95 translate-y-1"
            >
                <div
                    v-if="pickerOpen"
                    :id="pickerId"
                    ref="pickerRef"
                    role="menu"
                    :aria-label="t('accessibility.chooseReaction')"
                    :style="pickerStyle"
                    class="fixed z-50 flex items-center gap-1.5 rounded-full border border-border bg-card px-2 py-1.5 shadow-lg"
                    @pointerenter="cancelClose"
                    @pointerleave="onPointerLeave"
                >
                    <button
                        v-for="(item, idx) in REACTIONS"
                        :key="item.type"
                        :ref="
                            (el) => {
                                optionRefs[idx] =
                                    el as HTMLButtonElement | null;
                            }
                        "
                        type="button"
                        role="menuitem"
                        :aria-label="t(item.labelKey)"
                        :title="t(item.labelKey)"
                        :class="[
                            'group/rx relative flex size-9 items-center justify-center rounded-full transition-transform duration-150 hover:scale-115 focus-visible:scale-110 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none motion-reduce:transition-none',
                            activeReaction === item.type
                                ? `${item.iconBgClass} ring-2 ring-primary/40`
                                : `${item.hoverBgClass} text-muted-foreground`,
                        ]"
                        @click="pickReaction(item.type)"
                        @pointerenter="hoveredType = item.type"
                        @pointerleave="hoveredType = null"
                        @keydown="onOptionKeydown($event, idx)"
                    >
                        <component
                            :is="item.icon"
                            :class="['size-4.5', item.activeTextClass]"
                            aria-hidden="true"
                        />
                        <span
                            v-if="hoveredType === item.type"
                            class="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 rounded-md bg-foreground px-2 py-0.5 text-[10px] font-medium whitespace-nowrap text-background shadow-xs"
                        >
                            {{ t(item.labelKey) }}
                        </span>
                    </button>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
