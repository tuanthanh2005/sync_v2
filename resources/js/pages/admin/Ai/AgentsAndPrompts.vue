<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Bot, FileCode2, History, Plus, Sparkles } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AiAgentsAndPromptsController from '@/actions/App/Http/Controllers/Admin/Ai/AiAgentsAndPromptsController';
import AiBadge from '@/components/ai/AiBadge.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    agents: Array<{
        id: number;
        user_id: number;
        display_name: string;
        username: string;
        ai_disclosure: string;
        system_prompt: string;
        personality: string | null;
        writing_style: string | null;
        expertise: string | null;
        default_language: string;
        allowed_topics: string[];
        restricted_topics: string[];
        preferred_model_id: number | null;
        preferred_model_name: string | null;
        daily_post_limit: number;
        daily_comment_limit: number;
        monthly_token_budget: number;
        is_enabled: boolean;
        drafts_count: number;
        profile_url: string;
    }>;
    prompts: Array<{
        id: number;
        slug: string;
        name: string;
        prompt_type: string;
        prompt_type_label: string;
        agent_id: number | null;
        agent_name: string | null;
        system_instruction: string;
        user_template: string;
        active_version_id: number | null;
        is_enabled: boolean;
        versions: Array<{
            id: number;
            version_number: number;
            changelog: string | null;
            created_at: string | null;
        }>;
    }>;
    models: Array<{
        id: number;
        model_id: string;
        display_name: string;
        is_paid_only: boolean;
        is_enabled: boolean;
    }>;
    languages: Array<{ code: string; native_name: string }>;
    promptTypes: Array<{ value: string; label: string }>;
    safeVariables: string[];
}>();

const { t } = useI18n();

const agentForm = useForm({
    display_name: '',
    username: '',
    ai_disclosure: 'Official SyncViet AI Agent — Automated Assistant',
    expertise: '',
    default_language: 'en',
    preferred_model_id: props.models.find((m) => m.is_enabled)?.id ?? null,
    allowed_topics: '',
    restricted_topics: 'politics, medical diagnosis, trading signals',
    daily_post_limit: 2,
    daily_comment_limit: 5,
    monthly_token_budget: 500000,
    system_prompt:
        'You are an official transparent SyncViet AI Agent. Provide accurate, educational content and never claim to be a human.',
});

const promptForm = useForm({
    name: '',
    prompt_type: 'generate_post',
    agent_id: null as number | null,
    system_instruction:
        'Write clear, original, fact-checked educational community content for SyncViet.',
    user_template:
        'Topic: {{topic}}\nLanguage: {{target_language}}\nStyle: {{writing_style}}\nSource Material:\n{{source_material}}',
    changelog: 'Initial version',
});

function seedStarters(): void {
    router.post(
        AiAgentsAndPromptsController.seedStarterAgents.url(),
        {},
        { preserveScroll: true },
    );
}

function submitAgent(): void {
    agentForm.post(AiAgentsAndPromptsController.storeAgent.url(), {
        preserveScroll: true,
        onSuccess: () => {
            agentForm.reset('display_name', 'username', 'expertise');
        },
    });
}

function toggleAgent(id: number, enabled: boolean): void {
    router.patch(
        AiAgentsAndPromptsController.updateAgent.url(id),
        { is_enabled: !enabled },
        { preserveScroll: true },
    );
}

function submitPrompt(): void {
    promptForm.post(AiAgentsAndPromptsController.storePrompt.url(), {
        preserveScroll: true,
        onSuccess: () => {
            promptForm.reset('name', 'changelog');
        },
    });
}

function rollbackVersion(templateId: number, versionId: number): void {
    router.post(
        AiAgentsAndPromptsController.rollbackPrompt.url([
            templateId,
            versionId,
        ]),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.ai.agentsTitle')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <Sparkles class="size-5 text-primary" />
                    {{ t('admin.ai.agentsTitle') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.ai.agentsSubtitle') }}
                </p>
            </div>
            <Button size="sm" @click="seedStarters">
                <Bot class="size-4" />
                <span>{{ t('admin.ai.provisionStarterAgents') }}</span>
            </Button>
        </div>

        <!-- Official AI Agents Grid -->
        <section class="space-y-3 rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold">
                {{ t('admin.ai.activeAgentsHeading') }} ({{ agents.length }})
            </h2>
            <div
                v-if="agents.length === 0"
                class="py-6 text-center text-xs text-muted-foreground"
            >
                {{ t('admin.ai.noAgentsYet') }}
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="a in agents"
                    :key="a.id"
                    class="flex flex-col justify-between gap-3 rounded-xl border border-border p-4"
                >
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="a.profile_url"
                                class="font-semibold text-foreground hover:text-primary"
                            >
                                {{ a.display_name }}
                            </Link>
                            <AiBadge :disclosure="a.ai_disclosure" size="sm" />
                            <Badge
                                :variant="a.is_enabled ? 'default' : 'outline'"
                            >
                                {{ a.is_enabled ? 'Active' : 'Paused' }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            @{{ a.username }} · {{ a.default_language }} ·
                            {{ a.preferred_model_name || 'Default Model' }}
                        </p>
                        <p
                            v-if="a.expertise"
                            class="text-xs text-foreground/85"
                        >
                            {{ a.expertise }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-border/60 pt-2.5 text-xs text-muted-foreground"
                    >
                        <span>
                            {{ a.drafts_count }} drafts · Max
                            {{ a.daily_post_limit }} posts/d ·
                            {{ a.daily_comment_limit }} replies/d
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="toggleAgent(a.id, a.is_enabled)"
                        >
                            {{
                                a.is_enabled
                                    ? t('common.disable')
                                    : t('common.enable')
                            }}
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Create New AI Agent -->
        <form
            @submit.prevent="submitAgent"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Plus class="size-4 text-primary" />
                {{ t('admin.ai.createAgentHeading') }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label for="agent_name">Display Name</Label>
                    <Input
                        id="agent_name"
                        v-model="agentForm.display_name"
                        required
                        placeholder="Cloud Architect AI"
                    />
                    <InputError :message="agentForm.errors.display_name" />
                </div>
                <div class="space-y-1">
                    <Label for="agent_username">Username</Label>
                    <Input
                        id="agent_username"
                        v-model="agentForm.username"
                        required
                        placeholder="cloud_architect_ai"
                    />
                    <InputError :message="agentForm.errors.username" />
                </div>
                <div class="space-y-1">
                    <Label for="agent_lang">Default Language</Label>
                    <select
                        id="agent_lang"
                        v-model="agentForm.default_language"
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
                    <Label for="agent_model">Preferred Model</Label>
                    <select
                        id="agent_model"
                        v-model="agentForm.preferred_model_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option :value="null">Auto (Fast Group)</option>
                        <option v-for="m in models" :key="m.id" :value="m.id">
                            {{ m.display_name }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <Label for="agent_expertise">Expertise</Label>
                    <Input
                        id="agent_expertise"
                        v-model="agentForm.expertise"
                        placeholder="Linux, Hosting, Web Performance"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="agent_restricted">Restricted Topics</Label>
                    <Input
                        id="agent_restricted"
                        v-model="agentForm.restricted_topics"
                    />
                </div>
            </div>
            <div class="space-y-1">
                <Label for="agent_prompt">System Prompt</Label>
                <textarea
                    id="agent_prompt"
                    v-model="agentForm.system_prompt"
                    rows="3"
                    required
                    class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                />
                <InputError :message="agentForm.errors.system_prompt" />
            </div>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="agentForm.processing"
                >
                    {{ t('admin.ai.createAgentBtn') }}
                </Button>
            </div>
        </form>

        <!-- Prompt Template Library + Version History -->
        <section class="space-y-4 rounded-xl border border-border bg-card p-5">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <FileCode2 class="size-4 text-primary" />
                    {{ t('admin.ai.promptLibraryTitle') }}
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ t('admin.ai.safeVariablesLabel') }}:
                    <code
                        v-for="v in safeVariables"
                        :key="v"
                        class="mx-0.5 rounded bg-muted px-1 py-0.5 text-[11px]"
                        >{{ '{' + '{' + v + '}' + '}' }}</code
                    >
                </p>
            </div>

            <div class="space-y-3">
                <div
                    v-for="p in prompts"
                    :key="p.id"
                    class="rounded-xl border border-border p-4 text-xs"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <p class="text-sm font-semibold text-foreground">
                                {{ p.name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ p.prompt_type_label }} · {{ p.slug }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Button
                                v-for="v in p.versions.slice(0, 3)"
                                :key="v.id"
                                size="sm"
                                :variant="
                                    v.id === p.active_version_id
                                        ? 'default'
                                        : 'outline'
                                "
                                :disabled="v.id === p.active_version_id"
                                @click="rollbackVersion(p.id, v.id)"
                            >
                                <History class="size-3" />
                                <span>v{{ v.version_number }}</span>
                            </Button>
                        </div>
                    </div>
                    <p
                        class="mt-2 line-clamp-2 rounded bg-muted/40 p-2 font-mono text-[11px] text-muted-foreground"
                    >
                        {{ p.user_template }}
                    </p>
                </div>
            </div>

            <form
                @submit.prevent="submitPrompt"
                class="space-y-3 border-t border-border pt-4"
            >
                <h3
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('admin.ai.newPromptHeading') }}
                </h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="prompt_name">Template Name</Label>
                        <Input
                            id="prompt_name"
                            v-model="promptForm.name"
                            required
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="prompt_type">Type</Label>
                        <select
                            id="prompt_type"
                            v-model="promptForm.prompt_type"
                            class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                        >
                            <option
                                v-for="pt in promptTypes"
                                :key="pt.value"
                                :value="pt.value"
                            >
                                {{ pt.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="sys_instr">System Instruction</Label>
                        <textarea
                            id="sys_instr"
                            v-model="promptForm.system_instruction"
                            rows="3"
                            required
                            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="user_tpl">User Prompt Template</Label>
                        <textarea
                            id="user_tpl"
                            v-model="promptForm.user_template"
                            rows="3"
                            required
                            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs"
                        />
                    </div>
                </div>
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="promptForm.processing"
                    >
                        {{ t('admin.ai.savePromptBtn') }}
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
