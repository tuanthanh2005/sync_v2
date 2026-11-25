<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, Globe, Star, Upload } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FlagIcon from '@/components/common/FlagIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/formatters';
import {
    exportMethod as exportLocaleRoute,
    importMethod as importLocaleRoute,
    update as updateLocaleRoute,
} from '@/routes/admin/languages';

type LanguageRow = {
    code: string;
    native_name: string;
    english_name: string;
    flag_code: string;
    is_rtl: boolean;
    is_enabled: boolean;
    is_default: boolean;
    sort_order: number;
    total_keys: number;
    translated_keys: number;
    completion_percent: number;
    missing_keys: string[];
    missing_keys_count: number;
};

defineProps<{
    languages: LanguageRow[];
    revisions: Array<{
        id: number;
        language_code: string;
        action: string;
        keys_count: number;
        summary: string | null;
        actor_username: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();
const importLang = ref<LanguageRow | null>(null);
const importForm = useForm({ json_payload: '{}' });

function toggleEnabled(lang: LanguageRow): void {
    router.post(
        updateLocaleRoute.url(lang.code),
        {
            is_enabled: !lang.is_enabled,
            is_default: lang.is_default,
            sort_order: lang.sort_order,
        },
        { preserveScroll: true },
    );
}

function setDefault(lang: LanguageRow): void {
    router.post(
        updateLocaleRoute.url(lang.code),
        { is_enabled: true, is_default: true, sort_order: lang.sort_order },
        { preserveScroll: true },
    );
}

function submitImport(): void {
    if (!importLang.value) {
        return;
    }
    importForm.post(importLocaleRoute.url(importLang.value.code), {
        preserveScroll: true,
        onSuccess: () => {
            importLang.value = null;
            importForm.reset();
        },
    });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.languages.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Globe class="size-5 text-primary" />
                <span>{{ t('admin.languages.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.languages.subtitle') }}
            </p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">{{ t('locale.label') }}</th>
                            <th class="py-2 pr-3">Code</th>
                            <th class="py-2 pr-3">
                                {{ t('admin.languages.completion') }}
                            </th>
                            <th class="py-2 pr-3">
                                {{ t('admin.common.status') }}
                            </th>
                            <th class="py-2 text-right">
                                {{ t('admin.common.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="lang in languages" :key="lang.code">
                            <td class="py-3 pr-3">
                                <div class="flex items-center gap-2.5">
                                    <FlagIcon
                                        :code="lang.flag_code"
                                        size="md"
                                    />
                                    <div>
                                        <p
                                            class="font-semibold text-foreground"
                                        >
                                            {{ lang.native_name }}
                                        </p>
                                        <p
                                            class="text-[11px] text-muted-foreground"
                                        >
                                            {{ lang.english_name }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 pr-3 font-mono">
                                {{ lang.code }}
                                <Badge
                                    v-if="lang.is_rtl"
                                    variant="outline"
                                    class="ms-1 text-[10px]"
                                    >{{ t('admin.languages.rtl') }}</Badge
                                >
                            </td>
                            <td class="py-3 pr-3">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full bg-primary"
                                            :style="{
                                                width: `${lang.completion_percent}%`,
                                            }"
                                        />
                                    </div>
                                    <span class="font-mono text-[11px]"
                                        >{{ lang.completion_percent }}% ({{
                                            lang.translated_keys
                                        }}/{{ lang.total_keys }})</span
                                    >
                                </div>
                            </td>
                            <td class="py-3 pr-3">
                                <Badge
                                    v-if="lang.is_default"
                                    class="text-[10px]"
                                    >{{ t('admin.languages.default') }}</Badge
                                >
                                <Badge
                                    v-else
                                    :variant="
                                        lang.is_enabled
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    class="text-[10px]"
                                >
                                    {{
                                        lang.is_enabled
                                            ? t('admin.common.enabled')
                                            : t('admin.common.disabled')
                                    }}
                                </Badge>
                            </td>
                            <td class="py-3 text-right">
                                <div class="inline-flex flex-wrap gap-1.5">
                                    <Button
                                        v-if="!lang.is_default"
                                        size="sm"
                                        variant="outline"
                                        class="h-7 px-2 text-[11px]"
                                        @click="toggleEnabled(lang)"
                                    >
                                        {{
                                            lang.is_enabled
                                                ? t('admin.common.disabled')
                                                : t('admin.common.enabled')
                                        }}
                                    </Button>
                                    <Button
                                        v-if="
                                            !lang.is_default && lang.is_enabled
                                        "
                                        size="sm"
                                        variant="ghost"
                                        class="h-7 gap-1 px-2 text-[11px]"
                                        @click="setDefault(lang)"
                                    >
                                        <Star class="size-3" />
                                        <span>{{
                                            t('admin.languages.setDefault')
                                        }}</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 px-2 text-[11px]"
                                        as-child
                                    >
                                        <a
                                            :href="
                                                exportLocaleRoute.url(lang.code)
                                            "
                                        >
                                            <Download class="size-3" />
                                        </a>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 px-2 text-[11px]"
                                        @click="importLang = lang"
                                    >
                                        <Upload class="size-3" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <section
            v-if="revisions.length > 0"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="mb-3 text-sm font-semibold">
                {{ t('admin.languages.revisions') }}
            </h2>
            <div class="space-y-2 text-xs">
                <div
                    v-for="rev in revisions"
                    :key="rev.id"
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <div>
                        <span class="font-mono font-semibold">{{
                            rev.language_code
                        }}</span>
                        <span class="ms-2 text-muted-foreground">{{
                            rev.summary
                        }}</span>
                    </div>
                    <span class="text-[11px] text-muted-foreground"
                        >@{{ rev.actor_username || 'system' }} ·
                        {{ formatDateTime(rev.created_at, locale) }}</span
                    >
                </div>
            </div>
        </section>

        <Dialog
            :open="importLang !== null"
            @update:open="(v) => !v && (importLang = null)"
        >
            <DialogContent class="rounded-2xl sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle
                        >{{ t('admin.languages.importJson') }} ({{
                            importLang?.native_name
                        }})</DialogTitle
                    >
                    <DialogDescription
                        >Paste valid key-value JSON matching platform schema
                        keys.</DialogDescription
                    >
                </DialogHeader>
                <form @submit.prevent="submitImport" class="space-y-3">
                    <textarea
                        v-model="importForm.json_payload"
                        rows="8"
                        class="w-full rounded-lg border border-input bg-background p-3 font-mono text-xs"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="importLang = null"
                            >{{ t('dialog.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="importForm.processing"
                            >{{ t('admin.common.save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
