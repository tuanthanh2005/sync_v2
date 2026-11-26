<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Globe2,
    Languages,
    MessageSquarePlus,
    ShieldAlert,
    TrendingUp,
    X,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AiAssistantAndTranslationController from '@/actions/App/Http/Controllers/Admin/Ai/AiAssistantAndTranslationController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { international as adminSeoInternational } from '@/routes/admin/seo';

const props = defineProps<{
    control: {
        emergency_stop: boolean;
        compliance: Record<string, boolean>;
    };
    recentPosts: Array<{
        id: number;
        title: string;
        language_code: string;
        content_origin: string;
        author_username: string | null;
        author_is_ai: boolean;
    }>;
    aiTranslations: Array<{
        id: number;
        entity_type: string;
        entity_id: number;
        source_locale: string;
        target_locale: string;
        translated_title: string | null;
        localized_slug: string;
        status: string;
        review_status: string;
        provider: string;
    }>;
    seoOpportunities: Array<{
        id: number;
        type: string;
        priority: string;
        locale: string | null;
        query: string | null;
        title: string;
        description: string;
    }>;
    replyTasks: Array<{
        id: number;
        post_id: number;
        post_title: string;
        agent_name: string | null;
        mode: string;
        language_code: string;
        draft_reply: string | null;
        status: string;
        published_comment_id: number | null;
        error_message: string | null;
    }>;
    assessments: Array<{
        id: number;
        entity_type: string;
        entity_id: number;
        risk_category: string;
        risk_level: string;
        reason: string;
        suggested_action: string;
        review_required: boolean;
        status: string;
    }>;
    agents: Array<{ id: number; display_name: string; username: string }>;
    languages: Array<{ code: string; native_name: string }>;
}>();

const { t } = useI18n();

const translateForm = useForm({
    post_id: props.recentPosts[0]?.id ?? 0,
    target_locale: 'vi',
});

const replyForm = useForm({
    post_id: props.recentPosts.find((p) => !p.author_is_ai)?.id ?? 0,
    agent_id: props.agents[0]?.id ?? 0,
    mode: 'manual_draft',
});

const modForm = useForm({
    entity_type: 'post',
    entity_id: props.recentPosts[0]?.id ?? 0,
});

function submitTranslate(): void {
    translateForm.post(
        AiAssistantAndTranslationController.translatePost.url(),
        {
            preserveScroll: true,
        },
    );
}

function submitReplyDraft(): void {
    replyForm.post(
        AiAssistantAndTranslationController.generateReplyDraft.url(),
        {
            preserveScroll: true,
        },
    );
}

function approveReply(id: number): void {
    router.post(
        AiAssistantAndTranslationController.approveReply.url(id),
        {},
        { preserveScroll: true },
    );
}

function rejectReply(id: number): void {
    router.post(
        AiAssistantAndTranslationController.rejectReply.url(id),
        {},
        { preserveScroll: true },
    );
}

function submitModerationAssess(): void {
    modForm.post(AiAssistantAndTranslationController.assessModeration.url(), {
        preserveScroll: true,
    });
}

function resolveAssessment(
    id: number,
    status: 'acknowledged' | 'dismissed',
): void {
    router.post(
        AiAssistantAndTranslationController.resolveAssessment.url(id),
        { status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.ai.assistTitle')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <Languages class="size-5 text-primary" />
                    {{ t('admin.ai.assistTitle') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.ai.assistSubtitle') }}
                </p>
            </div>
            <Button variant="outline" size="sm" as-child>
                <Link :href="adminSeoInternational.url()">
                    <Globe2 class="size-4" />
                    <span>{{ t('admin.nav.seoInternational') }}</span>
                </Link>
            </Button>
        </div>

        <!-- Multilingual AI Translation Center (Connected to SEO V2.0) -->
        <section class="space-y-4 rounded-xl border border-border bg-card p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Globe2 class="size-4 text-primary" />
                {{ t('admin.ai.translationCenterHeading') }}
            </h2>
            <form
                @submit.prevent="submitTranslate"
                class="grid gap-3 sm:grid-cols-3"
            >
                <div class="space-y-1">
                    <Label for="tr_post">Post</Label>
                    <select
                        id="tr_post"
                        v-model.number="translateForm.post_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option
                            v-for="p in recentPosts"
                            :key="p.id"
                            :value="p.id"
                        >
                            #{{ p.id }} [{{ p.language_code }}] {{ p.title }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="tr_locale">Target Language</Label>
                    <select
                        id="tr_locale"
                        v-model="translateForm.target_locale"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option
                            v-for="l in languages"
                            :key="l.code"
                            :value="l.code"
                        >
                            {{ l.native_name }} ({{ l.code }})
                        </option>
                    </select>
                    <InputError :message="translateForm.errors.target_locale" />
                </div>
                <div class="flex items-end">
                    <Button
                        type="submit"
                        size="sm"
                        class="w-full"
                        :disabled="
                            translateForm.processing ||
                            control.emergency_stop ||
                            recentPosts.length === 0
                        "
                    >
                        {{ t('admin.ai.translateBtn') }}
                    </Button>
                </div>
            </form>

            <div v-if="aiTranslations.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="py-2 pr-2">Entity</th>
                            <th class="px-2 py-2">Locale</th>
                            <th class="px-2 py-2">Title</th>
                            <th class="py-2 pl-2 text-right">SEO Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tr in aiTranslations.slice(0, 10)"
                            :key="tr.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-2 pr-2 font-mono">
                                {{ tr.entity_type }} #{{ tr.entity_id }}
                            </td>
                            <td class="px-2 py-2">
                                {{ tr.source_locale }} → {{ tr.target_locale }}
                            </td>
                            <td class="px-2 py-2 font-medium">
                                {{ tr.translated_title }}
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <Badge variant="outline">
                                    {{ tr.review_status }} / {{ tr.status }}
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- AI Comment Assistant & AI Moderation Assistant -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Comment Assistant -->
            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <MessageSquarePlus class="size-4 text-primary" />
                    {{ t('admin.ai.commentAssistantHeading') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.ai.commentAssistantDesc') }}
                </p>
                <form @submit.prevent="submitReplyDraft" class="space-y-2.5">
                    <select
                        v-model.number="replyForm.post_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-2 text-xs"
                    >
                        <option
                            v-for="p in recentPosts.filter(
                                (x) => !x.author_is_ai,
                            )"
                            :key="p.id"
                            :value="p.id"
                        >
                            #{{ p.id }} {{ p.title }} (@{{ p.author_username }})
                        </option>
                    </select>
                    <InputError :message="replyForm.errors.post_id" />
                    <div class="flex gap-2">
                        <select
                            v-model.number="replyForm.agent_id"
                            class="h-9 flex-1 rounded-lg border border-input bg-background px-2 text-xs"
                        >
                            <option
                                v-for="a in agents"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.display_name }}
                            </option>
                        </select>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                replyForm.processing ||
                                control.emergency_stop ||
                                agents.length === 0
                            "
                        >
                            Draft Reply
                        </Button>
                    </div>
                </form>

                <div class="space-y-2.5">
                    <div
                        v-for="r in replyTasks.slice(0, 8)"
                        :key="r.id"
                        class="rounded-lg border border-border p-3 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{
                                r.agent_name
                            }}</span>
                            <Badge variant="outline">{{ r.status }}</Badge>
                        </div>
                        <p class="mt-1 text-muted-foreground">
                            Post #{{ r.post_id }}: {{ r.post_title }}
                        </p>
                        <p v-if="r.draft_reply" class="mt-1 text-foreground">
                            {{ r.draft_reply }}
                        </p>
                        <div
                            v-if="r.status === 'pending_review'"
                            class="mt-2 flex gap-1.5"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="approveReply(r.id)"
                            >
                                <Check class="size-3.5" />
                                <span>Approve & Post</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="rejectReply(r.id)"
                            >
                                <X class="size-3.5" />
                                <span>Reject</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Moderation Assistant (Advisory Only) -->
            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <ShieldAlert class="size-4 text-primary" />
                    {{ t('admin.ai.moderationAssistantHeading') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('admin.ai.moderationAssistantDesc') }}
                </p>
                <form
                    @submit.prevent="submitModerationAssess"
                    class="flex gap-2"
                >
                    <select
                        v-model.number="modForm.entity_id"
                        class="h-9 flex-1 rounded-lg border border-input bg-background px-2 text-xs"
                    >
                        <option
                            v-for="p in recentPosts"
                            :key="p.id"
                            :value="p.id"
                        >
                            Post #{{ p.id }}: {{ p.title }}
                        </option>
                    </select>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="modForm.processing || control.emergency_stop"
                    >
                        Assess Risk
                    </Button>
                </form>

                <div class="space-y-2.5">
                    <div
                        v-for="a in assessments.slice(0, 8)"
                        :key="a.id"
                        class="rounded-lg border border-border p-3 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">
                                {{ a.entity_type }} #{{ a.entity_id }} ·
                                {{ a.risk_category }}
                            </span>
                            <Badge
                                :variant="
                                    a.risk_level === 'high' ||
                                    a.risk_level === 'critical'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                            >
                                {{ a.risk_level.toUpperCase() }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-foreground/90">{{ a.reason }}</p>
                        <p class="mt-0.5 text-muted-foreground">
                            Suggested: {{ a.suggested_action }} ({{ a.status }})
                        </p>
                        <div
                            v-if="a.status === 'pending_review'"
                            class="mt-2 flex gap-1.5"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="resolveAssessment(a.id, 'acknowledged')"
                            >
                                Acknowledge
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="resolveAssessment(a.id, 'dismissed')"
                            >
                                Dismiss
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Real SEO V2.0 Keyword Opportunities -->
        <section
            v-if="seoOpportunities.length > 0"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <TrendingUp class="size-4 text-primary" />
                {{ t('admin.ai.seoOpportunitiesHeading') }}
            </h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="opp in seoOpportunities.slice(0, 6)"
                    :key="opp.id"
                    class="rounded-lg border border-border p-3 text-xs"
                >
                    <p class="font-semibold text-foreground">{{ opp.title }}</p>
                    <p class="mt-1 text-muted-foreground">
                        {{ opp.description }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
