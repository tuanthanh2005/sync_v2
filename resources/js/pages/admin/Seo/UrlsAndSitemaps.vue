<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Download,
    ExternalLink,
    FileCode2,
    Link2,
    RefreshCw,
    Search,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SeoUrlsAndSitemapsController from '@/actions/App/Http/Controllers/Admin/Seo/SeoUrlsAndSitemapsController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RegistryRow = {
    id: number;
    url: string;
    path: string;
    entity_type: string;
    locale: string;
    index_eligibility: string;
    status: string;
    exclusion_reason: string | null;
    http_status: number;
    quality_score: number;
    is_in_sitemap: boolean;
    sitemap_shard_key: string | null;
    is_noindex_forced: boolean;
    inspection_verdict: string | null;
};

const props = defineProps<{
    filters: {
        search: string;
        entity_type: string;
        locale: string;
        eligibility: string;
        status: string;
    };
    enabledLocales: string[];
    urls: {
        data: RegistryRow[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    sitemaps: Array<{
        id: number;
        name: string;
        type: string;
        locale: string | null;
        total_urls: number;
        total_shards: number;
        status: string;
        is_dirty: boolean;
        last_generated_at: string | null;
        gsc_status: string | null;
        shards: Array<{
            shard_key: string;
            url_count: number;
            byte_size: number;
        }>;
    }>;
    sitemapIndexUrl: string;
    recentInspections: Array<{
        id: number;
        url: string | null;
        locale: string | null;
        inspection_status: string;
        verdict: string | null;
        coverage_state: string | null;
        inspected_at: string | null;
        error_message: string | null;
    }>;
    redirects: Array<{
        id: number;
        source_path: string;
        target_url: string;
        status_code: number;
        is_active: boolean;
        hit_count: number;
        reason: string | null;
    }>;
    robotsPreview: string;
    robotsExtraDisallow: string;
}>();

const { t } = useI18n();
const searchQuery = ref(props.filters.search);
const entityFilter = ref(props.filters.entity_type);
const localeFilter = ref(props.filters.locale);
const eligibilityFilter = ref(props.filters.eligibility);
const selectedIds = ref<number[]>([]);

const redirectForm = useForm({
    source_path: '',
    target_url: '',
    status_code: 301,
    reason: '',
});

const robotsForm = useForm({
    robots_extra_disallow: props.robotsExtraDisallow,
});

function applyFilters(): void {
    router.get(
        SeoUrlsAndSitemapsController.index.url({
            query: {
                search: searchQuery.value || undefined,
                entity_type: entityFilter.value || undefined,
                locale: localeFilter.value || undefined,
                eligibility: eligibilityFilter.value || undefined,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

function toggleSelect(id: number): void {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((item) => item !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
}

function runBulk(action: string): void {
    if (selectedIds.value.length === 0) {
        return;
    }
    router.post(
        SeoUrlsAndSitemapsController.bulkAction.url(),
        { ids: selectedIds.value, action },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = [];
            },
        },
    );
}

function backfillAll(): void {
    router.post(
        SeoUrlsAndSitemapsController.backfillRegistry.url(),
        {},
        { preserveScroll: true },
    );
}

function rebuildSitemaps(all: boolean): void {
    router.post(
        SeoUrlsAndSitemapsController.rebuildSitemaps.url(),
        { all },
        { preserveScroll: true },
    );
}

function runInspectionBatch(): void {
    router.post(
        SeoUrlsAndSitemapsController.runInspectionBatch.url(),
        {},
        { preserveScroll: true },
    );
}

function saveRedirect(): void {
    redirectForm.post(SeoUrlsAndSitemapsController.storeRedirect.url(), {
        preserveScroll: true,
        onSuccess: () => redirectForm.reset(),
    });
}

function deleteRedirect(id: number): void {
    router.delete(SeoUrlsAndSitemapsController.destroyRedirect.url(id), {
        preserveScroll: true,
    });
}

function saveRobots(): void {
    robotsForm.post(SeoUrlsAndSitemapsController.updateRobots.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.seo.urlsTitle')" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1
                    class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                >
                    {{ t('admin.seo.urlsTitle') }}
                </h1>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        t('admin.seoCenter.urlsTracked', {
                            count: urls.total.toLocaleString(),
                        })
                    }}
                    ·
                    <a
                        :href="sitemapIndexUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-primary hover:underline"
                    >
                        <span>/sitemap.xml</span>
                        <ExternalLink class="size-3" />
                    </a>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a
                        :href="
                            SeoUrlsAndSitemapsController.exportRegistryCsv.url()
                        "
                    >
                        <Download class="size-3.5" />
                        <span>{{ t('admin.seoCenter.exportCsv') }}</span>
                    </a>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="rebuildSitemaps(true)"
                >
                    <RefreshCw class="size-3.5" />
                    <span>{{ t('admin.seoCenter.rebuildAllSitemaps') }}</span>
                </Button>
                <Button size="sm" @click="backfillAll">
                    {{ t('admin.seoCenter.syncAllEntities') }}
                </Button>
            </div>
        </div>

        <!-- URL Registry Table -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <form
                @submit.prevent="applyFilters"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
            >
                <Input
                    v-model="searchQuery"
                    :placeholder="t('admin.seoCenter.searchUrlPlaceholder')"
                />
                <select
                    v-model="entityFilter"
                    class="h-10 rounded-lg border border-input bg-background px-3 text-xs text-foreground"
                >
                    <option value="">
                        {{ t('admin.seoCenter.allEntityTypes') }}
                    </option>
                    <option value="page">
                        {{ t('admin.seoCenter.entityPage') }}
                    </option>
                    <option value="post">
                        {{ t('admin.seoCenter.entityPost') }}
                    </option>
                    <option value="translation">
                        {{ t('admin.seoCenter.entityTranslation') }}
                    </option>
                    <option value="community">
                        {{ t('admin.seoCenter.entityCommunity') }}
                    </option>
                    <option value="topic">
                        {{ t('admin.seoCenter.entityTopic') }}
                    </option>
                    <option value="profile">
                        {{ t('admin.seoCenter.entityProfile') }}
                    </option>
                    <option value="policy">
                        {{ t('admin.seoCenter.entityPolicy') }}
                    </option>
                </select>
                <select
                    v-model="localeFilter"
                    class="h-10 rounded-lg border border-input bg-background px-3 text-xs text-foreground"
                >
                    <option value="">
                        {{ t('admin.seoCenter.allLocales') }}
                    </option>
                    <option
                        v-for="code in enabledLocales"
                        :key="code"
                        :value="code"
                    >
                        {{ code }}
                    </option>
                </select>
                <select
                    v-model="eligibilityFilter"
                    class="h-10 rounded-lg border border-input bg-background px-3 text-xs text-foreground"
                >
                    <option value="">
                        {{ t('admin.seoCenter.allEligibility') }}
                    </option>
                    <option value="eligible">
                        {{ t('admin.seoCenter.eligible') }}
                    </option>
                    <option value="excluded">
                        {{ t('admin.seoCenter.excluded') }}
                    </option>
                    <option value="pending_quality_review">
                        {{ t('admin.seoCenter.pendingQualityReview') }}
                    </option>
                </select>
                <Button type="submit" size="sm" class="h-10">
                    <Search class="size-3.5" />
                    <span>{{ t('admin.seoCenter.filter') }}</span>
                </Button>
            </form>

            <div
                v-if="selectedIds.length > 0"
                class="flex flex-wrap items-center gap-2 rounded-lg bg-primary-soft/50 p-3 text-xs"
            >
                <span class="font-semibold text-primary">{{
                    t('admin.seoCenter.selectedCount', {
                        count: selectedIds.length,
                    })
                }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="runBulk('force_noindex')"
                    >{{ t('admin.seoCenter.forceNoindex') }}</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="runBulk('allow_index')"
                    >{{ t('admin.seoCenter.allowIndex') }}</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="runBulk('queue_inspection')"
                    >{{ t('admin.seoCenter.queueInspection') }}</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="runBulk('queue_indexnow')"
                    >{{ t('admin.seoCenter.queueIndexNow') }}</Button
                >
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-2" />
                            <th class="py-2 pr-3 font-medium">
                                {{ t('admin.seoCenter.pathType') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.locale') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.eligibility') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.status') }}
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                {{ t('admin.seoCenter.quality') }}
                            </th>
                            <th class="py-2 pl-2 text-right font-medium">
                                {{ t('admin.seoCenter.sitemap') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="row in urls.data" :key="row.id">
                            <td class="py-2 pr-2">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(row.id)"
                                    @change="toggleSelect(row.id)"
                                />
                            </td>
                            <td class="max-w-xs py-2 pr-3">
                                <p class="truncate font-mono text-foreground">
                                    {{ row.path }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ row.entity_type }} ·
                                    {{
                                        t('admin.seoCenter.httpStatus', {
                                            code: row.http_status,
                                        })
                                    }}
                                    <span v-if="row.exclusion_reason">
                                        · {{ row.exclusion_reason }}</span
                                    >
                                </p>
                            </td>
                            <td class="px-2 py-2 font-mono uppercase">
                                {{ row.locale }}
                            </td>
                            <td class="px-2 py-2">
                                <Badge
                                    :variant="
                                        row.index_eligibility === 'eligible'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ row.index_eligibility }}
                                </Badge>
                            </td>
                            <td class="px-2 py-2">{{ row.status }}</td>
                            <td class="px-2 py-2 text-right">
                                {{ row.quality_score }}
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <Badge
                                    :variant="
                                        row.is_in_sitemap
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        row.is_in_sitemap
                                            ? t('admin.seoCenter.inSitemap')
                                            : t('admin.seoCenter.no')
                                    }}
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Sharded Sitemaps & URL Inspection -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between">
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <FileCode2 class="size-4 text-primary" />
                        <span>{{ t('admin.seoCenter.shardedSitemaps') }}</span>
                    </h2>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="rebuildSitemaps(false)"
                    >
                        {{ t('admin.seoCenter.rebuildDirty') }}
                    </Button>
                </div>
                <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                    <div
                        v-for="sm in sitemaps"
                        :key="sm.id"
                        class="flex items-center justify-between gap-2 rounded-lg border border-border/70 p-2.5 text-xs"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate font-mono font-medium text-foreground"
                            >
                                {{ sm.name }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{
                                    t('admin.seoCenter.sitemapUrlsAndShards', {
                                        urls: sm.total_urls,
                                        shards: sm.total_shards,
                                    })
                                }}
                            </p>
                        </div>
                        <Badge :variant="sm.is_dirty ? 'secondary' : 'default'">
                            {{
                                sm.is_dirty
                                    ? t('admin.seoCenter.dirty')
                                    : t('admin.seoCenter.ready')
                            }}
                        </Badge>
                    </div>
                </div>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ t('admin.seoCenter.urlInspectionQueue') }}
                    </h2>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="runInspectionBatch"
                    >
                        {{ t('admin.seoCenter.runPriorityBatch') }}
                    </Button>
                </div>
                <p
                    v-if="recentInspections.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('admin.seoCenter.noInspectionsYet') }}
                </p>
                <ul v-else class="max-h-72 space-y-2 overflow-y-auto text-xs">
                    <li
                        v-for="insp in recentInspections"
                        :key="insp.id"
                        class="rounded-lg border border-border/70 p-2.5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-mono text-foreground">{{
                                insp.url
                            }}</span>
                            <Badge variant="outline">{{
                                insp.verdict || insp.inspection_status
                            }}</Badge>
                        </div>
                        <p
                            v-if="insp.coverage_state || insp.error_message"
                            class="mt-1 text-[11px] text-muted-foreground"
                        >
                            {{ insp.coverage_state || insp.error_message }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Redirects & Dynamic Robots.txt -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Link2 class="size-4 text-primary" />
                    <span>{{
                        t('admin.seoCenter.managedRedirectsTitle')
                    }}</span>
                </h2>
                <form
                    @submit.prevent="saveRedirect"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div class="space-y-1">
                        <Label for="redir-src">{{
                            t('admin.seoCenter.sourcePath')
                        }}</Label>
                        <Input
                            id="redir-src"
                            v-model="redirectForm.source_path"
                            :placeholder="
                                t('admin.seoCenter.sourcePathPlaceholder')
                            "
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="redir-dst">{{
                            t('admin.seoCenter.targetPath')
                        }}</Label>
                        <Input
                            id="redir-dst"
                            v-model="redirectForm.target_url"
                            :placeholder="
                                t('admin.seoCenter.targetPathPlaceholder')
                            "
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="redir-code">{{
                            t('admin.seoCenter.httpCode')
                        }}</Label>
                        <select
                            id="redir-code"
                            v-model.number="redirectForm.status_code"
                            class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option :value="301">
                                {{ t('admin.seoCenter.http301') }}
                            </option>
                            <option :value="302">
                                {{ t('admin.seoCenter.http302') }}
                            </option>
                            <option :value="307">
                                {{ t('admin.seoCenter.http307') }}
                            </option>
                            <option :value="308">
                                {{ t('admin.seoCenter.http308') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="redir-reason">{{
                            t('admin.seoCenter.reason')
                        }}</Label>
                        <Input
                            id="redir-reason"
                            v-model="redirectForm.reason"
                            :placeholder="
                                t('admin.seoCenter.redirectReasonPlaceholder')
                            "
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="redirectForm.processing"
                            >{{ t('admin.seoCenter.saveRedirect') }}</Button
                        >
                    </div>
                </form>

                <ul v-if="redirects.length > 0" class="space-y-2 text-xs">
                    <li
                        v-for="r in redirects"
                        :key="r.id"
                        class="flex items-center justify-between gap-2 rounded-lg border border-border/70 p-2.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-mono">
                                {{ r.source_path }} → {{ r.target_url }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{
                                    t('admin.seoCenter.redirectMeta', {
                                        code: r.status_code,
                                        hits: r.hit_count,
                                    })
                                }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-7 text-muted-foreground hover:text-destructive"
                            :aria-label="t('admin.seoCenter.deleteRedirect')"
                            @click="deleteRedirect(r.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </li>
                </ul>
            </section>

            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.seoCenter.dynamicRobotsTitle') }}
                </h2>
                <pre
                    class="max-h-52 overflow-auto rounded-lg bg-muted/40 p-3 font-mono text-[11px] text-foreground"
                    >{{ robotsPreview }}</pre>
                <form @submit.prevent="saveRobots" class="space-y-2">
                    <Label for="robots-extra">{{
                        t('admin.seoCenter.additionalDisallowPaths')
                    }}</Label>
                    <textarea
                        id="robots-extra"
                        v-model="robotsForm.robots_extra_disallow"
                        rows="3"
                        class="w-full rounded-lg border border-input bg-background p-2.5 font-mono text-xs"
                        :placeholder="
                            t('admin.seoCenter.robotsExtraPlaceholder')
                        "
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="robotsForm.processing"
                        >{{ t('admin.seoCenter.saveRobotsRules') }}</Button
                    >
                </form>
            </section>
        </div>
    </div>
</template>
