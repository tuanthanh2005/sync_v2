<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as updateMethodRoute } from '@/routes/admin/monetization/payment-methods';

export type AdminPaymentMethod = {
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

const props = defineProps<{
    method: AdminPaymentMethod;
}>();

const { t } = useI18n();

const form = useForm({
    name: props.method.name,
    fee_percent_bps: props.method.fee_percent_bps,
    fee_fixed_vnd: props.method.fee_fixed_vnd,
    fee_fixed_usd_cents: props.method.fee_fixed_usd_cents,
    is_enabled: props.method.is_enabled,
    is_live_ready: props.method.is_live_ready,
    instructions: {
        ...props.method.instructions,
        bank_name: String(props.method.instructions?.bank_name ?? ''),
        bank_code: String(props.method.instructions?.bank_code ?? ''),
        account_number: String(props.method.instructions?.account_number ?? ''),
        account_holder: String(props.method.instructions?.account_holder ?? ''),
        merchant_email: String(props.method.instructions?.merchant_email ?? ''),
        paypal_me_url: String(props.method.instructions?.paypal_me_url ?? ''),
    },
});

function save(): void {
    form.patch(updateMethodRoute.url(props.method.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div
        class="space-y-2.5 rounded-xl border border-border bg-card p-4 text-xs"
    >
        <div class="flex items-center justify-between gap-2">
            <div>
                <p class="font-bold text-foreground">
                    {{ method.name }}
                </p>
                <p class="font-mono text-[10px] text-muted-foreground">
                    {{ method.code }} ·
                    {{ method.supported_currencies.join(', ') }}
                </p>
            </div>
            <Badge
                :variant="
                    method.requires_legal_clearance
                        ? 'outline'
                        : form.is_enabled
                          ? 'success'
                          : 'secondary'
                "
            >
                {{
                    method.requires_legal_clearance
                        ? t(
                              'admin.monetizationCenter.paymentMethod.cryptoLegalHold',
                          )
                        : form.is_enabled
                          ? t('admin.monetizationCenter.common.enabled')
                          : t('admin.monetizationCenter.common.disabled')
                }}
            </Badge>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.feeBps')
                }}</Label>
                <Input
                    v-model.number="form.fee_percent_bps"
                    type="number"
                    class="h-8 text-xs"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.fixedVnd')
                }}</Label>
                <Input
                    v-model.number="form.fee_fixed_vnd"
                    type="number"
                    class="h-8 text-xs"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.fixedUsdCents')
                }}</Label>
                <Input
                    v-model.number="form.fee_fixed_usd_cents"
                    type="number"
                    class="h-8 text-xs"
                />
            </div>
        </div>

        <div
            v-if="method.code === 'vietqr' || method.code === 'bank_transfer'"
            class="grid gap-2 rounded-lg border border-border/80 bg-muted/30 p-3 sm:grid-cols-2"
        >
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.bankName')
                }}</Label>
                <input
                    v-model="form.instructions.bank_name"
                    type="text"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.bankNamePlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.vietqrCode')
                }}</Label>
                <input
                    v-model="form.instructions.bank_code"
                    type="text"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.vietqrCodePlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 font-mono text-xs uppercase shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.accountNumber')
                }}</Label>
                <input
                    v-model="form.instructions.account_number"
                    type="text"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.accountNumberPlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.accountHolder')
                }}</Label>
                <input
                    v-model="form.instructions.account_holder"
                    type="text"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.accountHolderPlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs uppercase shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
        </div>

        <div
            v-else-if="method.code === 'paypal'"
            class="grid gap-2 rounded-lg border border-border/80 bg-muted/30 p-3 sm:grid-cols-2"
        >
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.paypalEmail')
                }}</Label>
                <input
                    v-model="form.instructions.merchant_email"
                    type="email"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.paypalEmailPlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
            <div>
                <Label class="text-[10px]">{{
                    t('admin.monetizationCenter.paymentMethod.paypalMeUrl')
                }}</Label>
                <input
                    v-model="form.instructions.paypal_me_url"
                    type="url"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.paymentMethod.paypalMeUrlPlaceholder',
                        )
                    "
                    class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
            </div>
        </div>

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-1.5">
                    <input v-model="form.is_enabled" type="checkbox" />
                    <span>{{
                        t('admin.monetizationCenter.common.enabled')
                    }}</span>
                </label>
                <label class="flex items-center gap-1.5">
                    <input v-model="form.is_live_ready" type="checkbox" />
                    <span>{{
                        t('admin.monetizationCenter.paymentMethod.liveReady')
                    }}</span>
                </label>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-7 text-[11px]"
                :disabled="form.processing"
                @click="save"
            >
                {{ t('admin.monetizationCenter.common.save') }}
            </Button>
        </div>
    </div>
</template>
