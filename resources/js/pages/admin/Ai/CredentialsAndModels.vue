<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Cpu,
    Key,
    Plus,
    RefreshCw,
    ShieldCheck,
    Trash2,
    Wifi,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AiCredentialsAndModelsController from '@/actions/App/Http/Controllers/Admin/Ai/AiCredentialsAndModelsController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';

type ProviderItem = {
    id: number;
    slug: string;
    name: string;
    driver: string;
    base_url: string;
    is_enabled: boolean;
    compliance_status: string;
    compliance_notes: string | null;
};

type CredentialItem = {
    id: number;
    provider_id: number;
    provider_name: string;
    name: string;
    project_id: string;
    masked_key: string;
    key_last4: string;
    billing_tier: 'free' | 'paid';
    allowed_models: string[];
    rpm_limit: number;
    tpm_limit: number;
    rpd_limit: number;
    monthly_budget_cents: number;
    priority: number;
    is_enabled: boolean;
    health_status: string;
    last_tested_at: string | null;
    last_used_at: string | null;
    cooldown_until: string | null;
    last_error: string | null;
};

type ModelItem = {
    id: number;
    provider_id: number;
    provider_name: string;
    model_id: string;
    display_name: string;
    role_group: string;
    max_input_tokens: number;
    max_output_tokens: number;
    default_temperature: number;
    supports_structured_output: boolean;
    supports_thinking: boolean;
    is_paid_only: boolean;
    is_enabled: boolean;
    input_price_per_million_cents: number;
    output_price_per_million_cents: number;
    pricing_verified: boolean;
    updated_at: string | null;
};

const props = defineProps<{
    providers: ProviderItem[];
    credentials: CredentialItem[];
    models: ModelItem[];
    quotaTracking: Array<{
        id: number;
        provider_name: string;
        project_id: string;
        model_id: string;
        window_type: string;
        window_key: string;
        requests_count: number;
        tokens_count: number;
        last_rate_limited_at: string | null;
        waiting_for_quota_until: string | null;
    }>;
    roleGroups: Array<{ value: string; label: string }>;
}>();

const { t, locale } = useI18n();
const deleteTarget = ref<CredentialItem | null>(null);
const deleting = ref(false);

const credForm = useForm({
    provider_id: props.providers[0]?.id ?? 1,
    name: '',
    project_id: 'gcp-project-1',
    api_key: '',
    billing_tier: 'free' as 'free' | 'paid',
    rpm_limit: 10,
    tpm_limit: 250000,
    rpd_limit: 250,
    monthly_budget_cents: 0,
    priority: 10,
});

function submitCredential(): void {
    credForm.post(AiCredentialsAndModelsController.storeCredential.url(), {
        preserveScroll: true,
        onSuccess: () => {
            credForm.reset('name', 'api_key');
        },
    });
}

function toggleCredential(c: CredentialItem): void {
    router.patch(
        AiCredentialsAndModelsController.updateCredential.url(c.id),
        { is_enabled: !c.is_enabled },
        { preserveScroll: true },
    );
}

function testCredential(c: CredentialItem): void {
    router.post(
        AiCredentialsAndModelsController.testCredential.url(c.id),
        {},
        { preserveScroll: true },
    );
}

function syncModels(c: CredentialItem): void {
    router.post(
        AiCredentialsAndModelsController.syncModels.url(c.id),
        {},
        { preserveScroll: true },
    );
}

function confirmDelete(): void {
    if (!deleteTarget.value) {
        return;
    }
    deleting.value = true;
    router.delete(
        AiCredentialsAndModelsController.destroyCredential.url(
            deleteTarget.value.id,
        ),
        {
            preserveScroll: true,
            onFinish: () => {
                deleting.value = false;
                deleteTarget.value = null;
            },
        },
    );
}

function toggleModelEnabled(m: ModelItem): void {
    router.patch(
        AiCredentialsAndModelsController.updateModel.url(m.id),
        {
            is_enabled: !m.is_enabled,
            is_paid_only: m.is_paid_only,
            role_group: m.role_group,
            default_temperature: m.default_temperature,
            input_price_per_million_cents: m.input_price_per_million_cents,
            output_price_per_million_cents: m.output_price_per_million_cents,
            pricing_verified: m.pricing_verified,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.ai.credentialsTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1 class="flex items-center gap-2 text-xl font-bold">
                <Key class="size-5 text-primary" />
                {{ t('admin.ai.credentialsTitle') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.ai.credentialsSubtitle') }}
            </p>
        </div>

        <!-- Add Encrypted API Credential Form -->
        <form
            @submit.prevent="submitCredential"
            class="space-y-4 rounded-xl border border-border bg-card p-5"
        >
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Plus class="size-4 text-primary" />
                {{ t('admin.ai.addCredentialHeading') }}
            </h2>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label for="provider_id">{{
                        t('admin.ai.provider')
                    }}</Label>
                    <select
                        id="provider_id"
                        v-model.number="credForm.provider_id"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option
                            v-for="p in providers"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }} ({{ p.driver }})
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="cred_name">{{
                        t('admin.ai.credentialName')
                    }}</Label>
                    <Input
                        id="cred_name"
                        v-model="credForm.name"
                        required
                        placeholder="Gemini Free Key #1"
                    />
                    <InputError :message="credForm.errors.name" />
                </div>
                <div class="space-y-1">
                    <Label for="project_id">{{
                        t('admin.ai.projectId')
                    }}</Label>
                    <Input
                        id="project_id"
                        v-model="credForm.project_id"
                        required
                        placeholder="gcp-project-alpha"
                    />
                    <InputError :message="credForm.errors.project_id" />
                </div>
                <div class="space-y-1">
                    <Label for="billing_tier">{{
                        t('admin.ai.billingTier')
                    }}</Label>
                    <select
                        id="billing_tier"
                        v-model="credForm.billing_tier"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option value="free">Free Tier</option>
                        <option value="paid">
                            Paid Tier (Billing Enabled)
                        </option>
                    </select>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="space-y-1 sm:col-span-2">
                    <Label for="api_key">{{ t('admin.ai.apiKeyLabel') }}</Label>
                    <Input
                        id="api_key"
                        v-model="credForm.api_key"
                        type="password"
                        autocomplete="off"
                        required
                        placeholder="AIza..."
                    />
                    <InputError :message="credForm.errors.api_key" />
                </div>
                <div class="space-y-1">
                    <Label for="rpm_limit">RPM</Label>
                    <Input
                        id="rpm_limit"
                        v-model.number="credForm.rpm_limit"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="rpd_limit">RPD</Label>
                    <Input
                        id="rpd_limit"
                        v-model.number="credForm.rpd_limit"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="priority">{{ t('admin.ai.priority') }}</Label>
                    <Input
                        id="priority"
                        v-model.number="credForm.priority"
                        type="number"
                        min="1"
                    />
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <ShieldCheck class="size-4 text-primary" />
                    {{ t('admin.ai.encryptionNote') }}
                </p>
                <Button type="submit" size="sm" :disabled="credForm.processing">
                    {{ t('admin.ai.saveCredential') }}
                </Button>
            </div>
        </form>

        <!-- Stored Credentials List (Masked Only) -->
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-3 text-sm font-semibold">
                {{ t('admin.ai.configuredKeys') }} ({{ credentials.length }})
            </h2>
            <div
                v-if="credentials.length === 0"
                class="py-6 text-center text-xs text-muted-foreground"
            >
                {{ t('admin.ai.noCredentialsYet') }}
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="py-2 pr-3">Name / Project</th>
                            <th class="px-2 py-2">Masked Key</th>
                            <th class="px-2 py-2">Tier</th>
                            <th class="px-2 py-2">Limits (RPM/RPD)</th>
                            <th class="px-2 py-2">Health</th>
                            <th class="py-2 pl-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in credentials"
                            :key="c.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-2.5 pr-3">
                                <p class="font-semibold text-foreground">
                                    {{ c.name }}
                                </p>
                                <p
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ c.project_id }} · P{{ c.priority }}
                                </p>
                            </td>
                            <td class="px-2 py-2.5 font-mono">
                                {{ c.masked_key }}
                            </td>
                            <td class="px-2 py-2.5">
                                <Badge
                                    :variant="
                                        c.billing_tier === 'paid'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ c.billing_tier.toUpperCase() }}
                                </Badge>
                            </td>
                            <td class="px-2 py-2.5">
                                {{ c.rpm_limit }} / {{ c.rpd_limit }}
                            </td>
                            <td class="px-2 py-2.5">
                                <Badge
                                    :variant="
                                        c.health_status === 'healthy'
                                            ? 'default'
                                            : c.health_status === 'auth_error'
                                              ? 'destructive'
                                              : 'outline'
                                    "
                                >
                                    {{ c.health_status }}
                                </Badge>
                                <p
                                    v-if="c.last_error"
                                    class="mt-0.5 max-w-[200px] truncate text-[10px] text-destructive"
                                    :title="c.last_error"
                                >
                                    {{ c.last_error }}
                                </p>
                            </td>
                            <td class="py-2.5 pl-2 text-right">
                                <div class="flex justify-end gap-1.5">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="testCredential(c)"
                                    >
                                        <Wifi class="size-3.5" />
                                        <span>Test</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="syncModels(c)"
                                    >
                                        <RefreshCw class="size-3.5" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="toggleCredential(c)"
                                    >
                                        {{
                                            c.is_enabled
                                                ? t('common.disable')
                                                : t('common.enable')
                                        }}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="text-destructive"
                                        @click="deleteTarget = c"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Verified Active Model Registry -->
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <Cpu class="size-4 text-primary" />
                {{ t('admin.ai.modelRegistry') }}
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="py-2 pr-3">Model</th>
                            <th class="px-2 py-2">Role Group</th>
                            <th class="px-2 py-2">Capabilities</th>
                            <th class="px-2 py-2">Billing</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="py-2 pl-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in models"
                            :key="m.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-2.5 pr-3">
                                <p class="font-semibold text-foreground">
                                    {{ m.display_name }}
                                </p>
                                <p
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ m.model_id }}
                                </p>
                            </td>
                            <td class="px-2 py-2.5">{{ m.role_group }}</td>
                            <td class="px-2 py-2.5">
                                <span v-if="m.supports_structured_output"
                                    >JSON</span
                                >
                                <span v-if="m.supports_thinking">
                                    · Thinking</span
                                >
                            </td>
                            <td class="px-2 py-2.5">
                                <Badge
                                    :variant="
                                        m.is_paid_only ? 'outline' : 'secondary'
                                    "
                                >
                                    {{
                                        m.is_paid_only
                                            ? 'Paid Only'
                                            : 'Free / Paid'
                                    }}
                                </Badge>
                            </td>
                            <td class="px-2 py-2.5">
                                <Badge
                                    :variant="
                                        m.is_enabled ? 'default' : 'outline'
                                    "
                                >
                                    {{ m.is_enabled ? 'Enabled' : 'Disabled' }}
                                </Badge>
                            </td>
                            <td class="py-2.5 pl-2 text-right">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="toggleModelEnabled(m)"
                                >
                                    <CheckCircle2 class="size-3.5" />
                                    <span>{{
                                        m.is_enabled
                                            ? t('common.disable')
                                            : t('common.enable')
                                    }}</span>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Shared Project + Model Quota Tracking -->
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-2 text-sm font-semibold">
                {{ t('admin.ai.sharedQuotaTitle') }}
            </h2>
            <p class="mb-3 text-xs text-muted-foreground">
                {{ t('admin.ai.sharedQuotaDesc') }}
            </p>
            <div
                v-if="quotaTracking.length === 0"
                class="py-4 text-center text-xs text-muted-foreground"
            >
                {{ t('admin.ai.noQuotaWindowsYet') }}
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="py-2 pr-2">Project ID</th>
                            <th class="px-2 py-2">Model</th>
                            <th class="px-2 py-2">Window</th>
                            <th class="px-2 py-2">Req / Tokens</th>
                            <th class="py-2 pl-2 text-right">Wait Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="q in quotaTracking"
                            :key="q.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-2 pr-2 font-mono">
                                {{ q.project_id }}
                            </td>
                            <td class="px-2 py-2 font-mono">
                                {{ q.model_id }}
                            </td>
                            <td class="px-2 py-2">
                                {{ q.window_type }} ({{ q.window_key }})
                            </td>
                            <td class="px-2 py-2">
                                {{ q.requests_count }} req ·
                                {{ q.tokens_count.toLocaleString() }} tok
                            </td>
                            <td class="py-2 pl-2 text-right">
                                {{
                                    q.waiting_for_quota_until
                                        ? formatDateTime(
                                              q.waiting_for_quota_until,
                                              locale,
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmActionDialog
            :open="deleteTarget !== null"
            :title="t('admin.ai.deleteCredentialTitle')"
            :description="t('admin.ai.deleteCredentialDesc')"
            :confirm-label="t('common.delete')"
            destructive
            :processing="deleting"
            @update:open="
                (v: boolean) => {
                    if (!v) deleteTarget = null;
                }
            "
            @confirm="confirmDelete"
        />
    </div>
</template>
