<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Save, Settings } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as updateSettingsRoute } from '@/routes/admin/settings';

const props = defineProps<{
    settings: Record<string, string | number | boolean>;
    schema: Record<
        string,
        { group: string; type: string; value: unknown; is_public: boolean }
    >;
}>();

const { t } = useI18n();
const activeTab = ref<'general' | 'features' | 'content' | 'ads' | 'seo'>(
    'general',
);

const form = useForm<{ settings: Record<string, string | number | boolean> }>({
    settings: { ...props.settings },
});

function keysForGroup(groups: string[]): string[] {
    return Object.entries(props.schema)
        .filter(([, meta]) => groups.includes(meta.group))
        .map(([key]) => key);
}

function submit(): void {
    form.post(updateSettingsRoute.url(), { preserveScroll: true });
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
