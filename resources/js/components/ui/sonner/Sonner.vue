<script lang="ts" setup>
import type { ToasterProps } from 'vue-sonner';
import {
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    OctagonXIcon,
    TriangleAlertIcon,
    XIcon,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Toaster as Sonner, toast } from 'vue-sonner';
import { usePage } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

import 'vue-sonner/style.css';

const props = withDefaults(defineProps<ToasterProps>(), {
    closeButton: true,
    visibleToasts: 3,
    richColors: false,
    expand: false,
    duration: 4500,
});

const page = usePage();
const isRtl = computed(() => Boolean(page.props.isRtl));
const isMobile = ref(false);

function updateIsMobile(): void {
    if (typeof window !== 'undefined') {
        isMobile.value = window.innerWidth < 640;
    }
}

const resolvedPosition = computed(() => {
    if (props.position) {
        return props.position;
    }

    if (isMobile.value) {
        return 'top-center';
    }

    return isRtl.value ? 'top-left' : 'top-right';
});

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        toast.dismiss();
    }
}

onMounted(() => {
    updateIsMobile();
    window.addEventListener('resize', updateIsMobile, { passive: true });
    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateIsMobile);
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Sonner
        v-bind="props"
        :dir="isRtl ? 'rtl' : 'ltr'"
        :position="resolvedPosition"
        :close-button="props.closeButton"
        :visible-toasts="props.visibleToasts"
        :duration="props.duration"
        :expand="props.expand"
        :class="cn('toaster group pointer-events-none', props.class)"
        :toast-options="{
            classes: {
                toast: 'syncviet-toast pointer-events-auto group relative overflow-hidden rounded-xl border border-border bg-card text-foreground shadow-lg',
                title: 'text-sm font-medium leading-snug',
                description: 'text-xs text-muted-foreground',
                closeButton:
                    '!left-auto !right-2 !top-2 !border-border !bg-card !text-muted-foreground hover:!bg-surface-hover hover:!text-foreground focus-visible:!ring-2 focus-visible:!ring-primary',
                actionButton:
                    '!rounded-lg !bg-primary !text-primary-foreground !text-xs !font-medium',
                cancelButton:
                    '!rounded-lg !bg-muted !text-muted-foreground !text-xs',
            },
        }"
        :style="{
            '--normal-bg': 'var(--card)',
            '--normal-text': 'var(--card-foreground)',
            '--normal-border': 'var(--border)',
            '--border-radius': 'var(--radius)',
        }"
    >
        <template #success-icon>
            <CircleCheckIcon class="size-4 text-success" aria-hidden="true" />
        </template>
        <template #info-icon>
            <InfoIcon class="size-4 text-primary" aria-hidden="true" />
        </template>
        <template #warning-icon>
            <TriangleAlertIcon class="size-4 text-warning" aria-hidden="true" />
        </template>
        <template #error-icon>
            <OctagonXIcon class="size-4 text-destructive" aria-hidden="true" />
        </template>
        <template #loading-icon>
            <div>
                <Loader2Icon class="size-4 animate-spin text-primary" aria-hidden="true" />
            </div>
        </template>
        <template #close-icon>
            <XIcon class="size-3.5" aria-hidden="true" />
        </template>
    </Sonner>
</template>
