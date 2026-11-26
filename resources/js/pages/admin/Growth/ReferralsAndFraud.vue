<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Download,
    Gift,
    Play,
    ShieldAlert,
    Undo2,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GrowthReferralsAndFraudController from '@/actions/App/Http/Controllers/Admin/Growth/GrowthReferralsAndFraudController';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    statusFilter: string;
    referrals: Array<{
        id: number;
        code: string;
        referrer_username: string | null;
        referee_username: string | null;
        referee_verified: boolean;
        status: string;
        status_label: string;
        status_reason: string | null;
        risk_score: number;
        risk_signals: string[];
    }>;
    fraudReviews: Array<{
        id: number;
        referral_id: number | null;
        username: string | null;
        risk_score: number;
        signals: string[];
        status: string;
        decision_note: string | null;
    }>;
    rules: Array<{
        id: number;
        slug: string;
        name: string;
        required_qualified_referrals: number;
        cumulative_premium_days: number;
        is_active: boolean;
    }>;
    rewards: Array<{
        id: number;
        username: string | null;
        rule_name: string | null;
        days_granted: number;
        source: string;
        status: string;
        note: string | null;
        revoke_reason: string | null;
    }>;
    codes: Array<{
        id: number;
        code: string;
        username: string | null;
        is_active: boolean;
        visits_count: number;
        registrations_count: number;
        qualified_count: number;
    }>;
}>();

const { t } = useI18n();

const reasonAction = ref<{
    type: 'referral' | 'revoke_reward';
    id: number;
    decision?: 'approve' | 'reject' | 'revoke';
    title: string;
} | null>(null);
const actionProcessing = ref(false);

const ruleForm = useForm({
    name: '',
    required_qualified_referrals: 15,
    cumulative_premium_days: 45,
});

const grantForm = useForm({
    username: '',
    days: 7,
    source: 'referral_reward',
    reason: '',
});

function runQualification(): void {
    router.post(
        GrowthReferralsAndFraudController.runQualificationNow.url(),
        {},
        { preserveScroll: true },
    );
}

function submitReason(reason: string): void {
    if (!reasonAction.value) {
        return;
    }
    actionProcessing.value = true;
    const act = reasonAction.value;
    if (act.type === 'referral') {
        router.post(
            GrowthReferralsAndFraudController.reviewReferral.url(act.id),
            { decision: act.decision, reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    actionProcessing.value = false;
                    reasonAction.value = null;
                },
            },
        );
    } else {
        router.post(
            GrowthReferralsAndFraudController.revokeReward.url(act.id),
            { reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    actionProcessing.value = false;
                    reasonAction.value = null;
                },
            },
        );
    }
}

function submitRule(): void {
    ruleForm.post(GrowthReferralsAndFraudController.storeRule.url(), {
        preserveScroll: true,
        onSuccess: () => ruleForm.reset('name'),
    });
}

function toggleRule(id: number, active: boolean): void {
    router.patch(
        GrowthReferralsAndFraudController.updateRule.url(id),
        { is_active: !active },
        { preserveScroll: true },
    );
}

function submitManualGrant(): void {
    grantForm.post(GrowthReferralsAndFraudController.manualGrant.url(), {
        preserveScroll: true,
        onSuccess: () => grantForm.reset('username', 'reason'),
    });
}

function toggleCode(id: number): void {
    router.patch(
        GrowthReferralsAndFraudController.toggleCode.url(id),
        { reason: 'Toggled from Admin Growth Center' },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.growth.referralsTitle')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <Gift class="size-5 text-primary" />
                    {{ t('admin.growth.referralsTitle') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('admin.growth.referralsSubtitle') }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button size="sm" @click="runQualification">
                    <Play class="size-4" />
                    <span>Run Qualification</span>
                </Button>
                <Button size="sm" variant="outline" as-child>
                    <a
                        :href="
                            GrowthReferralsAndFraudController.exportReferralsCsv.url()
                        "
                    >
                        <Download class="size-4" />
                        <span>CSV</span>
                    </a>
                </Button>
            </div>
        </div>

        <!-- Referrals & Fraud Review Table -->
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <ShieldAlert class="size-4 text-primary" />
                Referrals & Manual Review Queue ({{ referrals.length }})
            </h2>
            <div
                v-if="referrals.length === 0"
                class="py-6 text-center text-xs text-muted-foreground"
            >
                No referrals attributed yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="py-2 pr-2">Code / Referrer</th>
                            <th class="px-2 py-2">Referee</th>
                            <th class="px-2 py-2">Risk</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="py-2 pl-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in referrals"
                            :key="r.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-2 pr-2">
                                <span class="font-mono font-semibold">{{
                                    r.code
                                }}</span>
                                <span class="text-muted-foreground">
                                    (@{{ r.referrer_username }})</span
                                >
                            </td>
                            <td class="px-2 py-2">
                                @{{ r.referee_username }}
                                <Badge
                                    v-if="r.referee_verified"
                                    variant="secondary"
                                    class="ml-1"
                                    >Verified</Badge
                                >
                            </td>
                            <td class="px-2 py-2">
                                <Badge
                                    :variant="
                                        r.risk_score >= 50
                                            ? 'destructive'
                                            : 'outline'
                                    "
                                >
                                    {{ r.risk_score }}/100
                                </Badge>
                            </td>
                            <td class="px-2 py-2">{{ r.status_label }}</td>
                            <td class="py-2 pl-2 text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            reasonAction = {
                                                type: 'referral',
                                                id: r.id,
                                                decision: 'approve',
                                                title: 'Approve Referral',
                                            }
                                        "
                                    >
                                        <Check class="size-3.5" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="
                                            reasonAction = {
                                                type: 'referral',
                                                id: r.id,
                                                decision: 'reject',
                                                title: 'Reject Referral',
                                            }
                                        "
                                    >
                                        <X class="size-3.5" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="text-destructive"
                                        @click="
                                            reasonAction = {
                                                type: 'referral',
                                                id: r.id,
                                                decision: 'revoke',
                                                title: 'Revoke Referral',
                                            }
                                        "
                                    >
                                        <Undo2 class="size-3.5" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Cumulative Milestone Rules & Manual Grant / Revoke -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold">
                    Cumulative Reward Milestone Rules
                </h2>
                <ul class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="rule in rules"
                        :key="rule.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <span>
                            <strong>{{ rule.name }}</strong> —
                            {{ rule.required_qualified_referrals }} refs →
                            {{ rule.cumulative_premium_days }}d total
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="toggleRule(rule.id, rule.is_active)"
                        >
                            {{ rule.is_active ? 'Active' : 'Paused' }}
                        </Button>
                    </li>
                </ul>
                <form
                    @submit.prevent="submitRule"
                    class="grid gap-2 border-t border-border pt-3 sm:grid-cols-3"
                >
                    <Input
                        v-model="ruleForm.name"
                        required
                        placeholder="Milestone Name"
                    />
                    <Input
                        v-model.number="ruleForm.required_qualified_referrals"
                        type="number"
                        min="1"
                    />
                    <div class="flex gap-1.5">
                        <Input
                            v-model.number="ruleForm.cumulative_premium_days"
                            type="number"
                            min="1"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="ruleForm.processing"
                            >Add</Button
                        >
                    </div>
                </form>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold">
                    Manual Promotional Grant & Reward Clawback
                </h2>
                <form @submit.prevent="submitManualGrant" class="space-y-2.5">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <Label for="mg_user">Username</Label>
                            <Input
                                id="mg_user"
                                v-model="grantForm.username"
                                required
                                placeholder="member_username"
                            />
                            <InputError :message="grantForm.errors.username" />
                        </div>
                        <div>
                            <Label for="mg_days">Days</Label>
                            <Input
                                id="mg_days"
                                v-model.number="grantForm.days"
                                type="number"
                                min="1"
                                max="365"
                            />
                        </div>
                    </div>
                    <Input
                        v-model="grantForm.reason"
                        required
                        placeholder="Mandatory audit reason..."
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="grantForm.processing"
                    >
                        Grant Promotional Premium
                    </Button>
                </form>

                <ul
                    class="divide-y divide-border/60 border-t border-border pt-2 text-xs"
                >
                    <li
                        v-for="rw in rewards.slice(0, 8)"
                        :key="rw.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <span>
                            @{{ rw.username }} · +{{ rw.days_granted }}d ({{
                                rw.status
                            }})
                        </span>
                        <Button
                            v-if="rw.status === 'granted'"
                            size="sm"
                            variant="ghost"
                            class="text-destructive"
                            @click="
                                reasonAction = {
                                    type: 'revoke_reward',
                                    id: rw.id,
                                    title: 'Revoke Promotional Reward',
                                }
                            "
                        >
                            Revoke
                        </Button>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Top Referral Codes Abuse Control -->
        <section
            v-if="codes.length > 0"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="mb-3 text-sm font-semibold">Referral Codes Control</h2>
            <div class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="c in codes.slice(0, 12)"
                    :key="c.id"
                    class="flex items-center justify-between rounded-lg border border-border p-2.5"
                >
                    <div>
                        <p class="font-mono font-semibold">{{ c.code }}</p>
                        <p class="text-muted-foreground">
                            @{{ c.username }} · {{ c.qualified_count }}/{{
                                c.registrations_count
                            }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="toggleCode(c.id)"
                    >
                        {{ c.is_active ? 'Disable' : 'Enable' }}
                    </Button>
                </div>
            </div>
        </section>

        <ReasonDialog
            :open="reasonAction !== null"
            :title="reasonAction?.title || 'Confirm Action'"
            description="Provide a clear audit reason. This action will be permanently recorded in the Growth activity log."
            :confirm-label="t('dialog.confirm')"
            :processing="actionProcessing"
            @update:open="
                (v: boolean) => {
                    if (!v) reasonAction = null;
                }
            "
            @confirm="submitReason"
        />
    </div>
</template>
