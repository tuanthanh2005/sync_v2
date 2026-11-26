<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Crown, Gift, Plus, Save } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, formatUsdCents, formatVnd } from '@/lib/currency';
import { formatDate } from '@/lib/formatters';
import { grant as grantPremiumRoute } from '@/routes/admin/monetization/premium';
import {
    store as storePlanRoute,
    update as updatePlanRoute,
} from '@/routes/admin/monetization/premium/plans';
import { manage as manageSubRoute } from '@/routes/admin/monetization/premium/subscriptions';
import type { PremiumPlanItem } from '@/types';

type SubRow = {
    id: number;
    user_id: number;
    user_name: string;
    username: string;
    email: string;
    plan_name: string;
    status: string;
    status_label: string;
    source: string;
    currency: string;
    amount_paid: number;
    starts_at: string;
    expires_at: string;
    granted_by_username: string | null;
    reason: string | null;
};

const props = defineProps<{
    plans: PremiumPlanItem[];
    subscriptions: {
        data: SubRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        search: string;
        status: string;
    };
}>();

const { t, locale } = useI18n();

const grantForm = useForm({
    username: '',
    premium_plan_id: props.plans[0]?.id ?? 0,
    duration_days: 30,
    source: 'admin_grant',
    reason: '',
});

const newPlanForm = useForm({
    name: '',
    description: '',
    duration_days: 30,
    price_usd_cents: 299,
    price_vnd: 79_000,
    promo_price_usd_cents: null as number | null,
    promo_price_vnd: null as number | null,
    ad_reduction_mode: 'reduced',
    benefits: ['blue_badge', 'post_analytics', 'reduced_ads'],
    sort_order: 50,
    is_active: true,
    is_featured: false,
});

const reasonOpen = ref(false);
const pendingAction = ref<{
    subId: number;
    action: 'extend' | 'suspend' | 'restore' | 'revoke';
    title: string;
} | null>(null);

function savePlan(plan: PremiumPlanItem): void {
    router.patch(
        updatePlanRoute.url(plan.id),
        {
            name: plan.name,
            description: plan.description,
            duration_days: plan.duration_days,
            price_usd_cents: plan.price_usd_cents,
            price_vnd: plan.price_vnd,
            promo_price_usd_cents: plan.promo_price_usd_cents,
            promo_price_vnd: plan.promo_price_vnd,
            ad_reduction_mode: plan.ad_reduction_mode,
            benefits: plan.benefits,
            sort_order: plan.sort_order,
            is_active: plan.is_active,
            is_featured: plan.is_featured,
        },
        { preserveScroll: true },
    );
}

function createPlan(): void {
    newPlanForm.post(storePlanRoute.url(), {
        preserveScroll: true,
        onSuccess: () => newPlanForm.reset('name', 'description'),
    });
}

function submitGrant(): void {
    grantForm.post(grantPremiumRoute.url(), {
        preserveScroll: true,
        onSuccess: () => grantForm.reset('username', 'reason'),
    });
}

function openSubAction(
    subId: number,
    action: 'extend' | 'suspend' | 'restore' | 'revoke',
    title: string,
): void {
    pendingAction.value = { subId, action, title };
    reasonOpen.value = true;
}

function executeSubAction(reason: string): void {
    if (!pendingAction.value) {
        return;
    }

    router.post(
        manageSubRoute.url(pendingAction.value.subId),
        {
            action: pendingAction.value.action,
            reason,
            extra_days:
                pendingAction.value.action === 'extend' ? 30 : undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                reasonOpen.value = false;
                pendingAction.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.nav.premiumManagement')" />

        <h1 class="flex items-center gap-2 text-xl font-bold text-foreground">
            <Crown class="size-5 text-primary" />
            <span>{{ t('admin.nav.premiumManagement') }}</span>
        </h1>

        <!-- Editable Plans Table -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2 class="text-sm font-bold text-foreground">
                {{ t('admin.monetizationCenter.premium.plansTitle') }}
            </h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="space-y-2.5 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Input
                            v-model="plan.name"
                            class="h-8 text-xs font-semibold"
                        />
                        <Badge
                            :variant="plan.is_active ? 'success' : 'outline'"
                        >
                            {{
                                plan.is_active
                                    ? t(
                                          'admin.monetizationCenter.common.active',
                                      )
                                    : t(
                                          'admin.monetizationCenter.common.disabled',
                                      )
                            }}
                        </Badge>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <Label class="text-[11px]">{{
                                t('admin.monetizationCenter.common.days')
                            }}</Label>
                            <Input
                                v-model.number="plan.duration_days"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div>
                            <Label class="text-[11px]">{{
                                t('admin.monetizationCenter.common.vnd')
                            }}</Label>
                            <Input
                                v-model.number="plan.price_vnd"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div>
                            <Label class="text-[11px]">{{
                                t('admin.monetizationCenter.common.usdCents')
                            }}</Label>
                            <Input
                                v-model.number="plan.price_usd_cents"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="plan.is_active"
                                    type="checkbox"
                                />
                                <span>{{
                                    t('admin.monetizationCenter.common.active')
                                }}</span>
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="plan.is_featured"
                                    type="checkbox"
                                />
                                <span>{{
                                    t(
                                        'admin.monetizationCenter.common.featured',
                                    )
                                }}</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-muted-foreground">
                                {{ formatVnd(plan.price_vnd, locale) }} /
                                {{
                                    formatUsdCents(plan.price_usd_cents, locale)
                                }}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                class="h-8 rounded-lg text-xs"
                                @click="savePlan(plan)"
                            >
                                <Save class="size-3.5" />
                                <span>{{
                                    t('admin.monetizationCenter.common.save')
                                }}</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Administrative Grant / Promotion + Create Plan -->
        <div class="grid gap-5 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-bold text-foreground"
                >
                    <Gift class="size-4 text-primary" />
                    <span>{{
                        t('admin.monetizationCenter.premium.grantTitle')
                    }}</span>
                </h2>
                <form @submit.prevent="submitGrant" class="space-y-3 text-xs">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label>{{
                                t(
                                    'admin.monetizationCenter.premium.usernameOrEmail',
                                )
                            }}</Label>
                            <Input
                                v-model="grantForm.username"
                                :placeholder="
                                    t(
                                        'admin.monetizationCenter.premium.usernamePlaceholder',
                                    )
                                "
                                required
                            />
                            <InputError :message="grantForm.errors.username" />
                        </div>
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.premium.plan')
                            }}</Label>
                            <select
                                v-model.number="grantForm.premium_plan_id"
                                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                            >
                                <option
                                    v-for="p in plans"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label>{{
                                t(
                                    'admin.monetizationCenter.common.durationDays',
                                )
                            }}</Label>
                            <Input
                                v-model.number="grantForm.duration_days"
                                type="number"
                                min="1"
                                max="1095"
                                required
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.premium.source')
                            }}</Label>
                            <select
                                v-model="grantForm.source"
                                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                            >
                                <option value="admin_grant">
                                    {{
                                        t(
                                            'admin.monetizationCenter.premium.sourceAdminGrant',
                                        )
                                    }}
                                </option>
                                <option value="promotion">
                                    {{
                                        t(
                                            'admin.monetizationCenter.premium.sourcePromotion',
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label>{{
                            t('admin.monetizationCenter.premium.auditReason')
                        }}</Label>
                        <Input
                            v-model="grantForm.reason"
                            required
                            :placeholder="
                                t(
                                    'admin.monetizationCenter.premium.auditReasonPlaceholder',
                                )
                            "
                        />
                        <InputError :message="grantForm.errors.reason" />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="grantForm.processing"
                    >
                        <Gift class="size-3.5" />
                        <span>{{
                            t('admin.monetizationCenter.premium.grantButton')
                        }}</span>
                    </Button>
                </form>
            </section>

            <section
                class="space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-bold text-foreground"
                >
                    <Plus class="size-4 text-primary" />
                    <span>{{
                        t('admin.monetizationCenter.premium.addPlanTitle')
                    }}</span>
                </h2>
                <form @submit.prevent="createPlan" class="space-y-3 text-xs">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.premium.planName')
                            }}</Label>
                            <Input v-model="newPlanForm.name" required />
                        </div>
                        <div class="grid gap-1">
                            <Label>{{
                                t(
                                    'admin.monetizationCenter.common.durationDays',
                                )
                            }}</Label>
                            <Input
                                v-model.number="newPlanForm.duration_days"
                                type="number"
                                required
                            />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.common.priceVnd')
                            }}</Label>
                            <Input
                                v-model.number="newPlanForm.price_vnd"
                                type="number"
                                required
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.common.usdCents')
                            }}</Label>
                            <Input
                                v-model.number="newPlanForm.price_usd_cents"
                                type="number"
                                required
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>{{
                                t('admin.monetizationCenter.premium.adMode')
                            }}</Label>
                            <select
                                v-model="newPlanForm.ad_reduction_mode"
                                class="h-9 rounded-lg border border-input bg-background px-2 text-xs"
                            >
                                <option value="none">
                                    {{
                                        t(
                                            'admin.monetizationCenter.premium.adModeStandard',
                                        )
                                    }}
                                </option>
                                <option value="reduced">
                                    {{
                                        t(
                                            'admin.monetizationCenter.premium.adModeReduced',
                                        )
                                    }}
                                </option>
                                <option value="ad_free">
                                    {{
                                        t(
                                            'admin.monetizationCenter.premium.adModeAdFree',
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        :disabled="newPlanForm.processing"
                    >
                        <Plus class="size-3.5" />
                        <span>{{
                            t(
                                'admin.monetizationCenter.premium.createPlanButton',
                            )
                        }}</span>
                    </Button>
                </form>
            </section>
        </div>

        <!-- Subscriptions Table -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2 class="text-sm font-bold text-foreground">
                {{
                    t('admin.monetizationCenter.premium.subscriptionsTitle', {
                        count: subscriptions.total,
                    })
                }}
            </h2>
            <div
                v-if="subscriptions.data.length > 0"
                class="divide-y divide-border"
            >
                <div
                    v-for="sub in subscriptions.data"
                    :key="sub.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3 text-xs"
                >
                    <div>
                        <p class="font-semibold text-foreground">
                            {{ sub.user_name }} (@{{ sub.username }}) ·
                            {{ sub.plan_name }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ sub.source }} ·
                            {{
                                sub.amount_paid > 0
                                    ? formatMoney(
                                          sub.amount_paid,
                                          sub.currency,
                                          locale,
                                      )
                                    : t(
                                          'admin.monetizationCenter.premium.complimentary',
                                      )
                            }}
                            · {{ formatDate(sub.starts_at, locale) }} →
                            {{ formatDate(sub.expires_at, locale) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Badge variant="outline">{{ sub.status_label }}</Badge>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px]"
                            @click="
                                openSubAction(
                                    sub.id,
                                    'extend',
                                    t(
                                        'admin.monetizationCenter.premium.extend30DaysTitle',
                                    ),
                                )
                            "
                        >
                            {{
                                t(
                                    'admin.monetizationCenter.premium.extend30DaysShort',
                                )
                            }}
                        </Button>
                        <Button
                            v-if="sub.status === 'active'"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px]"
                            @click="
                                openSubAction(
                                    sub.id,
                                    'suspend',
                                    t(
                                        'admin.monetizationCenter.premium.suspendTitle',
                                    ),
                                )
                            "
                        >
                            {{ t('admin.monetizationCenter.premium.suspend') }}
                        </Button>
                        <Button
                            v-if="sub.status === 'suspended'"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px]"
                            @click="
                                openSubAction(
                                    sub.id,
                                    'restore',
                                    t(
                                        'admin.monetizationCenter.premium.restoreTitle',
                                    ),
                                )
                            "
                        >
                            {{ t('admin.monetizationCenter.premium.restore') }}
                        </Button>
                        <Button
                            v-if="sub.status !== 'revoked'"
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="h-7 text-[11px] text-destructive"
                            @click="
                                openSubAction(
                                    sub.id,
                                    'revoke',
                                    t(
                                        'admin.monetizationCenter.premium.revokeTitle',
                                    ),
                                )
                            "
                        >
                            {{ t('admin.monetizationCenter.premium.revoke') }}
                        </Button>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('admin.monetizationCenter.premium.emptySubscriptions') }}
            </p>
        </section>

        <ReasonDialog
            v-model:open="reasonOpen"
            :title="
                pendingAction?.title ||
                t('admin.monetizationCenter.premium.subscriptionActionFallback')
            "
            :description="t('dialog.reasonPlaceholder')"
            :confirm-label="t('common.confirm')"
            @submit="executeSubAction"
        />
    </div>
</template>
