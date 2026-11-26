<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Download,
    FileSpreadsheet,
    Globe,
    RotateCcw,
    Search,
    Star,
    Upload,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
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
    auditReport as auditReportRoute,
    exportMethod as exportLocaleRoute,
    importMethod as importLocaleRoute,
    index as languagesIndexRoute,
    rollback as rollbackLocaleRoute,
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
    overrides_count: number;
    frontend_empty_count: number;
    frontend_placeholder_errors_count: number;
    backend_total: number;
    backend_translated: number;
    backend_coverage_percent: number;
    php_lang_total: number;
    php_lang_translated: number;
    php_lang_coverage_percent: number;
    overall_coverage_percent: number;
    review_status: string;
};

type KeyBrowserItem = {
    key: string;
    module: string;
    en_value: string;
    locale_value: string;
    is_overridden: boolean;
    status: string;
};

const props = defineProps<{
    languages: LanguageRow[];
    auditSummary: {
        default_locale: string;
        fallback_locale: string;
        frontend_total_keys: number;
        backend_total_keys: number;
        php_lang_total_keys: number;
        total_missing_keys: number;
        total_empty_keys: number;
        total_duplicate_keys: number;
        total_placeholder_errors: number;
    };
    keyBrowser: {
        locale: string;
        modules: string[];
        total_matched: number;
        items: KeyBrowserItem[];
    };
    filters: {
        browse_locale: string;
        browse_module: string;
        browse_search: string;
        browse_status: string;
    };
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
const rollbackLang = ref<LanguageRow | null>(null);
const importForm = useForm({ json_payload: '{}' });

const browseLocale = ref(props.filters.browse_locale || 'vi');
const browseModule = ref(props.filters.browse_module || 'all');
const browseStatus = ref(props.filters.browse_status || 'all');
const browseSearch = ref(props.filters.browse_search || '');

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

function confirmRollback(): void {
    if (!rollbackLang.value) {
        return;
    }
    router.post(
        rollbackLocaleRoute.url(rollbackLang.value.code),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rollbackLang.value = null;
            },
        },
    );
}

function applyBrowserFilters(): void {
    router.get(
        languagesIndexRoute.url(),
        {
            browse_locale: browseLocale.value,
            browse_module: browseModule.value,
            browse_status: browseStatus.value,
            browse_search: browseSearch.value || undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function reviewStatusLabel(status: string): string {
    if (status === 'default_source') {
        return t('admin.languages.statusSource');
    }
    if (status === 'complete_pending_native_review') {
        return t('admin.languages.statusComplete');
    }

    return t('admin.languages.statusNeedsWork');
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.languages.title')" />

        <div
            class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-bold text-foreground"
                >
                    <Globe class="size-5 text-primary" aria-hidden="true" />
                    <span>{{ t('admin.languages.title') }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.languages.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    as-child
                >
                    <a
                        :href="
                            auditReportRoute.url({
                                query: { format: 'csv' },
                            })
                        "
                    >
                        <FileSpreadsheet class="size-3.5" aria-hidden="true" />
                        <span>{{ t('admin.languages.exportCsvReport') }}</span>
                    </a>
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    as-child
                >
                    <a
                        :href="
                            auditReportRoute.url({
                                query: { format: 'json' },
                            })
                        "
                    >
                        <Download class="size-3.5" aria-hidden="true" />
                        <span>{{ t('admin.languages.exportJsonReport') }}</span>
                    </a>
                </Button>
            </div>
        </div>

        <!-- Audit Summary KPI Grid -->
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.languages.kpiFrontendKeys') }}
                </p>
                <p class="mt-1 text-xl font-bold text-foreground tabular-nums">
                    {{ auditSummary.frontend_total_keys }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{
                        t('admin.languages.kpiFallbackInfo', {
                            default: auditSummary.default_locale,
                            fallback: auditSummary.fallback_locale,
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.languages.kpiBackendKeys') }}
                </p>
                <p class="mt-1 text-xl font-bold text-foreground tabular-nums">
                    {{ auditSummary.backend_total_keys }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{
                        t('admin.languages.kpiPhpLangKeys', {
                            count: auditSummary.php_lang_total_keys,
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.languages.kpiMissingEmpty') }}
                </p>
                <p class="mt-1 text-xl font-bold text-foreground tabular-nums">
                    {{
                        auditSummary.total_missing_keys +
                        auditSummary.total_empty_keys
                    }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{
                        t('admin.languages.kpiMissingDetail', {
                            missing: auditSummary.total_missing_keys,
                            empty: auditSummary.total_empty_keys,
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.languages.kpiPlaceholderDuplicates') }}
                </p>
                <p class="mt-1 text-xl font-bold text-foreground tabular-nums">
                    {{
                        auditSummary.total_placeholder_errors +
                        auditSummary.total_duplicate_keys
                    }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{
                        t('admin.languages.kpiPlaceholderDetail', {
                            placeholders: auditSummary.total_placeholder_errors,
                            duplicates: auditSummary.total_duplicate_keys,
                        })
                    }}
                </p>
            </div>
        </section>

        <!-- 15-Locale Coverage Table -->
        <div class="rounded-xl border border-border bg-card p-4">
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pe-3 text-start">
                                {{ t('locale.label') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.codeColumn') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.completion') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.backendCoverage') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.reviewColumn') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.common.status') }}
                            </th>
                            <th class="py-2 text-end">
                                {{ t('admin.common.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="lang in languages" :key="lang.code">
                            <td class="py-3 pe-3">
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
                            <td class="py-3 pe-3 font-mono">
                                {{ lang.code }}
                                <Badge
                                    v-if="lang.is_rtl"
                                    variant="outline"
                                    class="ms-1 text-[10px]"
                                    >{{ t('admin.languages.rtl') }}</Badge
                                >
                            </td>
                            <td class="py-3 pe-3">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full bg-primary"
                                            :style="{
                                                width: `${lang.completion_percent}%`,
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="font-mono text-[11px] tabular-nums"
                                        >{{ lang.completion_percent }}% ({{
                                            lang.translated_keys
                                        }}/{{ lang.total_keys }})</span
                                    >
                                </div>
                            </td>
                            <td
                                class="py-3 pe-3 font-mono text-[11px] text-muted-foreground tabular-nums"
                            >
                                {{ lang.backend_coverage_percent }}% ·
                                {{ lang.php_lang_coverage_percent }}%
                            </td>
                            <td class="py-3 pe-3">
                                <Badge variant="outline" class="text-[10px]">
                                    {{ reviewStatusLabel(lang.review_status) }}
                                </Badge>
                            </td>
                            <td class="py-3 pe-3">
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
                            <td class="py-3 text-end">
                                <div
                                    class="inline-flex flex-wrap justify-end gap-1.5"
                                >
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
                                        <Star
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        <span>{{
                                            t('admin.languages.setDefault')
                                        }}</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 px-2 text-[11px]"
                                        :aria-label="
                                            t('admin.languages.exportLocale', {
                                                locale: lang.code,
                                            })
                                        "
                                        as-child
                                    >
                                        <a
                                            :href="
                                                exportLocaleRoute.url(lang.code)
                                            "
                                        >
                                            <Download
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                        </a>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-7 px-2 text-[11px]"
                                        :aria-label="
                                            t('admin.languages.importJson')
                                        "
                                        @click="importLang = lang"
                                    >
                                        <Upload
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                    <Button
                                        v-if="lang.overrides_count > 0"
                                        size="sm"
                                        variant="ghost"
                                        class="h-7 px-2 text-[11px] text-destructive"
                                        :aria-label="
                                            t('admin.languages.rollbackTitle')
                                        "
                                        @click="rollbackLang = lang"
                                    >
                                        <RotateCcw
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Translation Key Explorer -->
        <section class="space-y-4 rounded-xl border border-border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ t('admin.languages.explorerTitle') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('admin.languages.explorerSubtitle', {
                                matched: keyBrowser.total_matched,
                            })
                        }}
                    </p>
                </div>
            </div>

            <form
                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5"
                @submit.prevent="applyBrowserFilters"
            >
                <select
                    v-model="browseLocale"
                    class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                    :aria-label="t('locale.label')"
                    @change="applyBrowserFilters"
                >
                    <option
                        v-for="lang in languages"
                        :key="lang.code"
                        :value="lang.code"
                    >
                        {{ lang.native_name }} ({{ lang.code }})
                    </option>
                </select>

                <select
                    v-model="browseModule"
                    class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                    :aria-label="t('admin.languages.filterModule')"
                    @change="applyBrowserFilters"
                >
                    <option value="all">
                        {{ t('admin.languages.allModules') }}
                    </option>
                    <option
                        v-for="mod in keyBrowser.modules"
                        :key="mod"
                        :value="mod"
                    >
                        {{ mod }}
                    </option>
                </select>

                <select
                    v-model="browseStatus"
                    class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                    :aria-label="t('admin.common.status')"
                    @change="applyBrowserFilters"
                >
                    <option value="all">
                        {{ t('admin.languages.allStatuses') }}
                    </option>
                    <option value="translated">
                        {{ t('admin.languages.statusTranslated') }}
                    </option>
                    <option value="overridden">
                        {{ t('admin.languages.statusOverridden') }}
                    </option>
                    <option value="missing">
                        {{ t('admin.languages.statusMissing') }}
                    </option>
                    <option value="empty">
                        {{ t('admin.languages.statusEmpty') }}
                    </option>
                    <option value="placeholder_error">
                        {{ t('admin.languages.statusPlaceholderError') }}
                    </option>
                </select>

                <div class="relative lg:col-span-2">
                    <Search
                        class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="browseSearch"
                        type="search"
                        :placeholder="t('admin.languages.searchKeys')"
                        class="h-9 w-full rounded-lg border border-input bg-background ps-8 pe-3 text-xs"
                    />
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.keyColumn') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.languages.englishSource') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{
                                    t('admin.languages.targetTranslation', {
                                        locale: keyBrowser.locale,
                                    })
                                }}
                            </th>
                            <th class="py-2 text-end">
                                {{ t('admin.common.status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="item in keyBrowser.items" :key="item.key">
                            <td
                                class="py-2.5 pe-3 align-top font-mono text-[11px] text-primary"
                            >
                                {{ item.key }}
                            </td>
                            <td
                                class="max-w-xs py-2.5 pe-3 align-top text-muted-foreground"
                            >
                                {{ item.en_value }}
                            </td>
                            <td
                                class="max-w-xs py-2.5 pe-3 align-top text-foreground"
                            >
                                {{ item.locale_value || '—' }}
                            </td>
                            <td class="py-2.5 text-end align-top">
                                <Badge
                                    :variant="
                                        item.status === 'translated'
                                            ? 'secondary'
                                            : item.status === 'overridden'
                                              ? 'default'
                                              : 'destructive'
                                    "
                                    class="text-[10px]"
                                >
                                    {{ item.status }}
                                </Badge>
                            </td>
                        </tr>
                        <tr v-if="keyBrowser.items.length === 0">
                            <td
                                colspan="4"
                                class="py-6 text-center text-muted-foreground"
                            >
                                {{ t('admin.languages.noKeysMatched') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Revision History -->
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
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2.5"
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

        <!-- Safe Import Dialog -->
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
                    <DialogDescription>{{
                        t('admin.languages.importDescription')
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitImport">
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

        <!-- Rollback Overrides Confirmation Dialog -->
        <ConfirmActionDialog
            :open="rollbackLang !== null"
            :title="t('admin.languages.rollbackTitle')"
            :description="
                t('admin.languages.rollbackDescription', {
                    language: rollbackLang?.native_name ?? '',
                })
            "
            :confirm-label="t('admin.languages.rollbackConfirm')"
            destructive
            @update:open="(v) => !v && (rollbackLang = null)"
            @confirm="confirmRollback"
        />
    </div>
</template>
