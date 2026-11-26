<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Megaphone, Plus, Save, Zap } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsdCents, formatVnd } from '@/lib/currency';
import { index as adminAdsIndex } from '@/routes/admin/ads';
import {
    store as storePkgRoute,
    update as updatePkgRoute,
} from '@/routes/admin/monetization/boosts/packages';
import type { AdCampaignItem, BoostPackageItem } from '@/types';

defineProps<{
    packages: BoostPackageItem[];
    boostCampaigns: Array<AdCampaignItem & { package_name?: string | null }>;
}>();

const { t, locale } = useI18n();

const createForm = useForm({
    name: '',
    description: '',
    duration_hours: 72,
    price_usd_cents: 299,
    price_vnd: 75_000,
    is_featured_slot: false,
    priority_weight: 20,
    sort_order: 60,
    is_active: true,
});

function savePkg(pkg: BoostPackageItem): void {
    router.patch(
        updatePkgRoute.url(pkg.id),
        {
            name: pkg.name,
            description: pkg.description,
            duration_hours: pkg.duration_hours,
            price_usd_cents: pkg.price_usd_cents,
            price_vnd: pkg.price_vnd,
            is_featured_slot: pkg.is_featured_slot,
            priority_weight: pkg.priority_weight,
            sort_order: pkg.sort_order,
            is_active: pkg.is_active,
        },
        { preserveScroll: true },
    );
}

function createPkg(): void {
    createForm.post(storePkgRoute.url(), {
        preserveScroll: true,
        onSuccess: () => createForm.reset('name', 'description'),
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.nav.boostPackages')" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Zap class="size-5 text-primary" />
                <span>{{ t('admin.nav.boostPackages') }}</span>
            </h1>
            <Button variant="outline" size="sm" as-child>
                <Link :href="adminAdsIndex()">
                    <Megaphone class="size-4" />
                    <span>{{
                        t(
                            'admin.monetizationCenter.boostPackages.reviewCampaigns',
                        )
                    }}</span>
                </Link>
            </Button>
        </div>

        <section class="grid gap-3 md:grid-cols-2">
            <div
                v-for="pkg in packages"
                :key="pkg.id"
                class="space-y-2.5 rounded-xl border border-border bg-card p-4 text-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <Input
                        v-model="pkg.name"
                        class="h-8 text-xs font-semibold"
                    />
                    <Badge :variant="pkg.is_active ? 'success' : 'outline'">
                        {{
                            t(
                                'admin.monetizationCenter.boostPackages.hoursShort',
                                { hours: pkg.duration_hours },
                            )
                        }}
                    </Badge>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <Label class="text-[10px]">{{
                            t('admin.monetizationCenter.boostPackages.hours')
                        }}</Label>
                        <Input
                            v-model.number="pkg.duration_hours"
                            type="number"
                            class="h-8 text-xs"
                        />
                    </div>
                    <div>
                        <Label class="text-[10px]">{{
                            t('admin.monetizationCenter.common.vnd')
                        }}</Label>
                        <Input
                            v-model.number="pkg.price_vnd"
                            type="number"
                            class="h-8 text-xs"
                        />
                    </div>
                    <div>
                        <Label class="text-[10px]">{{
                            t('admin.monetizationCenter.common.usdCents')
                        }}</Label>
                        <Input
                            v-model.number="pkg.price_usd_cents"
                            type="number"
                            class="h-8 text-xs"
                        />
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5">
                            <input v-model="pkg.is_active" type="checkbox" />
                            <span>{{
                                t('admin.monetizationCenter.common.active')
                            }}</span>
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input
                                v-model="pkg.is_featured_slot"
                                type="checkbox"
                            />
                            <span>{{
                                t('admin.monetizationCenter.common.featured')
                            }}</span>
                        </label>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        class="h-7 text-[11px]"
                        @click="savePkg(pkg)"
                    >
                        <Save class="size-3" />
                        <span>{{
                            t('admin.monetizationCenter.common.save')
                        }}</span>
                    </Button>
                </div>
            </div>
        </section>

        <!-- Add Package -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2
                class="flex items-center gap-2 text-sm font-bold text-foreground"
            >
                <Plus class="size-4 text-primary" />
                <span>{{
                    t('admin.monetizationCenter.boostPackages.addPackageTitle')
                }}</span>
            </h2>
            <form
                @submit.prevent="createPkg"
                class="grid gap-3 text-xs sm:grid-cols-5"
            >
                <Input
                    v-model="createForm.name"
                    :placeholder="
                        t(
                            'admin.monetizationCenter.boostPackages.packageNamePlaceholder',
                        )
                    "
                    required
                />
                <Input
                    v-model.number="createForm.duration_hours"
                    type="number"
                    :placeholder="
                        t('admin.monetizationCenter.boostPackages.hours')
                    "
                    required
                />
                <Input
                    v-model.number="createForm.price_vnd"
                    type="number"
                    :placeholder="t('admin.monetizationCenter.common.priceVnd')"
                    required
                />
                <Input
                    v-model.number="createForm.price_usd_cents"
                    type="number"
                    :placeholder="t('admin.monetizationCenter.common.usdCents')"
                    required
                />
                <Button
                    type="submit"
                    size="sm"
                    :disabled="createForm.processing"
                >
                    {{ t('admin.monetizationCenter.boostPackages.create') }}
                </Button>
            </form>
        </section>

        <!-- Recent Boost Campaigns -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2 class="text-sm font-bold text-foreground">
                {{
                    t(
                        'admin.monetizationCenter.boostPackages.recentCampaignsTitle',
                        { count: boostCampaigns.length },
                    )
                }}
            </h2>
            <div
                v-if="boostCampaigns.length > 0"
                class="divide-y divide-border"
            >
                <div
                    v-for="c in boostCampaigns"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-xs"
                >
                    <div>
                        <p class="font-semibold text-foreground">
                            {{ c.name }}
                        </p>
                        <p class="text-muted-foreground">
                            @{{ c.owner_username }} ·
                            {{
                                c.package_name ||
                                t(
                                    'admin.monetizationCenter.boostPackages.defaultPackageFallback',
                                )
                            }}
                            ·
                            {{
                                t(
                                    'admin.monetizationCenter.common.impressionsClicks',
                                    {
                                        impressions: c.impressions_count,
                                        clicks: c.clicks_count,
                                    },
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ c.status_label }}</Badge>
                        <span class="font-semibold">
                            {{ formatVnd(c.total_budget, locale) }} /
                            {{ formatUsdCents(c.total_budget, locale) }}
                        </span>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('admin.monetizationCenter.boostPackages.emptyCampaigns') }}
            </p>
        </section>
    </div>
</template>
