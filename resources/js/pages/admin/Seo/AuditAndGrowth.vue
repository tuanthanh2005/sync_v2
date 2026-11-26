<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Hash,
    Lightbulb,
    Settings,
    ShieldCheck,
    Sparkles,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import SeoInternationalAndAuditController from '@/actions/App/Http/Controllers/Admin/Seo/SeoInternationalAndAuditController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    runs: Array<{
        id: number;
        trigger_type: string;
        status: string;
        technical_health_score: number;
        urls_scanned: number;
        critical_count: number;
        high_count: number;
        medium_count: number;
        low_count: number;
        info_count: number;
        finished_at: string | null;
    }>;
    issues: Array<{
        id: number;
        check_code: string;
        severity: string;
        url: string | null;
        locale: string | null;
        title: string;
        description: string;
        recommendation: string | null;
    }>;
    recommendations: Array<{
        id: number;
        type: string;
        priority: string;
        locale: string | null;
        query: string | null;
        title: string;
        description: string;
    }>;
    topTopics: Array<{
        id: number;
        name: string;
        slug: string;
        posts_count: number;
        is_featured: boolean;
    }>;
    settings: {
        seo_enabled: boolean;
        allow_indexing_in_non_production: boolean;
        min_post_words: number;
        min_post_chars: number;
        min_topic_posts: number;
        index_user_profiles: boolean;
        gsc_daily_inspection_limit: number;
    };
}>();

const { t } = useI18n();
const settingsForm = useForm({ ...props.settings });

function runAudit(): void {
    router.post(
        SeoInternationalAndAuditController.runAuditNow.url(),
        {},
        { preserveScroll: true },
    );
}

function resolveIssue(id: number): void {
    router.post(
        SeoInternationalAndAuditController.resolveIssue.url(id),
        {},
        { preserveScroll: true },
    );
}

function refreshRecommendations(): void {
    router.post(
        SeoInternationalAndAuditController.refreshRecommendations.url(),
        {},
        { preserveScroll: true },
    );
}

function dismissRecommendation(id: number): void {
    router.post(
        SeoInternationalAndAuditController.dismissRecommendation.url(id),
        {},
        { preserveScroll: true },
    );
}

function saveSettings(): void {
    settingsForm.post(SeoInternationalAndAuditController.updateSettings.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.seo.auditTitle')" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1
                    class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                >
                    {{ t('admin.seo.auditTitle') }}
                </h1>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.seo.scoreDisclaimer') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="refreshRecommendations"
                >
                    <Sparkles class="size-3.5" />
                    <span>Refresh Keyword Intelligence</span>
                </Button>
                <Button size="sm" @click="runAudit">
                    <ShieldCheck class="size-3.5" />
                    <span>Run SEO Audit Now</span>
                </Button>
            </div>
        </div>

        <!-- Audit Runs & Open Issues -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2 class="text-sm font-semibold text-foreground">
                Open Technical Audit Issues ({{ issues.length }})
            </h2>
            <p v-if="issues.length === 0" class="text-xs text-muted-foreground">
                No open technical issues found. Run an audit to scan current URL
                registry, hreflang, redirects, and sitemaps.
            </p>
            <div v-else class="space-y-2.5">
                <div
                    v-for="issue in issues"
                    :key="issue.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border/80 p-3 text-xs"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    issue.severity === 'critical' ||
                                    issue.severity === 'high'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                            >
                                {{ issue.severity }}
                            </Badge>
                            <span class="font-semibold text-foreground">{{
                                issue.title
                            }}</span>
                        </div>
                        <p class="text-muted-foreground">
                            {{ issue.description }}
                        </p>
                        <p v-if="issue.recommendation" class="text-primary">
                            Fix: {{ issue.recommendation }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="resolveIssue(issue.id)"
                    >
                        <CheckCircle2 class="size-3.5" />
                        <span>Resolve</span>
                    </Button>
                </div>
            </div>
        </section>

        <!-- Keyword Intelligence & Topic Clusters -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Lightbulb class="size-4 text-primary" />
                    <span>Keyword Intelligence &amp; CTR Recommendations</span>
                </h2>
                <p
                    v-if="recommendations.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No recommendations yet. Connect Google Search Console and
                    sync Search Analytics to generate data-backed opportunities.
                </p>
                <div v-else class="space-y-2.5">
                    <div
                        v-for="rec in recommendations"
                        :key="rec.id"
                        class="flex items-start justify-between gap-2 rounded-lg border border-border/70 p-3 text-xs"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold text-foreground">
                                {{ rec.title }}
                            </p>
                            <p class="mt-0.5 text-muted-foreground">
                                {{ rec.description }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="dismissRecommendation(rec.id)"
                        >
                            Dismiss
                        </Button>
                    </div>
                </div>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Hash class="size-4 text-primary" />
                    <span>Active Topic Clusters</span>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-for="topic in topTopics"
                        :key="topic.id"
                        :href="`/topics/${topic.slug}`"
                        target="_blank"
                        class="rounded-full border border-border bg-muted/30 px-3 py-1 text-xs font-medium text-foreground hover:border-primary/40 hover:text-primary"
                    >
                        #{{ topic.name }} ({{ topic.posts_count }})
                    </a>
                </div>
            </section>
        </div>

        <!-- SEO Quality Gate & Engine Settings -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Settings class="size-4 text-primary" />
                <span>SEO Quality Gate &amp; Engine Guardrails</span>
            </h2>
            <form
                @submit.prevent="saveSettings"
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <label class="flex items-center gap-2 text-xs font-medium">
                    <input v-model="settingsForm.seo_enabled" type="checkbox" />
                    <span>Enable SEO Engine globally</span>
                </label>
                <label class="flex items-center gap-2 text-xs font-medium">
                    <input
                        v-model="settingsForm.allow_indexing_in_non_production"
                        type="checkbox"
                    />
                    <span>Allow indexing outside production</span>
                </label>
                <label class="flex items-center gap-2 text-xs font-medium">
                    <input
                        v-model="settingsForm.index_user_profiles"
                        type="checkbox"
                    />
                    <span>Allow indexing active public profiles</span>
                </label>
                <div class="space-y-1">
                    <Label>Min Post Words for Index</Label>
                    <Input
                        v-model.number="settingsForm.min_post_words"
                        type="number"
                        min="3"
                        max="500"
                    />
                </div>
                <div class="space-y-1">
                    <Label>Min Post Characters for Index</Label>
                    <Input
                        v-model.number="settingsForm.min_post_chars"
                        type="number"
                        min="20"
                        max="2000"
                    />
                </div>
                <div class="space-y-1">
                    <Label>Min Posts per Topic Cluster</Label>
                    <Input
                        v-model.number="settingsForm.min_topic_posts"
                        type="number"
                        min="1"
                        max="100"
                    />
                </div>
                <div class="space-y-1">
                    <Label>Daily GSC Inspection Quota Limit</Label>
                    <Input
                        v-model.number="settingsForm.gsc_daily_inspection_limit"
                        type="number"
                        min="50"
                        max="2000"
                    />
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="settingsForm.processing"
                    >
                        Save SEO Engine Settings
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
