<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { X } from '@lucide/vue';
import { reactiveOmit } from '@vueuse/core';
import {
    DialogClose,
    DialogContent,
    DialogPortal,
    useForwardPropsEmits,
} from 'reka-ui';
import { cn } from '@/lib/utils';
import DialogOverlay from './DialogOverlay.vue';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<
        DialogContentProps & {
            class?: HTMLAttributes['class'];
            showCloseButton?: boolean;
            /**
             * Enables fixed Header + independently scrollable Body + fixed Footer
             * layout (no outer scroll on the dialog frame itself, keeping close
             * button and action buttons permanently accessible).
             */
            structured?: boolean;
        }
    >(),
    {
        showCloseButton: true,
        structured: false,
    },
);
const emits = defineEmits<DialogContentEmits>();

const delegatedProps = reactiveOmit(props, 'class', 'showCloseButton', 'structured');

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DialogPortal>
        <DialogOverlay />
        <DialogContent
            data-slot="dialog-content"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="
                cn(
                    'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-x-0 bottom-0 z-50 max-h-[90dvh] w-full rounded-t-2xl border shadow-lg duration-200 data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom sm:inset-auto sm:top-[50%] sm:left-[50%] sm:max-h-[88dvh] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl sm:data-[state=closed]:zoom-out-95 sm:data-[state=open]:zoom-in-95 sm:data-[state=closed]:slide-out-to-bottom-0 sm:data-[state=open]:slide-in-from-bottom-0',
                    props.structured
                        ? 'flex flex-col overflow-hidden p-0'
                        : 'sv-scrollbar grid gap-4 overflow-y-auto overscroll-contain p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))] sm:p-6',
                    props.class,
                )
            "
        >
            <div
                v-if="!props.structured"
                class="mx-auto -mt-1 mb-1 h-1 w-10 rounded-full bg-border sm:hidden"
                aria-hidden="true"
            />
            <slot />

            <DialogClose
                v-if="showCloseButton"
                data-slot="dialog-close"
                class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute end-3 top-3 z-10 flex size-9 items-center justify-center rounded-full opacity-80 transition-opacity hover:bg-surface-hover hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
            >
                <X />
                <span class="sr-only">Close</span>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
