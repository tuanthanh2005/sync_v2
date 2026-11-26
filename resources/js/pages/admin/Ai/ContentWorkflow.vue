<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    Check,
    Download,
    Globe,
    ListChecks,
    Send,
    Sparkles,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AiContentWorkflowController from '@/actions/App/Http/Controllers/Admin/Ai/AiContentWorkflowController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';

type DraftItem = {
    id: number;
    title: string;
    body: string;
    summary: string | null;
    language_code: string;
    content_type: string;
    category: string;
    suggested_tags: string[];
    seo_title: string | null;
    seo_description: string | null;
    status: string;
    status_label: string;
    quality_status: string;
    quality_score: number;
    agent_name: string | null;
    model_name: string | null;
    reviewer_username: string | null;
    published_post_id: number | null;
    published_post_url: string | null;
    scheduled_for: string | null;
    error_message: string | null;
    created_at: string | null;
};

const props = defineProps<{
    filters: { status: string; language: string; search: string };
    emergencyStopped: boolean;
    drafts: DraftItem[];
    plans: Array<{
        id: number;
        name: string;
        agent_name: string | null;
        target_language: string;
        topic: string;
        posts_per_day: number;
        is_active: boolean;
        last_generated_at: string | null;
    }>;
    sources: Array<{
        id: number;
        title: string;
        url: string;
        publisher: string | null;
        source_type: string;
        verification_status: string;
    }>;
    schedules: Array<{
        id: number;
        draft_id: number;
        draft_title: string | null;
        language_code: string | null;
        agent_name: string | null;
        scheduled_for: string;
        timezone: string;
        status: string;
        published_post_id: number | null;
        last_error: string | null;
    }>;
    agents: Array<{
        id: number;
        display_name: string;
        username: string;
        default_language: string;
    }>;
    communities: Array<{ id: number; name: string }>;
    languages: Array<{ code: string; native_name: string }>;
    categories: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const { t, locale } = useI18n();
const selectedDraftIds = ref<number[]>([]);
const urlPlaceholder = 'https://docs.example.org/guide';

const genForm = useForm({
    agent_id: props.agents[0]?.id ?? 0,
    topic: '',
    language_code: 'en',
    content_type: 'knowledge',
    category: props.categories[0]?.value ?? 'technology',
    community_id: null as number | null,
    source_id: null as number | null,
    seo_context: '',
});

const sourceForm = useForm({
    title: '',
    url: '',
    publisher: '',
    source_type: 'documentation',
    excerpt: '',
});

const planForm = useForm({
    name: '',
    agent_id: props.agents[0]?.id ?? 0,
    community_id: null as number | null,
    target_language: 'en',
    content_type: 'knowledge',
    category: props.categories[0]?.value ?? 'technology',
    topic: '',
    posts_per_day: 1,
});

function submitGenerate(): void {
    genForm.post(AiContentWorkflowController.generateNow.url(), {
        preserveScroll: true,
        onSuccess: () => genForm.reset('topic', 'seo_context'),
    });
}

function submitSource(): void {
    sourceForm.post(AiContentWorkflowController.storeSource.url(), {
        preserveScroll: true,
        onSuccess: () =>
            sourceForm.reset('title', 'url', 'publisher', 'excerpt'),
    });
}

function submitPlan(): void {
    planForm.post(AiContentWorkflowController.storePlan.url(), {
        preserveScroll: true,
        onSuccess: () => planForm.reset('name', 'topic'),
    });
}

function reviewDraft(id: number, decision: 'approved' | 'rejected'): void {
    router.post(
        AiContentWorkflowController.reviewDraft.url(id),
        { decision },
        { preserveScroll: true },
    );
}

function publishDraft(id: number): void {
    router.post(
        AiContentWorkflowController.publishDraft.url(id),
        {},
        { preserveScroll: true },
    );
}

function bulkReview(decision: 'approved' | 'rejected'): void {
    if (selectedDraftIds.value.length === 0) {
        return;
    }
    router.post(
        AiContentWorkflowController.bulkReview.url(),
        { ids: selectedDraftIds.value, decision },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedDraftIds.value = [];
            },
        },
    );
}

function togglePlan(id: number): void {
    router.patch(
        AiContentWorkflowController.togglePlan.url(id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.ai.contentTitle')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <ListChecks class="size-5 text-primary" />
                    {{ t('admin.ai.contentTitle') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.ai.contentSubtitle') }}
                </p>
            </div>
            <Button variant="outline" size="sm" as-child>
                <a :href="AiContentWorkflowController.exportDraftsCsv.url()">
                    <Download class="size-4" />
                    <span>CSV</span>
                </a>
            </Button>
        </div>

        <!-- Generate Draft Form -->
        <form
            @submit.prevent="submitGenerate"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Sparkles class="size-4 text-primary" />
                {{ t('admin.ai.generateDraftHeading') }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label for="gen_agent">AI Agent</Label>
                    <select
                        id="gen_agent"
                        v-model.number="genForm.agent_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option v-for="a in agents" :key="a.id" :value="a.id">
                            {{ a.display_name }} (@{{ a.username }})
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="gen_lang">Language</Label>
                    <select
                        id="gen_lang"
                        v-model="genForm.language_code"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option
                            v-for="l in languages"
                            :key="l.code"
                            :value="l.code"
                        >
                            {{ l.native_name }} ({{ l.code }})
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="gen_cat">Category</Label>
                    <select
                        id="gen_cat"
                        v-model="genForm.category"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option
                            v-for="c in categories"
                            :key="c.value"
                            :value="c.value"
                        >
                            {{ c.label }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="gen_src">Verified Source (Optional)</Label>
                    <select
                        id="gen_src"
                        v-model="genForm.source_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option :value="null">
                            None (Evergreen Knowledge)
                        </option>
                        <option v-for="s in sources" :key="s.id" :value="s.id">
                            {{ s.title }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="space-y-1 sm:col-span-2">
                    <Label for="gen_topic">{{
                        t('admin.ai.topicLabel')
                    }}</Label>
                    <Input
                        id="gen_topic"
                        v-model="genForm.topic"
                        required
                        placeholder="Practical guide to database indexing in MySQL 8.4"
                    />
                    <InputError :message="genForm.errors.topic" />
                </div>
                <div class="flex items-end">
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="
                            genForm.processing ||
                            emergencyStopped ||
                            agents.length === 0
                        "
                    >
                        <Sparkles class="size-4" />
                        <span>{{ t('admin.ai.generateBtn') }}</span>
                    </Button>
                </div>
            </div>
        </form>

        <!-- Human Review Queue & Drafts -->
        <section class="space-y-3 rounded-xl border border-border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">
                    {{ t('admin.ai.reviewQueueHeading') }} ({{ drafts.length }})
                </h2>
                <div
                    v-if="selectedDraftIds.length > 0"
                    class="flex items-center gap-2"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="bulkReview('approved')"
                    >
                        <Check class="size-3.5" />
                        <span>Approve ({{ selectedDraftIds.length }})</span>
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="bulkReview('rejected')"
                    >
                        <X class="size-3.5" />
                        <span>Reject</span>
                    </Button>
                </div>
            </div>

            <div
                v-if="drafts.length === 0"
                class="py-6 text-center text-xs text-muted-foreground"
            >
                {{ t('admin.ai.noDraftsYet') }}
            </div>
            <div v-else class="space-y-3">
                <div
                    v-for="d in drafts"
                    :key="d.id"
                    class="rounded-xl border border-border p-4 text-xs"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="flex items-start gap-2.5">
                            <input
                                v-model="selectedDraftIds"
                                type="checkbox"
                                :value="d.id"
                                class="mt-1 size-4 accent-primary"
                            />
                            <div class="space-y-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ d.title }}
                                    </p>
                                    <Badge variant="secondary">
                                        {{ d.language_code }}
                                    </Badge>
                                    <Badge
                                        :variant="
                                            d.status === 'published' ||
                                            d.status === 'approved'
                                                ? 'default'
                                                : d.status === 'failed' ||
                                                    d.status === 'rejected'
                                                  ? 'destructive'
                                                  : 'outline'
                                        "
                                    >
                                        {{ d.status_label }}
                                    </Badge>
                                    <Badge
                                        :variant="
                                            d.quality_status === 'passed'
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                    >
                                        Quality {{ d.quality_score }}/100
                                    </Badge>
                                </div>
                                <p class="text-muted-foreground">
                                    {{ d.agent_name }} · {{ d.category }} ·
                                    {{ d.model_name || '—' }}
                                </p>
                                <p
                                    v-if="d.summary || d.body"
                                    class="line-clamp-2 text-foreground/85"
                                >
                                    {{ d.summary || d.body }}
                                </p>
                                <p
                                    v-if="d.error_message"
                                    class="text-destructive"
                                >
                                    {{ d.error_message }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <Button
                                v-if="
                                    d.status === 'pending_review' ||
                                    d.status === 'draft'
                                "
                                size="sm"
                                variant="outline"
                                @click="reviewDraft(d.id, 'approved')"
                            >
                                <Check class="size-3.5" />
                                <span>Approve</span>
                            </Button>
                            <Button
                                v-if="
                                    d.status === 'pending_review' ||
                                    d.status === 'draft'
                                "
                                size="sm"
                                variant="ghost"
                                @click="reviewDraft(d.id, 'rejected')"
                            >
                                <X class="size-3.5" />
                                <span>Reject</span>
                            </Button>
                            <Button
                                v-if="
                                    d.status === 'approved' &&
                                    !d.published_post_id
                                "
                                size="sm"
                                :disabled="emergencyStopped"
                                @click="publishDraft(d.id)"
                            >
                                <Send class="size-3.5" />
                                <span>Publish Now</span>
                            </Button>
                            <Button
                                v-if="d.published_post_url"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="d.published_post_url">
                                    View Post #{{ d.published_post_id }}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Verified Research Sources & Content Plans -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Globe class="size-4 text-primary" />
                    {{ t('admin.ai.verifiedSourcesHeading') }}
                </h2>
                <form @submit.prevent="submitSource" class="space-y-2.5">
                    <Input
                        v-model="sourceForm.title"
                        required
                        placeholder="Official Laravel 13 Release Notes"
                    />
                    <Input
                        v-model="sourceForm.url"
                        type="url"
                        required
                        :placeholder="urlPlaceholder"
                    />
                    <InputError :message="sourceForm.errors.url" />
                    <textarea
                        v-model="sourceForm.excerpt"
                        rows="2"
                        placeholder="Verified excerpt / key facts..."
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="sourceForm.processing"
                    >
                        {{ t('admin.ai.saveSourceBtn') }}
                    </Button>
                </form>
                <ul class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="s in sources.slice(0, 8)"
                        :key="s.id"
                        class="py-2"
                    >
                        <p class="font-medium text-foreground">{{ s.title }}</p>
                        <p class="truncate text-muted-foreground">
                            {{ s.url }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <CalendarClock class="size-4 text-primary" />
                    {{ t('admin.ai.contentPlansHeading') }}
                </h2>
                <form @submit.prevent="submitPlan" class="space-y-2.5">
                    <Input
                        v-model="planForm.name"
                        required
                        placeholder="Weekly Developer AI Guides"
                    />
                    <Input
                        v-model="planForm.topic"
                        required
                        placeholder="PHP 8.4, Laravel 13 & Vue 3 Best Practices"
                    />
                    <div class="grid grid-cols-2 gap-2">
                        <select
                            v-model.number="planForm.agent_id"
                            class="h-9 rounded-lg border border-input bg-background px-2 text-xs"
                        >
                            <option
                                v-for="a in agents"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.display_name }}
                            </option>
                        </select>
                        <select
                            v-model="planForm.target_language"
                            class="h-9 rounded-lg border border-input bg-background px-2 text-xs"
                        >
                            <option
                                v-for="l in languages"
                                :key="l.code"
                                :value="l.code"
                            >
                                {{ l.code }}
                            </option>
                        </select>
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="planForm.processing || agents.length === 0"
                    >
                        {{ t('admin.ai.createPlanBtn') }}
                    </Button>
                </form>
                <ul class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="p in plans"
                        :key="p.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium text-foreground">
                                {{ p.name }}
                            </p>
                            <p class="truncate text-muted-foreground">
                                {{ p.agent_name }} · {{ p.target_language }} ·
                                {{ p.posts_per_day }}/day
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="togglePlan(p.id)"
                        >
                            {{ p.is_active ? 'Pause' : 'Resume' }}
                        </Button>
                    </li>
                </ul>

                <div
                    v-if="schedules.length > 0"
                    class="border-t border-border pt-3"
                >
                    <h3
                        class="mb-2 text-xs font-semibold text-muted-foreground"
                    >
                        {{ t('admin.ai.recentSchedules') }}
                    </h3>
                    <ul class="space-y-1.5 text-xs">
                        <li
                            v-for="sc in schedules.slice(0, 6)"
                            :key="sc.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="truncate">{{ sc.draft_title }}</span>
                            <span class="shrink-0 text-muted-foreground">
                                {{ sc.status }} ·
                                {{ formatDateTime(sc.scheduled_for, locale) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</template>
