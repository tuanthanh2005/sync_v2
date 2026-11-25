<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Globe, Save, ShieldCheck, Sliders } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/currency';
import { formatDateTime } from '@/lib/formatters';
import { update as updateAdSenseRoute } from '@/routes/admin/monetization/adsense';
import { update as updateComplianceRoute } from '@/routes/admin/monetization/compliance';
import { update as updateGuardrailsRoute } from '@/routes/admin/monetization/guardrails';
import { update as updateMethodRoute } from '@/routes/admin/monetization/payment-methods';
import { confirm as confirmPaymentRoute } from '@/routes/admin/monetization/payments';
import type { PaymentTransactionItem } from '@/types';

type AdminPaymentMethod = {
    id: number;
    code: string;
    name: string;
    category: string;
    supported_currencies: string[];
    fee_percent_bps: number;
    fee_fixed_vnd: number;
    fee_fixed_usd_cents: number;
    is_enabled: boolean;
    is_live_ready: boolean;
    requires_legal_clearance: boolean;
    instructions: Record<string, string | number | boolean | null>;
};

type ComplianceRuleRow = {
    id: number;
    platform: string;
    allow_external_web_checkout: boolean;
    allow_premium_purchase: boolean;
    allow_banner_display: boolean;
    allow_boost_purchase: boolean;
    require_native_iap_for_digital: boolean;
    allowed_payment_methods: string[];
    policy_note: string | null;
};

const props = defineProps<{
    tab: string;
    paymentMethods: AdminPaymentMethod[];
    pendingOrders: Array<
        PaymentTransactionItem & { username: string; email: string }
    >;
    adSense: {
        feature_adsense: boolean;
        adsense_enabled: boolean;
        adsense_publisher_id: string;
        adsense_slots: Record<string, string>;
        adsense_allowed_pages: string[];
        adsense_consent_required: boolean;
        adsense_ads_txt_line: string;
        permitted_pages: string[];
    };
    complianceRules: ComplianceRuleRow[];
    webhookLogs: Array<{
        id: number;
        provider: string;
        payment_id: number | null;
        provider_reference: string | null;
        signature_valid: boolean;
        http_status: number;
        outcome: string;
        ip_address: string | null;
        error_message: string | null;
        created_at: string;
    }>;
    monetizationConfig: {
        usd_to_vnd_rate: number;
        max_pending_payments: number;
    };
}>();

const { t, locale } = useI18n();
const activeTab = ref(props.tab || 'methods');

const guardrailsForm = useForm({
    monetization_usd_to_vnd_rate: props.monetizationConfig.usd_to_vnd_rate,
    monetization_max_pending_payments:
        props.monetizationConfig.max_pending_payments,
});

const adSenseForm = useForm({
    adsense_enabled: props.adSense.adsense_enabled,
    adsense_publisher_id: props.adSense.adsense_publisher_id,
    adsense_slots: {
        sidebar: props.adSense.adsense_slots.sidebar ?? '',
        in_feed: props.adSense.adsense_slots.in_feed ?? '',
        explore: props.adSense.adsense_slots.explore ?? '',
        community: props.adSense.adsense_slots.community ?? '',
    },
    adsense_allowed_pages: [...props.adSense.adsense_allowed_pages],
    adsense_consent_required: props.adSense.adsense_consent_required,
    adsense_ads_txt_line: props.adSense.adsense_ads_txt_line,
});

const reconcileTargetId = ref<number | null>(null);
const reconcileForm = useForm({
    provider_reference: '',
    reason: '',
    password: '',
});

function saveMethod(m: AdminPaymentMethod): void {
    router.patch(
        updateMethodRoute.url(m.id),
        {
            name: m.name,
            fee_percent_bps: m.fee_percent_bps,
            fee_fixed_vnd: m.fee_fixed_vnd,
            fee_fixed_usd_cents: m.fee_fixed_usd_cents,
            is_enabled: m.is_enabled,
            is_live_ready: m.is_live_ready,
            instructions: m.instructions,
        },
        { preserveScroll: true },
    );
}

function saveGuardrails(): void {
    guardrailsForm.post(updateGuardrailsRoute.url(), { preserveScroll: true });
}

function saveAdSense(): void {
    adSenseForm.post(updateAdSenseRoute.url(), { preserveScroll: true });
}

function saveCompliance(rule: ComplianceRuleRow): void {
    router.patch(
        updateComplianceRoute.url(rule.id),
        {
            allow_external_web_checkout: rule.allow_external_web_checkout,
            allow_premium_purchase: rule.allow_premium_purchase,
            allow_banner_display: rule.allow_banner_display,
            allow_boost_purchase: rule.allow_boost_purchase,
            require_native_iap_for_digital: rule.require_native_iap_for_digital,
            allowed_payment_methods: rule.allowed_payment_methods,
            policy_note: rule.policy_note,
        },
        { preserveScroll: true },
    );
}

function submitManualConfirm(): void {
    if (reconcileTargetId.value === null) return;
    reconcileForm.post(confirmPaymentRoute.url(reconcileTargetId.value), {
        preserveScroll: true,
        onSuccess: () => {
            reconcileTargetId.value = null;
            reconcileForm.reset();
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.nav.monetizationSettings')" />

        <h1 class="flex items-center gap-2 text-xl font-bold text-foreground">
            <Sliders class="size-5 text-primary" />
            <span>{{ t('admin.nav.monetizationSettings') }}</span>
        </h1>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="tabKey in [
                    'methods',
                    'pending',
                    'adsense',
                    'compliance',
                    'webhooks',
                ]"
                :key="tabKey"
                type="button"
                size="sm"
                :variant="activeTab === tabKey ? 'default' : 'outline'"
                class="rounded-lg text-xs capitalize"
                @click="activeTab = tabKey"
            >
                {{ tabKey }}
            </Button>
        </div>

        <!-- TAB 1: Payment Methods & Guardrails -->
        <template v-if="activeTab === 'methods'">
            <section
                class="space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-bold text-foreground">
                    Global Financial Guardrails
                </h2>
                <form
                    @submit.prevent="saveGuardrails"
                    class="flex flex-wrap items-end gap-3 text-xs"
                >
                    <div class="grid gap-1">
                        <Label>USD → VND Snapshot Rate (Integer)</Label>
                        <Input
                            v-model.number="
                                guardrailsForm.monetization_usd_to_vnd_rate
                            "
                            type="number"
                            class="w-40"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label>Max Pending Orders / User</Label>
                        <Input
                            v-model.number="
                                guardrailsForm.monetization_max_pending_payments
                            "
                            type="number"
                            min="1"
                            max="10"
                            class="w-28"
                        />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="guardrailsForm.processing"
                    >
                        <Save class="size-3.5" />
                        <span>Save</span>
                    </Button>
                </form>
            </section>

            <section class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="m in paymentMethods"
                    :key="m.id"
                    class="space-y-2.5 rounded-xl border border-border bg-card p-4 text-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="font-bold text-foreground">
                                {{ m.name }}
                            </p>
                            <p
                                class="font-mono text-[10px] text-muted-foreground"
                            >
                                {{ m.code }} ·
                                {{ m.supported_currencies.join(', ') }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                m.requires_legal_clearance
                                    ? 'outline'
                                    : m.is_enabled
                                      ? 'success'
                                      : 'secondary'
                            "
                        >
                            {{
                                m.requires_legal_clearance
                                    ? 'Crypto (Legal Hold)'
                                    : m.is_enabled
                                      ? 'Enabled'
                                      : 'Disabled'
                            }}
                        </Badge>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <Label class="text-[10px]">Fee (bps)</Label>
                            <Input
                                v-model.number="m.fee_percent_bps"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div>
                            <Label class="text-[10px]">Fixed VND</Label>
                            <Input
                                v-model.number="m.fee_fixed_vnd"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div>
                            <Label class="text-[10px]">Fixed USD¢</Label>
                            <Input
                                v-model.number="m.fee_fixed_usd_cents"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-1.5">
                                <input v-model="m.is_enabled" type="checkbox" />
                                <span>Enabled</span>
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="m.is_live_ready"
                                    type="checkbox"
                                />
                                <span>Live Ready</span>
                            </label>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px]"
                            @click="saveMethod(m)"
                        >
                            Save
                        </Button>
                    </div>
                </div>
            </section>
        </template>

        <!-- TAB 2: Pending Orders Manual Reconciliation -->
        <section
            v-else-if="activeTab === 'pending'"
            class="space-y-4 rounded-2xl border border-border bg-card p-5"
        >
            <h2 class="text-sm font-bold text-foreground">
                Open Pending Orders ({{ pendingOrders.length }})
            </h2>
            <div v-if="pendingOrders.length > 0" class="divide-y divide-border">
                <div
                    v-for="p in pendingOrders"
                    :key="p.id"
                    class="space-y-2 py-3 text-xs"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <span class="font-mono font-bold text-foreground">
                                {{ p.order_code || `#${p.id}` }}
                            </span>
                            · @{{ p.username }} · {{ p.service_type_label }} ·
                            {{ p.provider.toUpperCase() }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-primary">
                                {{ formatMoney(p.amount, p.currency, locale) }}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                class="h-7 text-[11px]"
                                @click="reconcileTargetId = p.id"
                            >
                                <CheckCircle2 class="size-3" />
                                <span>Confirm Paid</span>
                            </Button>
                        </div>
                    </div>

                    <form
                        v-if="reconcileTargetId === p.id"
                        @submit.prevent="submitManualConfirm"
                        class="grid gap-2 rounded-xl border border-border bg-muted/30 p-3 sm:grid-cols-4"
                    >
                        <Input
                            v-model="reconcileForm.provider_reference"
                            placeholder="Bank / PayPal Tx Ref"
                            required
                        />
                        <Input
                            v-model="reconcileForm.reason"
                            placeholder="Verification note"
                            required
                        />
                        <Input
                            v-model="reconcileForm.password"
                            type="password"
                            placeholder="Admin password"
                            required
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="reconcileForm.processing"
                        >
                            Verify & Settle
                        </Button>
                        <InputError
                            class="sm:col-span-4"
                            :message="
                                reconcileForm.errors.password ||
                                reconcileForm.errors.provider_reference
                            "
                        />
                    </form>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                No pending payment orders waiting reconciliation.
            </p>
        </section>

        <!-- TAB 3: Controlled Google AdSense Config (NO RAW JS!) -->
        <section
            v-else-if="activeTab === 'adsense'"
            class="space-y-4 rounded-2xl border border-border bg-card p-5"
        >
            <div class="flex items-center gap-2">
                <Globe class="size-4 text-primary" />
                <h2 class="text-sm font-bold text-foreground">
                    Google AdSense Safe Controlled Configuration
                </h2>
            </div>
            <p class="text-xs text-muted-foreground">
                Arbitrary HTML/JavaScript injection is strictly forbidden. Only
                validated Publisher IDs (ca-pub-XXXXXXXXXXXXXXXX) and numeric
                slot IDs are accepted. Direct rented banners always take
                priority over AdSense.
            </p>
            <form @submit.prevent="saveAdSense" class="space-y-4 text-xs">
                <label class="flex items-center gap-2 font-semibold">
                    <input
                        v-model="adSenseForm.adsense_enabled"
                        type="checkbox"
                    />
                    <span>Enable Google AdSense on permitted public pages</span>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1">
                        <Label>Publisher ID (ca-pub-XXXXXXXXXXXXXXXX)</Label>
                        <Input
                            v-model="adSenseForm.adsense_publisher_id"
                            placeholder="ca-pub-1234567890123456"
                        />
                        <InputError
                            :message="adSenseForm.errors.adsense_publisher_id"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label>ads.txt Entry Line</Label>
                        <Input
                            v-model="adSenseForm.adsense_ads_txt_line"
                            placeholder="google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0"
                        />
                        <InputError
                            :message="adSenseForm.errors.adsense_ads_txt_line"
                        />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="grid gap-1">
                        <Label>Sidebar Slot ID</Label>
                        <Input v-model="adSenseForm.adsense_slots.sidebar" />
                    </div>
                    <div class="grid gap-1">
                        <Label>In-Feed Slot ID</Label>
                        <Input v-model="adSenseForm.adsense_slots.in_feed" />
                    </div>
                    <div class="grid gap-1">
                        <Label>Explore Slot ID</Label>
                        <Input v-model="adSenseForm.adsense_slots.explore" />
                    </div>
                    <div class="grid gap-1">
                        <Label>Community Slot ID</Label>
                        <Input v-model="adSenseForm.adsense_slots.community" />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="sm"
                    :disabled="adSenseForm.processing"
                >
                    <Save class="size-3.5" />
                    <span>Save AdSense Config</span>
                </Button>
            </form>
        </section>

        <!-- TAB 4: Platform Compliance Rules (Web / PWA / Google Play / App Store) -->
        <section
            v-else-if="activeTab === 'compliance'"
            class="grid gap-3 md:grid-cols-2"
        >
            <div
                v-for="rule in complianceRules"
                :key="rule.id"
                class="space-y-2.5 rounded-xl border border-border bg-card p-4 text-xs"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="flex items-center gap-1.5 font-mono font-bold text-foreground uppercase"
                    >
                        <ShieldCheck class="size-4 text-primary" />
                        {{ rule.platform }}
                    </span>
                    <Badge
                        :variant="
                            rule.require_native_iap_for_digital
                                ? 'secondary'
                                : 'soft'
                        "
                    >
                        {{
                            rule.require_native_iap_for_digital
                                ? 'Native IAP Required'
                                : 'Web Checkout Allowed'
                        }}
                    </Badge>
                </div>
                <div class="grid grid-cols-2 gap-1.5">
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="rule.allow_external_web_checkout"
                            type="checkbox"
                        />
                        <span>External Web Checkout</span>
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="rule.allow_premium_purchase"
                            type="checkbox"
                        />
                        <span>Premium Purchase</span>
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="rule.allow_boost_purchase"
                            type="checkbox"
                        />
                        <span>Boost Purchase</span>
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            v-model="rule.allow_banner_display"
                            type="checkbox"
                        />
                        <span>Display Banners</span>
                    </label>
                </div>
                <p v-if="rule.policy_note" class="text-muted-foreground">
                    {{ rule.policy_note }}
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-7 text-[11px]"
                    @click="saveCompliance(rule)"
                >
                    Save Rule
                </Button>
            </div>
        </section>

        <!-- TAB 5: Webhook Security Logs -->
        <section
            v-else
            class="space-y-3 rounded-2xl border border-border bg-card p-5"
        >
            <h2 class="text-sm font-bold text-foreground">
                Payment Webhook Audit Trail
            </h2>
            <div v-if="webhookLogs.length > 0" class="divide-y divide-border">
                <div
                    v-for="l in webhookLogs"
                    :key="l.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-xs"
                >
                    <div>
                        <Badge
                            :variant="
                                l.signature_valid ? 'success' : 'destructive'
                            "
                            class="mr-2 text-[10px]"
                        >
                            {{ l.signature_valid ? 'SIG OK' : 'INVALID SIG' }}
                        </Badge>
                        <span class="font-semibold uppercase">{{
                            l.provider
                        }}</span>
                        · {{ l.outcome }}
                        <span
                            v-if="l.provider_reference"
                            class="font-mono text-muted-foreground"
                        >
                            ({{ l.provider_reference }})
                        </span>
                    </div>
                    <span class="text-muted-foreground">
                        {{ formatDateTime(l.created_at, locale) }}
                    </span>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                No webhook attempts logged yet.
            </p>
        </section>
    </div>
</template>
