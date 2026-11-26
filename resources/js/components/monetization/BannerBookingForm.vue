<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PaymentConfirmModal from '@/components/monetization/PaymentConfirmModal.vue';
import PendingLimitModal from '@/components/monetization/PendingLimitModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/currency';
import { login } from '@/routes';
import { store as storeBannerCampaign } from '@/routes/banners/campaigns';
import type { BannerPlacementItem, PaymentMethodPublic } from '@/types';

const props = defineProps<{
    placement: BannerPlacementItem;
    currency: 'VND' | 'USD';
    paymentMethods: PaymentMethodPublic[];
    pendingOrdersCount: number;
    maxPendingOrders: number;
    isAuthenticated: boolean;
}>();

const { t, locale } = useI18n();

const urlPlaceholder = 'https://example.com';
const optionalUrlPlaceholder = 'https://example.com/banner.png';

const durationDays = ref<number>(7);
const previewMode = ref<'desktop' | 'mobile'>('desktop');
const desktopPreviewUrl = ref<string | null>(null);
const mobilePreviewUrl = ref<string | null>(null);
const confirmOpen = ref(false);
const limitOpen = ref(false);

const eligibleMethods = computed(() =>
    props.paymentMethods.filter((m) =>
        m.supported_currencies.includes(props.currency),
    ),
);

function priceForPlacement(
    p: BannerPlacementItem,
    days: number,
    curr: 'VND' | 'USD',
): number {
    const isUsd = curr === 'USD';
    if (days === 7) return isUsd ? p.price_7d_usd_cents : p.price_7d_vnd;
    if (days === 15) return isUsd ? p.price_15d_usd_cents : p.price_15d_vnd;
    if (days === 30) return isUsd ? p.price_30d_usd_cents : p.price_30d_vnd;
    if (days === 90) return isUsd ? p.price_90d_usd_cents : p.price_90d_vnd;
    const base30 = isUsd ? p.price_30d_usd_cents : p.price_30d_vnd;

    return Math.ceil((base30 * days) / 30);
}

const estimatedPrice = computed(() =>
    priceForPlacement(props.placement, durationDays.value, props.currency),
);

function newKey(): string {
    return `banner-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

const bookingForm = useForm({
    banner_placement_id: props.placement.id,
    name: '',
    business_name: '',
    headline: '',
    cta_label: '',
    target_url: '',
    alt_text: '',
    duration_days: 7,
    starts_at: new Date().toISOString().slice(0, 10),
    currency: props.currency as 'VND' | 'USD',
    provider: props.paymentMethods[0]?.code ?? 'vietqr',
    idempotency_key: newKey(),
    confirmed: true,
    desktop_image: null as File | null,
    mobile_image: null as File | null,
    desktop_image_url: '',
    mobile_image_url: '',
});

const previewImageSrc = computed(() => {
    if (previewMode.value === 'desktop') {
        return desktopPreviewUrl.value || bookingForm.desktop_image_url || '';
    }

    return (
        mobilePreviewUrl.value ||
        bookingForm.mobile_image_url ||
        desktopPreviewUrl.value ||
        bookingForm.desktop_image_url ||
        ''
    );
});

const previewAlt = computed(() => bookingForm.alt_text || 'Preview');
const previewBusiness = computed(() => bookingForm.business_name || 'SyncViet');
const previewHeadline = computed(
    () =>
        bookingForm.headline ||
        bookingForm.name ||
        t('monetization.banner.headline'),
);
const previewCta = computed(
    () => bookingForm.cta_label || t('monetization.banner.ctaPlaceholder'),
);
const selectedMethodUpper = computed(() => bookingForm.provider.toUpperCase());

function handleFile(kind: 'desktop' | 'mobile', event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (kind === 'desktop') {
        bookingForm.desktop_image = file;
        desktopPreviewUrl.value = file ? URL.createObjectURL(file) : null;
    } else {
        bookingForm.mobile_image = file;
        mobilePreviewUrl.value = file ? URL.createObjectURL(file) : null;
    }
}

function openBookingConfirm(): void {
    if (!props.isAuthenticated) {
        router.visit(login.url());

        return;
    }

    if (props.pendingOrdersCount >= props.maxPendingOrders) {
        limitOpen.value = true;

        return;
    }

    if (
        eligibleMethods.value.length > 0 &&
        !eligibleMethods.value.some((m) => m.code === bookingForm.provider)
    ) {
        bookingForm.provider = eligibleMethods.value[0].code;
    }

    confirmOpen.value = true;
}

function submitBooking(): void {
    if (bookingForm.processing) {
        return;
    }

    bookingForm.banner_placement_id = props.placement.id;
    bookingForm.duration_days = durationDays.value;
    bookingForm.currency = props.currency;
    bookingForm.idempotency_key = newKey();
    bookingForm.confirmed = true;

    bookingForm.post(storeBannerCampaign.url(), {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => {
            confirmOpen.value = false;
            if (errors.pending_limit) {
                limitOpen.value = true;
            }
        },
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <section
        class="space-y-5 rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-bold text-foreground">
                    {{ t('monetization.banner.bookTitle') }}:
                    {{ placement.name }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('monetization.bookingExtra.recommendedSizeSpec', {
                            label: t('monetization.banner.recommendedSize'),
                            width: placement.recommended_width,
                            height: placement.recommended_height,
                            mode: placement.delivery_mode_label,
                        })
                    }}
                </p>
            </div>
            <p class="text-lg font-bold text-primary">
                {{ formatMoney(estimatedPrice, currency, locale) }}
            </p>
        </div>

        <form
            @submit.prevent="openBookingConfirm"
            class="grid gap-5 lg:grid-cols-12"
        >
            <div class="space-y-4 lg:col-span-7">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="banner-name">{{
                            t('monetization.banner.campaignName')
                        }}</Label>
                        <Input
                            id="banner-name"
                            v-model="bookingForm.name"
                            required
                            maxlength="140"
                        />
                        <InputError :message="bookingForm.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="banner-biz">{{
                            t('monetization.banner.businessName')
                        }}</Label>
                        <Input
                            id="banner-biz"
                            v-model="bookingForm.business_name"
                            maxlength="120"
                        />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="banner-url">{{
                            t('monetization.banner.targetUrl')
                        }}</Label>
                        <Input
                            id="banner-url"
                            v-model="bookingForm.target_url"
                            type="url"
                            :placeholder="urlPlaceholder"
                            required
                        />
                        <InputError :message="bookingForm.errors.target_url" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="banner-alt">{{
                            t('monetization.banner.altText')
                        }}</Label>
                        <Input
                            id="banner-alt"
                            v-model="bookingForm.alt_text"
                            required
                            maxlength="180"
                        />
                        <InputError :message="bookingForm.errors.alt_text" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="banner-headline">{{
                            t('monetization.banner.headline')
                        }}</Label>
                        <Input
                            id="banner-headline"
                            v-model="bookingForm.headline"
                            maxlength="160"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="banner-cta">{{
                            t('monetization.banner.ctaLabel')
                        }}</Label>
                        <Input
                            id="banner-cta"
                            v-model="bookingForm.cta_label"
                            maxlength="60"
                            :placeholder="
                                t('monetization.banner.ctaPlaceholder')
                            "
                        />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="banner-duration">{{
                            t('monetization.banner.duration')
                        }}</Label>
                        <select
                            id="banner-duration"
                            v-model.number="durationDays"
                            class="min-h-10 rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option :value="7">
                                7 {{ t('monetization.common.days') }}
                            </option>
                            <option :value="15">
                                15 {{ t('monetization.common.days') }}
                            </option>
                            <option :value="30">
                                30 {{ t('monetization.common.days') }}
                            </option>
                            <option :value="90">
                                90 {{ t('monetization.common.days') }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="banner-start">{{
                            t('monetization.banner.startDate')
                        }}</Label>
                        <Input
                            id="banner-start"
                            v-model="bookingForm.starts_at"
                            type="date"
                        />
                        <InputError :message="bookingForm.errors.starts_at" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="banner-provider">{{
                            t('monetization.confirmModal.method')
                        }}</Label>
                        <select
                            id="banner-provider"
                            v-model="bookingForm.provider"
                            class="min-h-10 rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option
                                v-for="m in eligibleMethods"
                                :key="m.code"
                                :value="m.code"
                            >
                                {{ m.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        class="space-y-2 rounded-xl border border-dashed border-border p-3"
                    >
                        <Label
                            for="banner-desktop-file"
                            class="flex items-center gap-1.5 text-xs"
                        >
                            <Upload class="size-3.5 text-primary" />
                            <span>{{
                                t('monetization.banner.desktopImage')
                            }}</span>
                        </Label>
                        <input
                            id="banner-desktop-file"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="w-full text-xs"
                            @change="handleFile('desktop', $event)"
                        />
                        <Input
                            v-model="bookingForm.desktop_image_url"
                            type="url"
                            :placeholder="optionalUrlPlaceholder"
                            class="text-xs"
                        />
                        <InputError
                            :message="bookingForm.errors.desktop_image"
                        />
                    </div>

                    <div
                        class="space-y-2 rounded-xl border border-dashed border-border p-3"
                    >
                        <Label
                            for="banner-mobile-file"
                            class="flex items-center gap-1.5 text-xs"
                        >
                            <Upload class="size-3.5 text-primary" />
                            <span>{{
                                t('monetization.banner.mobileImage')
                            }}</span>
                        </Label>
                        <input
                            id="banner-mobile-file"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="w-full text-xs"
                            @change="handleFile('mobile', $event)"
                        />
                        <Input
                            v-model="bookingForm.mobile_image_url"
                            type="url"
                            :placeholder="optionalUrlPlaceholder"
                            class="text-xs"
                        />
                        <InputError
                            :message="bookingForm.errors.mobile_image"
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    class="min-h-11 w-full rounded-xl sm:w-auto"
                    :disabled="bookingForm.processing"
                >
                    <CheckCircle2 class="size-4" />
                    <span>{{
                        isAuthenticated
                            ? t('monetization.banner.submitBooking')
                            : t('monetization.banner.signInToBook')
                    }}</span>
                </Button>
            </div>

            <!-- Live Creative Preview -->
            <div class="space-y-3 lg:col-span-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-foreground">
                        {{ t('monetization.banner.livePreview') }}
                    </p>
                    <div
                        class="inline-flex rounded-lg border border-border p-0.5 text-[11px]"
                    >
                        <button
                            type="button"
                            :class="[
                                'rounded-md px-2 py-1',
                                previewMode === 'desktop'
                                    ? 'bg-primary-soft font-semibold text-primary'
                                    : 'text-muted-foreground',
                            ]"
                            @click="previewMode = 'desktop'"
                        >
                            {{ t('monetization.bookingExtra.desktop') }}
                        </button>
                        <button
                            type="button"
                            :class="[
                                'rounded-md px-2 py-1',
                                previewMode === 'mobile'
                                    ? 'bg-primary-soft font-semibold text-primary'
                                    : 'text-muted-foreground',
                            ]"
                            @click="previewMode = 'mobile'"
                        >
                            {{ t('monetization.bookingExtra.mobile') }}
                        </button>
                    </div>
                </div>

                <div
                    :class="[
                        'mx-auto overflow-hidden rounded-xl border border-border bg-muted/20',
                        previewMode === 'mobile' ? 'max-w-[320px]' : 'w-full',
                    ]"
                >
                    <div
                        class="flex items-center justify-between border-b border-border/70 bg-card px-3 py-1.5 text-[10px] text-muted-foreground"
                    >
                        <span class="font-semibold uppercase">{{
                            t('monetization.banner.sponsoredLabel')
                        }}</span>
                        <span>{{ previewBusiness }}</span>
                    </div>
                    <div
                        class="flex min-h-[120px] items-center justify-center bg-muted/40"
                    >
                        <img
                            v-if="previewImageSrc"
                            :src="previewImageSrc"
                            :alt="previewAlt"
                            :class="[
                                'w-full object-cover',
                                previewMode === 'mobile'
                                    ? 'max-h-[140px]'
                                    : 'max-h-[180px]',
                            ]"
                        />
                        <p v-else class="p-4 text-xs text-muted-foreground">
                            {{ t('monetization.banner.previewPlaceholder') }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-between gap-2 bg-card px-3 py-2 text-xs"
                    >
                        <span class="truncate font-semibold text-foreground">
                            {{ previewHeadline }}
                        </span>
                        <span
                            class="shrink-0 rounded-md bg-primary-soft px-2 py-0.5 text-[11px] font-semibold text-accent-foreground"
                        >
                            {{ previewCta }}
                        </span>
                    </div>
                </div>
            </div>
        </form>

        <PaymentConfirmModal
            v-model:open="confirmOpen"
            :service-name="t('monetization.services.bannerTitle')"
            :item-name="placement.name"
            :duration-label="`${durationDays} ${t('monetization.common.days')}`"
            :amount="estimatedPrice"
            :currency="currency"
            :method-name="selectedMethodUpper"
            :processing="bookingForm.processing"
            @confirm="submitBooking"
        />

        <PendingLimitModal
            v-model:open="limitOpen"
            :max-pending="maxPendingOrders"
        />
    </section>
</template>
