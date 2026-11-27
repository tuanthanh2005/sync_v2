<script setup lang="ts">
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    RefreshCw,
    RotateCcw,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatBytes } from '@/lib/formatters';

const props = withDefaults(
    defineProps<{
        src: string;
        fileName?: string;
        fileSize?: number;
        alt?: string;
        index?: number;
        total?: number;
        progress?: number | null;
        error?: string | null;
        allowReorder?: boolean;
        allowAltText?: boolean;
        allowReplace?: boolean;
        aspectClass?: string;
        disabled?: boolean;
    }>(),
    {
        fileName: '',
        fileSize: 0,
        alt: '',
        index: 0,
        total: 1,
        progress: null,
        error: null,
        allowReorder: false,
        allowAltText: false,
        allowReplace: true,
        aspectClass: 'aspect-[4/3]',
        disabled: false,
    },
);

const emit = defineEmits<{
    remove: [];
    replace: [];
    retry: [];
    movePrev: [];
    moveNext: [];
    'update:alt': [value: string];
}>();

const { t, locale } = useI18n();
const showProgressBar = computed(
    () =>
        props.progress !== null && props.progress >= 0 && props.progress < 100,
);
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-xl border border-border bg-card shadow-2xs"
    >
        <div
            :class="[
                'relative w-full overflow-hidden bg-muted/50',
                props.aspectClass,
            ]"
        >
            <img
                :src="src"
                :alt="alt || fileName || t('upload.previewAlt')"
                class="size-full object-cover"
            />

            <!-- Top Action Controls -->
            <div
                class="absolute inset-x-1.5 top-1.5 flex items-center justify-between gap-1"
            >
                <div
                    v-if="allowReorder && total > 1"
                    class="flex items-center gap-1"
                >
                    <button
                        v-if="index > 0"
                        type="button"
                        :disabled="disabled"
                        class="flex size-7 items-center justify-center rounded-lg bg-black/70 text-white transition-colors hover:bg-black focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-50"
                        :aria-label="t('upload.movePrev')"
                        :title="t('upload.movePrev')"
                        @click="emit('movePrev')"
                    >
                        <ArrowLeft class="size-3.5" aria-hidden="true" />
                    </button>
                    <button
                        v-if="index < total - 1"
                        type="button"
                        :disabled="disabled"
                        class="flex size-7 items-center justify-center rounded-lg bg-black/70 text-white transition-colors hover:bg-black focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-50"
                        :aria-label="t('upload.moveNext')"
                        :title="t('upload.moveNext')"
                        @click="emit('moveNext')"
                    >
                        <ArrowRight class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
                <span v-else />

                <div class="flex items-center gap-1">
                    <button
                        v-if="allowReplace"
                        type="button"
                        :disabled="disabled"
                        class="flex size-7 items-center justify-center rounded-lg bg-black/70 text-white transition-colors hover:bg-black focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-50"
                        :aria-label="t('upload.replaceImage')"
                        :title="t('upload.replaceImage')"
                        @click="emit('replace')"
                    >
                        <RefreshCw class="size-3.5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        :disabled="disabled"
                        class="flex size-7 items-center justify-center rounded-lg bg-black/70 text-white transition-colors hover:bg-destructive focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-50"
                        :aria-label="t('upload.removeImage')"
                        :title="t('upload.removeImage')"
                        @click="emit('remove')"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <!-- Upload Progress Overlay -->
            <div
                v-if="showProgressBar"
                class="absolute inset-x-0 bottom-0 bg-black/65 px-2.5 py-1.5"
            >
                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-white/25"
                    role="progressbar"
                    :aria-valuenow="progress ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full bg-primary transition-[width] duration-150"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
            </div>

            <!-- Error Overlay with Retry -->
            <div
                v-if="error"
                class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/75 p-3 text-center text-white"
            >
                <AlertCircle
                    class="size-5 text-destructive"
                    aria-hidden="true"
                />
                <p class="line-clamp-2 text-[11px] leading-snug">
                    {{ error }}
                </p>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1 text-[11px] font-semibold text-primary-foreground hover:bg-primary-hover"
                    @click="emit('retry')"
                >
                    <RotateCcw class="size-3" aria-hidden="true" />
                    <span>{{ t('upload.retry') }}</span>
                </button>
            </div>
        </div>

        <!-- File metadata + Optional Alt text -->
        <div
            v-if="fileName || fileSize > 0 || allowAltText"
            class="space-y-1.5 border-t border-border/70 bg-card px-2.5 py-2"
        >
            <div
                v-if="fileName || fileSize > 0"
                class="flex items-center justify-between gap-2 text-[11px] text-muted-foreground"
            >
                <span class="min-w-0 truncate font-medium text-foreground/85">{{
                    fileName
                }}</span>
                <span v-if="fileSize > 0" class="shrink-0 tabular-nums">
                    {{ formatBytes(fileSize, locale) }}
                </span>
            </div>

            <input
                v-if="allowAltText"
                type="text"
                :value="alt"
                maxlength="250"
                :disabled="disabled"
                :placeholder="t('upload.altTextPlaceholder')"
                :aria-label="t('upload.altTextLabel')"
                class="h-7 w-full rounded-md border border-input bg-muted/30 px-2 text-[11px] text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                @input="
                    emit(
                        'update:alt',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
        </div>
    </div>
</template>
