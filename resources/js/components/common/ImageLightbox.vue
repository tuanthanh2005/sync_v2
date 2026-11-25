<script setup lang="ts">
import { ChevronLeft, ChevronRight, X, ZoomIn, ZoomOut } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';

export type LightboxImage = {
    id: number | string;
    url: string;
    alt?: string;
};

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        images: LightboxImage[];
        initialIndex?: number;
    }>(),
    {
        initialIndex: 0,
    },
);

const currentIndex = ref(0);
const scale = ref(1);
const translateX = ref(0);
const translateY = ref(0);

let touchStartX = 0;
let touchStartY = 0;
let initialPinchDistance = 0;
let initialPinchScale = 1;
let lastTapTime = 0;
let pushedHistory = false;
let previousBodyOverflow = '';

function resetZoom(): void {
    scale.value = 1;
    translateX.value = 0;
    translateY.value = 0;
}

function goPrev(): void {
    if (props.images.length <= 1) {
        return;
    }

    resetZoom();
    currentIndex.value =
        (currentIndex.value - 1 + props.images.length) % props.images.length;
}

function goNext(): void {
    if (props.images.length <= 1) {
        return;
    }

    resetZoom();
    currentIndex.value = (currentIndex.value + 1) % props.images.length;
}

function toggleZoom(): void {
    if (scale.value > 1.05) {
        resetZoom();
    } else {
        scale.value = 2.25;
        translateX.value = 0;
        translateY.value = 0;
    }
}

function closeViewer(fromPopState = false): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (pushedHistory && !fromPopState && typeof window !== 'undefined') {
        pushedHistory = false;
        window.history.back();
    } else {
        pushedHistory = false;
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        return;
    }

    if (event.key === 'Escape') {
        closeViewer();
    } else if (event.key === 'ArrowLeft') {
        goPrev();
    } else if (event.key === 'ArrowRight') {
        goNext();
    }
}

function handlePopState(): void {
    if (open.value && pushedHistory) {
        closeViewer(true);
    }
}

function getTouchDistance(touches: TouchList): number {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;

    return Math.hypot(dx, dy);
}

function handleTouchStart(event: TouchEvent): void {
    if (event.touches.length === 2) {
        initialPinchDistance = getTouchDistance(event.touches);
        initialPinchScale = scale.value;
        return;
    }

    if (event.touches.length === 1) {
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;

        const now = Date.now();
        if (now - lastTapTime < 280) {
            toggleZoom();
            lastTapTime = 0;
        } else {
            lastTapTime = now;
        }
    }
}

function handleTouchMove(event: TouchEvent): void {
    if (event.touches.length === 2 && initialPinchDistance > 0) {
        const dist = getTouchDistance(event.touches);
        const nextScale = Math.min(
            4,
            Math.max(1, (dist / initialPinchDistance) * initialPinchScale),
        );
        scale.value = nextScale;
        if (nextScale <= 1.02) {
            translateX.value = 0;
            translateY.value = 0;
        }
        return;
    }

    if (event.touches.length === 1 && scale.value > 1.05) {
        const dx = event.touches[0].clientX - touchStartX;
        const dy = event.touches[0].clientY - touchStartY;
        translateX.value += dx * 0.45;
        translateY.value += dy * 0.45;
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
    }
}

function handleTouchEnd(event: TouchEvent): void {
    if (event.touches.length < 2) {
        initialPinchDistance = 0;
    }

    if (
        event.changedTouches.length === 1 &&
        scale.value <= 1.05 &&
        event.touches.length === 0
    ) {
        const dx = event.changedTouches[0].clientX - touchStartX;
        const dy = event.changedTouches[0].clientY - touchStartY;

        if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.4) {
            if (dx > 0) {
                goPrev();
            } else {
                goNext();
            }
        } else if (dy > 95 && Math.abs(dy) > Math.abs(dx) * 1.6) {
            closeViewer();
        }
    }
}

watch(open, (isOpen) => {
    if (typeof window === 'undefined') {
        return;
    }

    if (isOpen) {
        currentIndex.value = Math.max(
            0,
            Math.min(props.initialIndex, Math.max(0, props.images.length - 1)),
        );
        resetZoom();
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        try {
            window.history.pushState({ syncvietLightbox: true }, '');
            pushedHistory = true;
        } catch {
            pushedHistory = false;
        }

        window.addEventListener('keydown', handleKeydown);
        window.addEventListener('popstate', handlePopState);
    } else {
        document.body.style.overflow = previousBodyOverflow;
        window.removeEventListener('keydown', handleKeydown);
        window.removeEventListener('popstate', handlePopState);
    }
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        if (open.value) {
            document.body.style.overflow = previousBodyOverflow;
        }
        window.removeEventListener('keydown', handleKeydown);
        window.removeEventListener('popstate', handlePopState);
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && images[currentIndex]"
            role="dialog"
            aria-modal="true"
            aria-label="Image viewer"
            class="fixed inset-0 z-[70] flex h-[100dvh] w-full flex-col bg-black/95 text-white select-none"
        >
            <!-- Top Safe-Area Controls -->
            <div
                class="safe-pt flex items-center justify-between gap-3 px-3 py-2.5 sm:px-5"
            >
                <span class="text-xs font-medium text-white/80">
                    {{ currentIndex + 1 }} / {{ images.length }}
                </span>

                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        class="flex size-11 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        :aria-label="scale > 1.05 ? 'Zoom out' : 'Zoom in'"
                        @click="toggleZoom"
                    >
                        <ZoomOut v-if="scale > 1.05" class="size-5" />
                        <ZoomIn v-else class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="flex size-11 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        aria-label="Close"
                        @click="closeViewer()"
                    >
                        <X class="size-5" />
                    </button>
                </div>
            </div>

            <!-- Interactive Stage -->
            <div
                class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden px-2"
                style="touch-action: none"
                @touchstart.passive="handleTouchStart"
                @touchmove.prevent="handleTouchMove"
                @touchend="handleTouchEnd"
                @click.self="closeViewer()"
            >
                <button
                    v-if="images.length > 1"
                    type="button"
                    aria-label="Previous image"
                    class="absolute left-2 z-10 hidden size-11 items-center justify-center rounded-full bg-white/15 text-white transition-colors hover:bg-white/25 sm:flex"
                    @click.stop="goPrev"
                >
                    <ChevronLeft class="size-6" />
                </button>

                <img
                    :src="images[currentIndex].url"
                    :alt="images[currentIndex].alt || 'Post media'"
                    class="max-h-full max-w-full object-contain transition-transform duration-150 will-change-transform"
                    :style="{
                        transform: `translate3d(${translateX}px, ${translateY}px, 0) scale(${scale})`,
                    }"
                    @dblclick="toggleZoom"
                />

                <button
                    v-if="images.length > 1"
                    type="button"
                    aria-label="Next image"
                    class="absolute right-2 z-10 hidden size-11 items-center justify-center rounded-full bg-white/15 text-white transition-colors hover:bg-white/25 sm:flex"
                    @click.stop="goNext"
                >
                    <ChevronRight class="size-6" />
                </button>
            </div>

            <!-- Dots Indicator -->
            <div
                v-if="images.length > 1"
                class="safe-pb flex items-center justify-center gap-2 py-3"
            >
                <button
                    v-for="(img, idx) in images"
                    :key="img.id"
                    type="button"
                    :aria-label="`Image ${idx + 1}`"
                    :class="[
                        'h-2 rounded-full transition-all',
                        idx === currentIndex
                            ? 'w-6 bg-white'
                            : 'w-2 bg-white/40',
                    ]"
                    @click="
                        resetZoom();
                        currentIndex = idx;
                    "
                />
            </div>
        </div>
    </Teleport>
</template>
