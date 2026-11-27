<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Image as ImageIcon,
    Save,
    Settings,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SystemSettingsController from '@/actions/App/Http/Controllers/Admin/SystemSettingsController';
import ImageUploader from '@/components/upload/ImageUploader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as updateSettingsRoute } from '@/routes/admin/settings';

type UploadRuntimeInfo = {
    policy_max_bytes: number;
    policy_max_mb: number;
    max_post_request_bytes: number;
    php_upload_max_raw: string;
    php_post_max_raw: string;
    php_memory_limit_raw: string;
    effective_max_mb: number;
    infrastructure_ready: boolean;
    gd_available: boolean;
    webp_available: boolean;
    exif_available: boolean;
};

const props = defineProps<{
    settings: Record<string, string | number | boolean>;
    schema: Record<
        string,
        { group: string; type: string; value: unknown; is_public: boolean }
    >;
    brandingUrls?: Record<string, string | null>;
    uploadRuntime?: UploadRuntimeInfo;
}>();

const { t } = useI18n();
const activeTab = ref<'general' | 'features' | 'content' | 'ads' | 'seo'>(
    'general',
);
const runtimeSpecsLine = computed(() => {
    const info = props.uploadRuntime;
    if (!info) {
        return '';
    }

    return `upload_max_filesize=${info.php_upload_max_raw} · post_max_size=${info.php_post_max_raw} · memory_limit=${info.php_memory_limit_raw} · WebP=${info.webp_available ? 'OK' : 'OFF'}`;
});

const BRANDING_ASSET_KEYS = [
    'logo_light_path',
    'logo_dark_path',
    'favicon_path',
    'og_image_path',
] as const;

const brandingUploading = ref<Record<string, boolean>>({});
const brandingFiles = ref<Record<string, File | null>>({
    logo_light_path: null,
    logo_dark_path: null,
    favicon_path: null,
    og_image_path: null,
});

const form = useForm<{ settings: Record<string, string | number | boolean> }>({
    settings: { ...props.settings },
});

function keysForGroup(groups: string[]): string[] {
    return Object.entries(props.schema)
        .filter(
            ([key, meta]) =>
                groups.includes(meta.group) &&
                !(BRANDING_ASSET_KEYS as readonly string[]).includes(key),
        )
        .map(([key]) => key);
}

function submit(): void {
    form.post(updateSettingsRoute.url(), { preserveScroll: true });
}

function uploadBrandingAsset(assetKey: string, file: File | null): void {
    brandingFiles.value[assetKey] = file;
    if (!file) {
        return;
    }

    brandingUploading.value[assetKey] = true;
    router.post(
        SystemSettingsController.uploadBrandingAsset.url(),
        {
            asset_key: assetKey,
            image: file,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                brandingUploading.value[assetKey] = false;
                brandingFiles.value[assetKey] = null;
            },
        },
    );
}

function removeBrandingAsset(assetKey: string): void {
    brandingUploading.value[assetKey] = true;
    router.post(
        SystemSettingsController.uploadBrandingAsset.url(),
        {
            asset_key: assetKey,
            remove: true,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                brandingUploading.value[assetKey] = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.settings.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Settings class="size-5 text-primary" />
                <span>{{ t('admin.settings.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.settings.subtitle') }}
            </p>
        </div>

        <div
            class="flex flex-wrap gap-1.5 rounded-xl border border-border bg-card p-2"
        >
            <Button
                v-for="tab in [
                    'general',
                    'features',
                    'content',
                    'ads',
                    'seo',
                ] as const"
                :key="tab"
                size="sm"
                :variant="activeTab === tab ? 'default' : 'ghost'"
                class="h-8 rounded-lg text-xs"
                @click="activeTab = tab"
            >
                {{ t(`admin.settings.tabs.${tab}`) }}
            </Button>
        </div>

        <!-- Branding Device Uploads (Zero External URL Import) -->
        <section
            v-if="activeTab === 'general'"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div class="flex items-center gap-2">
                <ImageIcon class="size-4 text-primary" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.settings.brandingAssetsTitle') }}
                </h2>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ t('admin.settings.brandingAssetsHint') }}
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div
                    v-for="assetKey in BRANDING_ASSET_KEYS"
                    :key="assetKey"
                    class="space-y-2 rounded-xl border border-border/70 bg-muted/15 p-3.5"
                >
                    <p class="font-mono text-xs font-semibold text-foreground">
                        {{ assetKey }}
                    </p>
                    <ImageUploader
                        :model-value="brandingFiles[assetKey]"
                        :existing-url="brandingUrls?.[assetKey] ?? null"
                        context="cms"
                        compact
                        :uploading="Boolean(brandingUploading[assetKey])"
                        :show-ownership-notice="false"
                        @update:model-value="
                            (val) =>
                                uploadBrandingAsset(
                                    assetKey,
                                    Array.isArray(val) ? (val[0] ?? null) : val,
                                )
                        "
                        @remove-existing="removeBrandingAsset(assetKey)"
                    />
                </div>
            </div>
        </section>

        <!-- Honest PHP Upload Runtime Diagnostics Banner -->
        <section
            v-if="activeTab === 'content' && uploadRuntime"
            :class="[
                'flex items-start gap-3 rounded-xl border p-4 text-xs',
                uploadRuntime.infrastructure_ready
                    ? 'border-success/35 bg-success-soft/35 text-success-soft-foreground'
                    : 'border-warning/45 bg-warning-soft/45 text-warning-soft-foreground',
            ]"
        >
            <CheckCircle2
                v-if="uploadRuntime.infrastructure_ready"
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <AlertTriangle
                v-else
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div class="space-y-1">
                <p class="font-semibold">
                    {{
                        uploadRuntime.infrastructure_ready
                            ? t('upload.admin.runtimeReady', {
                                  max: uploadRuntime.policy_max_mb,
                              })
                            : t('upload.admin.runtimeMismatch', {
                                  policy: uploadRuntime.policy_max_mb,
                                  effective: uploadRuntime.effective_max_mb,
                              })
                    }}
                </p>
                <p class="font-mono text-[11px] opacity-90">
                    {{ runtimeSpecsLine }}
                </p>
            </div>
        </section>

        <form
            @submit.prevent="submit"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <div
                v-if="activeTab === 'features'"
                class="flex items-start gap-2.5 rounded-xl border border-warning/40 bg-warning-soft/50 p-3 text-xs text-warning-soft-foreground"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span>{{ t('admin.settings.maintenanceWarning') }}</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div
                    v-for="key in keysForGroup(
                        activeTab === 'general'
                            ? ['general', 'branding']
                            : [activeTab],
                    )"
                    :key="key"
                    class="space-y-1.5 rounded-lg border border-border/70 bg-muted/20 p-3"
                >
                    <Label
                        :for="`setting-${key}`"
                        class="font-mono text-xs font-semibold text-foreground"
                    >
                        {{ key }}
                    </Label>

                    <label
                        v-if="schema[key].type === 'boolean'"
                        class="flex items-center gap-2 pt-1 text-xs"
                    >
                        <input
                            :id="`setting-${key}`"
                            v-model="form.settings[key]"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        <span>{{
                            form.settings[key]
                                ? t('admin.common.enabled')
                                : t('admin.common.disabled')
                        }}</span>
                    </label>

                    <Input
                        v-else-if="schema[key].type === 'integer'"
                        :id="`setting-${key}`"
                        :model-value="Number(form.settings[key] ?? 0)"
                        type="number"
                        :min="key.includes('upload_max_mb') ? 1 : 0"
                        :max="key.includes('upload_max_mb') ? 40 : undefined"
                        class="h-9 text-xs"
                        @update:model-value="
                            (v) => (form.settings[key] = Number(v))
                        "
                    />

                    <Input
                        v-else
                        :id="`setting-${key}`"
                        :model-value="String(form.settings[key] ?? '')"
                        type="text"
                        class="h-9 text-xs"
                        @update:model-value="
                            (v) => (form.settings[key] = String(v))
                        "
                    />
                </div>
            </div>

            <div class="flex justify-end border-t border-border pt-4">
                <Button
                    type="submit"
                    size="sm"
                    class="gap-1.5 rounded-lg text-xs"
                    :disabled="form.processing"
                >
                    <Save class="size-3.5" />
                    <span>{{
                        form.processing
                            ? t('admin.common.saving')
                            : t('admin.common.save')
                    }}</span>
                </Button>
            </div>
        </form>
    </div>
</template>
