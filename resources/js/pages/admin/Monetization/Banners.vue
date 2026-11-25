<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Image as ImageIcon,
    PauseOctagon,
    Plus,
    Save,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/currency';
import { formatDate } from '@/lib/formatters';
import {
    manage as manageCampaignRoute,
    review as reviewCampaignRoute,
    store as storeAdminCampaignRoute,
} from '@/routes/admin/monetization/banners/campaigns';
import { update as updatePlacementRoute } from '@/routes/admin/monetization/banners/placements';
import type { BannerCampaignItem, BannerPlacementItem } from '@/types';

const props = defineProps<{
    placements: BannerPlacementItem[];
    campaigns: {
        data: BannerCampaignItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status: string };
}>();

const { t, locale } = useI18n();

const createForm = useForm<{
    owner_username: string;
    banner_placement_id: number;
    name: string;
    business_name: string;
    headline: string;
    cta_label: string;
    target_url: string;
    alt_text: string;
    duration_days: number;
    starts_at: string;
    currency: 'VND' | 'USD';
    payment_status: string;
    auto_approve: boolean;
    desktop_image: File | null;
    mobile_image: File | null;
    desktop_image_url: string;
}>({
    owner_username: '',
    banner_placement_id: props.placements[0]?.id ?? 0,
    name: '',
    business_name: '',
    headline: '',
    cta_label: 'Learn more',
    target_url: '',
    alt_text: '',
    duration_days: 30,
    starts_at: new Date().toISOString().slice(0, 10),
    currency: 'VND',
    payment_status: 'manual_paid',
    auto_approve: true,
    desktop_image: null,
    mobile_image: null,
    desktop_image_url: '',
});

const reasonOpen = ref(false);
const actionCtx = ref<{
    campaignId: number;
    kind: 'reject' | 'emergency_pause' | 'cancel';
    title: string;
} | null>(null);

function savePlacement(pl: BannerPlacementItem): void {
    router.patch(
        updatePlacementRoute.url(pl.id),
        {
            name: pl.name,
            description: pl.description,
            delivery_mode: pl.delivery_mode,
            max_active_banners: pl.max_active_banners,
            price_7d_vnd: pl.price_7d_vnd,
            price_15d_vnd: pl.price_15d_vnd,
            price_30d_vnd: pl.price_30d_vnd,
            price_90d_vnd: pl.price_90d_vnd,
            price_7d_usd_cents: pl.price_7d_usd_cents,
            price_15d_usd_cents: pl.price_15d_usd_cents,
            price_30d_usd_cents: pl.price_30d_usd_cents,
            price_90d_usd_cents: pl.price_90d_usd_cents,
            is_active: pl.is_active,
        },
        { preserveScroll: true },
    );
}

function handleFile(kind: 'desktop' | 'mobile', e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (kind === 'desktop') createForm.desktop_image = file;
    else createForm.mobile_image = file;
}

function submitAdminCampaign(): void {
    createForm.post(storeAdminCampaignRoute.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () =>
            createForm.reset('name', 'headline', 'target_url', 'alt_text'),
    });
}

function approveCampaign(id: number): void {
    router.post(
        reviewCampaignRoute.url(id),
        { decision: 'approve' },
        { preserveScroll: true },
    );
}

function openReasonAction(
    campaignId: number,
    kind: 'reject' | 'emergency_pause' | 'cancel',
    title: string,
): void {
    actionCtx.value = { campaignId, kind, title };
    reasonOpen.value = true;
}

function submitReasonAction(reason: string): void {
    if (!actionCtx.value) return;

    const { campaignId, kind } = actionCtx.value;
    if (kind === 'reject') {
        router.post(
            reviewCampaignRoute.url(campaignId),
            { decision: 'reject', reason },
            {
                preserveScroll: true,
                onFinish: () => (reasonOpen.value = false),
            },
        );
    } else {
        router.post(
            manageCampaignRoute.url(campaignId),
            { action: kind, reason },
            {
                preserveScroll: true,
                onFinish: () => (reasonOpen.value = false),
            },
        );
    }
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.nav.bannerAdvertising')" />

        <h1 class="flex items-center gap-2 text-xl font-bold text-foreground">
            <ImageIcon class="size-5 text-primary" />
            <span>{{ t('admin.nav.bannerAdvertising') }}</span>
        </h1>

        <!-- Admin Direct Banner Creation -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2
                class="flex items-center gap-2 text-sm font-bold text-foreground"
            >
                <Plus class="size-4 text-primary" />
                <span>Create Managed Banner Campaign</span>
            </h2>
            <form
                @submit.prevent="submitAdminCampaign"
                class="space-y-3 text-xs"
            >
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <Label>Placement</Label>
                        <select
                            v-model.number="createForm.banner_placement_id"
                            class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs"
                        >
                            <option
                                v-for="pl in placements"
                                :key="pl.id"
                                :value="pl.id"
                            >
                                {{ pl.name }} ({{ pl.delivery_mode_label }})
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1">
                        <Label>Campaign Name</Label>
                        <Input v-model="createForm.name" required />
                    </div>
                    <div class="grid gap-1">
                        <Label>Business Name</Label>
                        <Input v-model="createForm.business_name" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <Label>Target URL (HTTPS)</Label>
                        <Input
                            v-model="createForm.target_url"
                            type="url"
                            required
                        />
                        <InputError :message="createForm.errors.target_url" />
                    </div>
                    <div class="grid gap-1">
                        <Label>Alt Text</Label>
                        <Input v-model="createForm.alt_text" required />
                    </div>
                    <div class="grid gap-1">
                        <Label>Duration (days) & Start</Label>
                        <div class="flex gap-2">
                            <Input
                                v-model.number="createForm.duration_days"
                                type="number"
                                min="1"
                                max="365"
                                class="w-20"
                            />
                            <Input v-model="createForm.starts_at" type="date" />
                        </div>
                        <InputError :message="createForm.errors.starts_at" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <Label>Desktop Image</Label>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            @change="handleFile('desktop', $event)"
                        />
                        <InputError
                            :message="createForm.errors.desktop_image"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label>Mobile Image</Label>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            @change="handleFile('mobile', $event)"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label>Owner Username (optional)</Label>
                        <Input
                            v-model="createForm.owner_username"
                            placeholder="defaults to current admin"
                        />
                    </div>
                </div>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="createForm.processing"
                >
                    <Plus class="size-3.5" />
                    <span>Publish Banner Campaign</span>
                </Button>
            </form>
        </section>

        <!-- Campaigns Moderation & Emergency Pause -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2 class="text-sm font-bold text-foreground">
                Banner Campaigns ({{ campaigns.total }})
            </h2>
            <div
                v-if="campaigns.data.length > 0"
                class="divide-y divide-border"
            >
                <div
                    v-for="c in campaigns.data"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3 text-xs"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-foreground">{{
                                c.name
                            }}</span>
                            <Badge variant="outline">{{
                                c.status_label
                            }}</Badge>
                            <Badge variant="secondary">{{
                                c.placement_name
                            }}</Badge>
                        </div>
                        <p class="mt-0.5 text-muted-foreground">
                            @{{ c.owner_username }} ·
                            {{ formatDate(c.starts_at, locale) }} →
                            {{ formatDate(c.ends_at, locale) }} ·
                            {{
                                formatMoney(c.price_amount, c.currency, locale)
                            }}
                            · {{ c.impressions_count }} imp /
                            {{ c.clicks_count }} clicks
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Button
                            v-if="c.status === 'pending_review'"
                            type="button"
                            size="sm"
                            class="h-7 text-[11px]"
                            @click="approveCampaign(c.id)"
                        >
                            <CheckCircle2 class="size-3" />
                            <span>Approve</span>
                        </Button>
                        <Button
                            v-if="c.status === 'pending_review'"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px] text-destructive"
                            @click="
                                openReasonAction(
                                    c.id,
                                    'reject',
                                    'Reject Banner',
                                )
                            "
                        >
                            <XCircle class="size-3" />
                            <span>Reject</span>
                        </Button>
                        <Button
                            v-if="
                                c.status === 'active' ||
                                c.status === 'scheduled'
                            "
                            type="button"
                            size="sm"
                            variant="destructive"
                            class="h-7 text-[11px]"
                            @click="
                                openReasonAction(
                                    c.id,
                                    'emergency_pause',
                                    'Emergency Pause Banner',
                                )
                            "
                        >
                            <PauseOctagon class="size-3" />
                            <span>Emergency Pause</span>
                        </Button>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                No banner campaigns found.
            </p>
        </section>

        <!-- Placements Pricing & Exclusive/Rotation Config -->
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5">
            <h2 class="text-sm font-bold text-foreground">
                Banner Placements ({{ placements.length }})
            </h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="pl in placements"
                    :key="pl.id"
                    class="space-y-2.5 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-foreground">
                            {{ pl.name }}
                            <span
                                class="font-mono text-[10px] text-muted-foreground"
                            >
                                ({{ pl.code }})
                            </span>
                        </span>
                        <Badge variant="soft">{{ pl.aspect_ratio }}</Badge>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <Label class="text-[10px]">Mode</Label>
                            <select
                                v-model="pl.delivery_mode"
                                class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                            >
                                <option value="exclusive">Exclusive</option>
                                <option value="rotation">Rotation</option>
                            </select>
                        </div>
                        <div>
                            <Label class="text-[10px]">7d VND</Label>
                            <Input
                                v-model.number="pl.price_7d_vnd"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div>
                            <Label class="text-[10px]">30d VND</Label>
                            <Input
                                v-model.number="pl.price_30d_vnd"
                                type="number"
                                class="h-8 text-xs"
                            />
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-1.5">
                            <input v-model="pl.is_active" type="checkbox" />
                            <span>Active</span>
                        </label>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-[11px]"
                            @click="savePlacement(pl)"
                        >
                            <Save class="size-3" />
                            <span>Save</span>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <ReasonDialog
            v-model:open="reasonOpen"
            :title="actionCtx?.title || 'Banner Action'"
            :description="t('dialog.reasonPlaceholder')"
            :confirm-label="t('common.confirm')"
            @submit="submitReasonAction"
        />
    </div>
</template>
