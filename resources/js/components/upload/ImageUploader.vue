<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ImagePlus,
    ShieldCheck,
    UploadCloud,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageUploadPreview from '@/components/upload/ImageUploadPreview.vue';
import { Button } from '@/components/ui/button';
import {
    IMAGE_ACCEPT_ATTR,
    MAX_IMAGE_BYTES,
    MAX_POST_REQUEST_BYTES,
    resolveMaxBytes,
    validateImageBatch,
    validateImageFile,
} from '@/lib/imageUpload';
import { appToast } from '@/lib/toast';

const props = withDefaults(
    defineProps<{
        modelValue?: File | File[] | null;
        existingUrl?: string | null;
        multiple?: boolean;
        maxFiles?: number;
        context?: 'avatar' | 'cover' | 'post' | 'banner' | 'cms';
        maxBytes?: number;
        label?: string;
        description?: string;
        compact?: boolean;
        allowAltText?: boolean;
        allowReorder?: boolean;
        alts?: string[];
        progress?: number | null;
        uploading?: boolean;
        externalError?: string | null;
        disabled?: boolean;
        showOwnershipNotice?: boolean;
    }>(),
    {
        modelValue: null,
        existingUrl: null,
        multiple: false,
        maxFiles: 4,
        context: 'post',
        maxBytes: undefined,
        label: undefined,
        description: undefined,
        compact: false,
        allowAltText: false,
        allowReorder: false,
        alts: () => [],
        progress: null,
        uploading: false,
        externalError: null,
        disabled: false,
        showOwnershipNotice: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: File | File[] | null];
    'update:alts': [value: string[]];
    'remove-existing': [];
    cancel: [];
    retry: [];
    error: [message: string];
}>();

const { t } = useI18n();
const page = usePage();

const inputRef = ref<HTMLInputElement | null>(null);
const replaceInputRef = ref<HTMLInputElement | null>(null);
const replaceIndex = ref<number | null>(null);
const isDragging = ref(false);
const localError = ref<string | null>(null);
const previewUrls = ref<string[]>([]);

const effectiveMaxBytes = computed<number>(() => {
    if (props.maxBytes !== undefined) {
        return resolveMaxBytes(props.maxBytes);
    }

    const limits = page.props.systemSettings?.limits as
        | {
              uploadMaxBytes?: number;
              uploadLimitsByContext?: Record<string, number>;
          }
        | undefined;
    const contextBytes = limits?.uploadLimitsByContext?.[props.context];

    return resolveMaxBytes(
        contextBytes ?? limits?.uploadMaxBytes ?? MAX_IMAGE_BYTES,
    );
});

const effectiveMaxMb = computed<number>(() =>
    Math.max(1, Math.round(effectiveMaxBytes.value / 1_000_000)),
);

const effectiveMaxRequestBytes = computed<number>(() => {
    const limits = page.props.systemSettings?.limits as
        | { uploadMaxPostRequestBytes?: number }
        | undefined;

    return limits?.uploadMaxPostRequestBytes ?? MAX_POST_REQUEST_BYTES;
});

const selectedFiles = computed<File[]>(() => {
    if (props.multiple) {
        return Array.isArray(props.modelValue) ? props.modelValue : [];
    }

    return props.modelValue instanceof File ? [props.modelValue] : [];
});

const displayedError = computed<string | null>(
    () => localError.value || props.externalError || null,
);

const canAddMore = computed<boolean>(() =>
    props.multiple
        ? selectedFiles.value.length < props.maxFiles
        : selectedFiles.value.length === 0,
);

function syncPreviews(files: File[]): void {
    for (const url of previewUrls.value) {
        URL.revokeObjectURL(url);
    }
    previewUrls.value = files.map((file) => URL.createObjectURL(file));
}

watch(
    selectedFiles,
    (files) => {
        syncPreviews(files);
    },
    { immediate: true },
);

function openPicker(): void {
    if (props.disabled || props.uploading) {
        return;
    }

    inputRef.value?.click();
}

function triggerReplace(index: number): void {
    if (props.disabled || props.uploading) {
        return;
    }

    replaceIndex.value = index;
    replaceInputRef.value?.click();
}

function handleIncomingFiles(rawList: FileList | File[] | null): void {
    if (!rawList || rawList.length === 0) {
        return;
    }

    const incoming = Array.from(rawList);
    localError.value = null;

    if (!props.multiple) {
        const candidate = incoming[0];
        const check = validateImageFile(candidate, {
            maxBytes: effectiveMaxBytes.value,
        });

        if (!check.valid && check.error) {
            localError.value = check.error;
            appToast.error(check.error);
            emit('error', check.error);
            return;
        }

        emit('update:modelValue', candidate);
        return;
    }

    const { accepted, errors } = validateImageBatch(
        selectedFiles.value,
        incoming,
        {
            maxFiles: props.maxFiles,
            maxBytes: effectiveMaxBytes.value,
            maxTotalBytes: effectiveMaxRequestBytes.value,
        },
    );

    if (errors.length > 0) {
        localError.value = errors[0];
        appToast.error(errors[0]);
        emit('error', errors[0]);
    }

    if (accepted.length > 0) {
        const nextFiles = [...selectedFiles.value, ...accepted];
        const nextAlts = [
            ...props.alts.slice(0, selectedFiles.value.length),
            ...accepted.map(() => ''),
        ];
        emit('update:modelValue', nextFiles);
        if (props.allowAltText) {
            emit('update:alts', nextAlts);
        }
    }
}

function onInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    handleIncomingFiles(input.files);
    input.value = '';
}

function onReplaceChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file || replaceIndex.value === null) {
        replaceIndex.value = null;
        return;
    }

    localError.value = null;
    const check = validateImageFile(file, {
        maxBytes: effectiveMaxBytes.value,
    });

    if (!check.valid && check.error) {
        localError.value = check.error;
        appToast.error(check.error);
        emit('error', check.error);
        replaceIndex.value = null;
        return;
    }

    if (!props.multiple) {
        emit('update:modelValue', file);
    } else {
        const next = [...selectedFiles.value];
        next[replaceIndex.value] = file;
        emit('update:modelValue', next);
    }

    replaceIndex.value = null;
}

function removeAt(index: number): void {
    localError.value = null;

    if (!props.multiple) {
        emit('update:modelValue', null);
        return;
    }

    const nextFiles = [...selectedFiles.value];
    nextFiles.splice(index, 1);
    emit('update:modelValue', nextFiles);

    if (props.allowAltText) {
        const nextAlts = [...props.alts];
        nextAlts.splice(index, 1);
        emit('update:alts', nextAlts);
    }
}

function moveItem(from: number, to: number): void {
    if (
        !props.multiple ||
        to < 0 ||
        to >= selectedFiles.value.length ||
        from === to
    ) {
        return;
    }

    const nextFiles = [...selectedFiles.value];
    const [moved] = nextFiles.splice(from, 1);
    nextFiles.splice(to, 0, moved);
    emit('update:modelValue', nextFiles);

    if (props.allowAltText) {
        const nextAlts = [...props.alts];
        const [movedAlt] = nextAlts.splice(from, 1);
        nextAlts.splice(to, 0, movedAlt ?? '');
        emit('update:alts', nextAlts);
    }
}

function updateAlt(index: number, value: string): void {
    const nextAlts = [...props.alts];
    while (nextAlts.length < selectedFiles.value.length) {
        nextAlts.push('');
    }
    nextAlts[index] = value;
    emit('update:alts', nextAlts);
}

function onDragOver(event: DragEvent): void {
    event.preventDefault();
    if (!props.disabled && !props.uploading) {
        isDragging.value = true;
    }
}

function onDragLeave(): void {
    isDragging.value = false;
}

function onDrop(event: DragEvent): void {
    event.preventDefault();
    isDragging.value = false;

    if (props.disabled || props.uploading) {
        return;
    }

    handleIncomingFiles(event.dataTransfer?.files ?? null);
}

function onZoneKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openPicker();
    }
}

onBeforeUnmount(() => {
    for (const url of previewUrls.value) {
        URL.revokeObjectURL(url);
    }
});

defineExpose({ openPicker });
</script>

<template>
    <div class="space-y-2.5">
        <!-- Interactive Dropzone -->
        <div
            v-if="canAddMore"
            role="button"
            tabindex="0"
            :aria-label="label || t('upload.title')"
            :aria-disabled="disabled || uploading"
            :class="[
                'group relative flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed text-center transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                compact ? 'gap-2 px-4 py-4' : 'gap-2.5 px-5 py-6',
                isDragging
                    ? 'border-primary bg-primary-soft/60'
                    : displayedError
                      ? 'border-destructive/60 bg-destructive-soft/25 hover:border-destructive'
                      : 'border-border bg-muted/25 hover:border-primary/50 hover:bg-muted/45',
                disabled || uploading ? 'pointer-events-none opacity-65' : '',
            ]"
            @click="openPicker"
            @keydown="onZoneKeydown"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <div
                :class="[
                    'flex items-center justify-center rounded-xl transition-colors',
                    compact ? 'size-9' : 'size-11',
                    isDragging
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-primary-soft text-primary',
                ]"
            >
                <UploadCloud
                    v-if="isDragging"
                    :class="compact ? 'size-4.5' : 'size-5'"
                    aria-hidden="true"
                />
                <ImagePlus
                    v-else
                    :class="compact ? 'size-4.5' : 'size-5'"
                    aria-hidden="true"
                />
            </div>

            <div class="space-y-0.5">
                <p class="text-xs font-semibold text-foreground sm:text-[13px]">
                    {{ label || t('upload.title') }}
                </p>
                <p class="text-[11px] text-muted-foreground">
                    {{ description || t('upload.dropOrBrowse') }}
                </p>
                <p class="text-[11px] font-medium text-muted-foreground">
                    {{
                        t('upload.formatsAndLimit', {
                            max: effectiveMaxMb,
                        })
                    }}
                    <span v-if="multiple">
                        ·
                        {{
                            t('upload.fileCountProgress', {
                                current: selectedFiles.length,
                                max: maxFiles,
                            })
                        }}
                    </span>
                </p>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="pointer-events-none h-8 rounded-lg bg-card px-3 text-xs font-medium"
                tabindex="-1"
            >
                {{ t('upload.chooseImage') }}
            </Button>
        </div>

        <!-- Existing Stored Image Preview (Single Mode when no new file chosen) -->
        <div
            v-if="!multiple && selectedFiles.length === 0 && existingUrl"
            class="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-2.5"
        >
            <div class="flex min-w-0 items-center gap-3">
                <img
                    :src="existingUrl"
                    :alt="t('upload.currentImageAlt')"
                    class="size-12 shrink-0 rounded-lg border border-border object-cover"
                />
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-foreground">
                        {{ t('upload.currentImage') }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        {{
                            t('upload.formatsAndLimit', {
                                max: effectiveMaxMb,
                            })
                        }}
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-8 rounded-lg text-xs"
                    :disabled="disabled || uploading"
                    @click="openPicker"
                >
                    {{ t('upload.replaceImage') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 rounded-lg text-muted-foreground hover:text-destructive"
                    :aria-label="t('upload.removeImage')"
                    :disabled="disabled || uploading"
                    @click="emit('remove-existing')"
                >
                    <X class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </div>

        <!-- Selected File Previews -->
        <div
            v-if="selectedFiles.length > 0"
            :class="[
                'grid gap-2.5',
                multiple
                    ? selectedFiles.length === 1
                        ? 'grid-cols-1 sm:grid-cols-2'
                        : 'grid-cols-2'
                    : 'grid-cols-1',
            ]"
        >
            <ImageUploadPreview
                v-for="(file, idx) in selectedFiles"
                :key="`${file.name}-${file.size}-${idx}`"
                :src="previewUrls[idx] || ''"
                :file-name="file.name"
                :file-size="file.size"
                :alt="alts[idx] || ''"
                :index="idx"
                :total="selectedFiles.length"
                :progress="uploading ? (progress ?? 0) : null"
                :allow-reorder="multiple && allowReorder"
                :allow-alt-text="allowAltText"
                :disabled="disabled || uploading"
                :aspect-class="multiple ? 'aspect-[4/3]' : 'aspect-[16/9]'"
                @remove="removeAt(idx)"
                @replace="triggerReplace(idx)"
                @retry="emit('retry')"
                @move-prev="moveItem(idx, idx - 1)"
                @move-next="moveItem(idx, idx + 1)"
                @update:alt="updateAlt(idx, $event)"
            />
        </div>

        <!-- Active Upload Progress & Cancel Strip -->
        <div
            v-if="uploading"
            class="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs"
        >
            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex items-center justify-between text-[11px]">
                    <span class="font-medium text-foreground">{{
                        t('upload.uploading')
                    }}</span>
                    <span
                        v-if="progress !== null"
                        class="font-medium text-primary tabular-nums"
                    >
                        {{ progress }}%
                    </span>
                </div>
                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="progress ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full bg-primary transition-[width] duration-150"
                        :style="{ width: `${progress ?? 25}%` }"
                    />
                </div>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-7 shrink-0 rounded-md px-2 text-[11px] text-muted-foreground hover:text-destructive"
                @click="emit('cancel')"
            >
                {{ t('upload.cancelUpload') }}
            </Button>
        </div>

        <!-- Inline Error Banner -->
        <div
            v-if="displayedError"
            role="alert"
            class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive-soft/40 px-3 py-2 text-xs text-destructive"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span class="leading-snug">{{ displayedError }}</span>
        </div>

        <!-- Ownership / Copyright Responsibility Notice -->
        <p
            v-if="showOwnershipNotice"
            class="flex items-start gap-1.5 text-[11px] leading-relaxed text-muted-foreground"
        >
            <ShieldCheck
                class="mt-0.5 size-3.5 shrink-0 text-primary/70"
                aria-hidden="true"
            />
            <span>{{ t('upload.ownershipPolicyNotice') }}</span>
        </p>

        <!-- Visually Hidden Accessible File Inputs (Never native browser bar) -->
        <input
            ref="inputRef"
            type="file"
            :multiple="multiple"
            :accept="IMAGE_ACCEPT_ATTR"
            class="sr-only"
            tabindex="-1"
            @change="onInputChange"
        />
        <input
            ref="replaceInputRef"
            type="file"
            :accept="IMAGE_ACCEPT_ATTR"
            class="sr-only"
            tabindex="-1"
            @change="onReplaceChange"
        />
    </div>
</template>
