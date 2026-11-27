<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    Camera,
    Check,
    ImagePlus,
    RotateCcw,
    Trash2,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    IMAGE_ACCEPT_ATTR,
    MAX_IMAGE_BYTES,
    renderCroppedImageFile,
    resolveMaxBytes,
    validateImageFile,
} from '@/lib/imageUpload';
import { appToast } from '@/lib/toast';

const props = withDefaults(
    defineProps<{
        modelValue?: File | null;
        currentUrl?: string | null;
        allowRemove?: boolean;
        removed?: boolean;
        externalError?: string | null;
        disabled?: boolean;
    }>(),
    {
        modelValue: null,
        currentUrl: null,
        allowRemove: true,
        removed: false,
        externalError: null,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [file: File | null];
    'update:removed': [value: boolean];
}>();

const { t } = useI18n();
const page = usePage();

const fileInputRef = ref<HTMLInputElement | null>(null);
const localError = ref<string | null>(null);
const previewUrl = ref<string | null>(null);

// Interactive 3:1 Cover Crop/Reposition Modal
const cropDialogOpen = ref(false);
const rawCropUrl = ref<string | null>(null);
const rawFileName = ref('cover.webp');
const zoom = ref(1);
const offsetX = ref(0);
const offsetY = ref(0);
const dragging = ref(false);
const dragStart = ref({ x: 0, y: 0, ox: 0, oy: 0 });
const applyingCrop = ref(false);

const effectiveMaxBytes = computed<number>(() => {
    const limits = page.props.systemSettings?.limits as
        | {
              uploadMaxBytes?: number;
              uploadLimitsByContext?: Record<string, number>;
          }
        | undefined;

    return resolveMaxBytes(
        limits?.uploadLimitsByContext?.cover ??
            limits?.uploadMaxBytes ??
            MAX_IMAGE_BYTES,
    );
});

const effectiveMaxMb = computed<number>(() =>
    Math.max(1, Math.round(effectiveMaxBytes.value / 1_000_000)),
);

const shownSrc = computed<string | null>(() => {
    if (previewUrl.value) {
        return previewUrl.value;
    }

    if (props.removed) {
        return null;
    }

    return props.currentUrl || null;
});

const displayedError = computed<string | null>(
    () => localError.value || props.externalError || null,
);

watch(
    () => props.modelValue,
    (file) => {
        if (previewUrl.value) {
            URL.revokeObjectURL(previewUrl.value);
            previewUrl.value = null;
        }
        if (file instanceof File) {
            previewUrl.value = URL.createObjectURL(file);
        }
    },
    { immediate: true },
);

function openPicker(): void {
    if (props.disabled) {
        return;
    }
    fileInputRef.value?.click();
}

function onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    localError.value = null;
    const check = validateImageFile(file, {
        maxBytes: effectiveMaxBytes.value,
    });

    if (!check.valid && check.error) {
        localError.value = check.error;
        appToast.error(check.error);
        return;
    }

    if (rawCropUrl.value) {
        URL.revokeObjectURL(rawCropUrl.value);
    }

    rawCropUrl.value = URL.createObjectURL(file);
    rawFileName.value = file.name;
    zoom.value = 1;
    offsetX.value = 0;
    offsetY.value = 0;
    cropDialogOpen.value = true;
}

function startDrag(event: PointerEvent): void {
    dragging.value = true;
    dragStart.value = {
        x: event.clientX,
        y: event.clientY,
        ox: offsetX.value,
        oy: offsetY.value,
    };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onDrag(event: PointerEvent): void {
    if (!dragging.value) {
        return;
    }
    const dx = ((event.clientX - dragStart.value.x) / 140) * 100;
    const dy = ((event.clientY - dragStart.value.y) / 80) * 100;
    offsetX.value = Math.max(-100, Math.min(100, dragStart.value.ox + dx));
    offsetY.value = Math.max(-100, Math.min(100, dragStart.value.oy + dy));
}

function endDrag(): void {
    dragging.value = false;
}

function resetCrop(): void {
    zoom.value = 1;
    offsetX.value = 0;
    offsetY.value = 0;
}

async function applyCrop(): Promise<void> {
    if (!rawCropUrl.value || applyingCrop.value) {
        return;
    }

    applyingCrop.value = true;
    try {
        const cropped = await renderCroppedImageFile(rawCropUrl.value, {
            outputWidth: 1500,
            outputHeight: 500,
            zoom: zoom.value,
            offsetXPercent: offsetX.value,
            offsetYPercent: offsetY.value,
            fileName: rawFileName.value,
        });
        emit('update:removed', false);
        emit('update:modelValue', cropped);
        cropDialogOpen.value = false;
    } catch {
        const msg = t('upload.errors.failed');
        localError.value = msg;
        appToast.error(msg);
    } finally {
        applyingCrop.value = false;
    }
}

function removeCover(): void {
    localError.value = null;
    emit('update:modelValue', null);
    emit('update:removed', true);
}

onBeforeUnmount(() => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    if (rawCropUrl.value) {
        URL.revokeObjectURL(rawCropUrl.value);
    }
});
</script>

<template>
    <div class="space-y-2">
        <div
            class="group relative aspect-[3/1] w-full overflow-hidden rounded-xl border border-border bg-muted/60"
        >
            <img
                v-if="shownSrc"
                :src="shownSrc"
                :alt="t('profile.cover')"
                class="size-full object-cover"
            />
            <button
                v-else
                type="button"
                :disabled="disabled"
                class="flex size-full flex-col items-center justify-center gap-1.5 text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                @click="openPicker"
            >
                <ImagePlus class="size-6 text-primary/80" aria-hidden="true" />
                <span class="text-xs font-medium">{{
                    t('upload.addCover')
                }}</span>
                <span class="text-[11px] text-muted-foreground">
                    {{ t('upload.formatsAndLimit', { max: effectiveMaxMb }) }}
                </span>
            </button>

            <!-- Action Overlay Buttons -->
            <div
                class="absolute end-2.5 bottom-2.5 flex flex-wrap items-center gap-1.5"
            >
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    class="h-8 gap-1.5 rounded-lg border border-border/70 bg-card/95 px-2.5 text-xs shadow-xs hover:bg-card"
                    :disabled="disabled"
                    @click="openPicker"
                >
                    <Camera class="size-3.5 text-primary" aria-hidden="true" />
                    <span>{{ t('upload.changeCover') }}</span>
                </Button>
                <Button
                    v-if="allowRemove && shownSrc"
                    type="button"
                    size="icon"
                    variant="secondary"
                    class="size-8 rounded-lg border border-border/70 bg-card/95 text-muted-foreground shadow-xs hover:bg-card hover:text-destructive"
                    :aria-label="t('upload.removeCover')"
                    :title="t('upload.removeCover')"
                    :disabled="disabled"
                    @click="removeCover"
                >
                    <Trash2 class="size-3.5" aria-hidden="true" />
                </Button>
            </div>
        </div>

        <div
            v-if="displayedError"
            role="alert"
            class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive-soft/40 px-3 py-2 text-xs text-destructive"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ displayedError }}</span>
        </div>

        <input
            ref="fileInputRef"
            type="file"
            :accept="IMAGE_ACCEPT_ATTR"
            class="sr-only"
            tabindex="-1"
            @change="onFileSelected"
        />

        <!-- Interactive 3:1 Cover Reposition & Crop Modal -->
        <Dialog v-model:open="cropDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ t('upload.cropCoverTitle') }}</DialogTitle>
                </DialogHeader>

                <div class="space-y-4">
                    <div
                        class="relative aspect-[3/1] w-full cursor-grab touch-none overflow-hidden rounded-xl border-2 border-primary/50 bg-muted select-none active:cursor-grabbing"
                        @pointerdown="startDrag"
                        @pointermove="onDrag"
                        @pointerup="endDrag"
                        @pointercancel="endDrag"
                    >
                        <img
                            v-if="rawCropUrl"
                            :src="rawCropUrl"
                            :alt="t('upload.previewAlt')"
                            draggable="false"
                            class="pointer-events-none size-full object-cover"
                            :style="{
                                transform: `scale(${zoom}) translate(${offsetX * 0.25}%, ${offsetY * 0.25}%)`,
                            }"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <ZoomOut
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            v-model.number="zoom"
                            type="range"
                            min="1"
                            max="3"
                            step="0.05"
                            :aria-label="t('upload.zoomLabel')"
                            class="h-1.5 flex-1 accent-primary"
                        />
                        <ZoomIn
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8 shrink-0"
                            :aria-label="t('upload.resetCrop')"
                            @click="resetCrop"
                        >
                            <RotateCcw class="size-3.5" aria-hidden="true" />
                        </Button>
                    </div>

                    <p class="text-center text-[11px] text-muted-foreground">
                        {{ t('upload.dragToReposition') }}
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        class="rounded-lg"
                        @click="cropDialogOpen = false"
                    >
                        {{ t('post.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        class="rounded-lg"
                        :disabled="applyingCrop"
                        @click="applyCrop"
                    >
                        <Check class="size-4" aria-hidden="true" />
                        <span>{{ t('upload.applyCrop') }}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
