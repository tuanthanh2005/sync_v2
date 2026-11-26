<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRight,
    BarChart3,
    Calendar,
    CheckCircle2,
    ChevronRight,
    Clock,
    CreditCard,
    ExternalLink,
    HelpCircle,
    Info,
    Layers,
    Megaphone,
    Monitor,
    MousePointerClick,
    Pause,
    Pencil,
    Play,
    Plus,
    Rocket,
    Smartphone,
    ThumbsUp,
    TrendingUp,
    Users,
    Wallet as WalletIcon,
    X,
    XCircle,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdCampaignController from '@/actions/App/Http/Controllers/Ads/AdCampaignController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { formatVnd } from '@/lib/currency';
import { formatDate, formatNumber } from '@/lib/formatters';
import { home } from '@/routes';
import { index as walletIndex } from '@/routes/wallet';
import type {
    AdCampaignItem,
    AdDailyStatItem,
    AdEligiblePostItem,
    AdObjectiveOption,
    AdPricingConfig,
    AdStatsSummary,
    CategoryOption,
    PaymentTransactionItem,
    WalletAccountSummary,
    WalletLedgerEntry,
} from '@/types';

const props = defineProps<{
    wallet: WalletAccountSummary;
    ledgerEntries: WalletLedgerEntry[];
    payments: PaymentTransactionItem[];
    campaigns: AdCampaignItem[];
    eligiblePosts: AdEligiblePostItem[];
    dailyStats: AdDailyStatItem[];
    statsSummary: AdStatsSummary;
    objectiveOptions: AdObjectiveOption[];
    topicSuggestions: CategoryOption[];
    pricing: AdPricingConfig;
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const activeTab = ref<'promote' | 'campaigns' | 'reports' | 'billing'>(
    'promote',
);
const previewMode = ref<'desktop' | 'mobile'>('desktop');
const postPickerOpen = ref(false);
const guideOpen = ref(false);
const cancelTargetId = ref<number | null>(null);

const selectedPost = ref<AdEligiblePostItem | null>(
    props.eligiblePosts[0] ?? null,
);

const form = useForm<{
    post_id: number;
    name: string;
    objective: 'reach' | 'engagement' | 'traffic';
    target_topics: string[];
    target_url: string;
    cta_label: string;
    schedule_mode: 'immediate' | 'scheduled';
    starts_at: string;
    ends_at: string;
    budget_type: 'daily' | 'total';
    daily_budget: number;
    duration_days: number;
    total_budget: number;
}>({
    post_id: props.eligiblePosts[0]?.id ?? 0,
    name: '',
    objective: 'reach',
    target_topics: ['Technology', 'Business'],
    target_url: '',
    cta_label: '',
    schedule_mode: 'immediate',
    starts_at: '',
    ends_at: '',
    budget_type: 'daily',
    daily_budget: 200_000,
    duration_days: 7,
    total_budget: 1_400_000,
});

const effectiveTotalBudget = computed<number>(() => {
    if (form.budget_type === 'daily') {
        const daily = Math.max(0, Math.trunc(Number(form.daily_budget) || 0));
        const days = Math.max(
            1,
            Math.min(90, Math.trunc(Number(form.duration_days) || 7)),
        );

        return daily * days;
    }

    return Math.max(0, Math.trunc(Number(form.total_budget) || 0));
});

const hasSufficientWallet = computed<boolean>(
    () =>
        props.wallet.balance >= effectiveTotalBudget.value &&
        effectiveTotalBudget.value > 0,
);

const remainingWalletAfter = computed<number>(() =>
    Math.max(0, props.wallet.balance - effectiveTotalBudget.value),
);

const walletUsagePercent = computed<number>(() => {
    if (props.wallet.balance <= 0) {
        return 100;
    }

    return Math.min(
        100,
        Math.round((effectiveTotalBudget.value / props.wallet.balance) * 100),
    );
});

const estimatedReachMin = computed<number>(() =>
    Math.round(
        effectiveTotalBudget.value /
            Math.max(1, props.pricing.impression_cost_vnd * 2),
    ),
);

const estimatedReachMax = computed<number>(() =>
    Math.round(
        effectiveTotalBudget.value /
            Math.max(1, props.pricing.impression_cost_vnd),
    ),
);

const estimatedClicksMin = computed<number>(() =>
    Math.round(
        effectiveTotalBudget.value /
            Math.max(1, props.pricing.click_cost_vnd * 2.5),
    ),
);

const estimatedClicksMax = computed<number>(() =>
    Math.round(
        effectiveTotalBudget.value / Math.max(1, props.pricing.click_cost_vnd),
    ),
);

function selectPost(post: AdEligiblePostItem): void {
    selectedPost.value = post;
    form.post_id = post.id;
    postPickerOpen.value = false;
}

function addTopic(label: string): void {
    const clean = label.trim();

    if (
        !clean ||
        form.target_topics.includes(clean) ||
        form.target_topics.length >= 5
    ) {
        return;
    }

    form.target_topics = [...form.target_topics, clean];
}

function removeTopic(topic: string): void {
    form.target_topics = form.target_topics.filter((item) => item !== topic);
}

function launchCampaign(): void {
    if (!selectedPost.value) {
        return;
    }

    form.post_id = selectedPost.value.id;
    form.total_budget = effectiveTotalBudget.value;
    form.post(AdCampaignController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            activeTab.value = 'campaigns';
        },
    });
}

function updateCampaignStatus(
    campaignId: number,
    action: 'pause' | 'resume' | 'cancel',
): void {
    router.post(
        AdCampaignController.updateStatus.url(campaignId),
        { action },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('ads.title')" />

        <!-- Top Breadcrumb Strip (Mockup 3) -->
        <nav
            :aria-label="t('accessibility.breadcrumb')"
            class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Link :href="home()" class="hover:text-foreground">
                {{ t('ads.breadcrumbRoot') }}
            </Link>
            <ChevronRight class="size-3.5" />
            <span>{{ t('ads.breadcrumbManager') }}</span>
            <ChevronRight class="size-3.5" />
            <span class="font-medium text-primary">{{
                t(`ads.tabs.${activeTab}`)
            }}</span>
        </nav>

        <!-- Header Banner + Tabs (Mockup 3) -->
        <section class="rounded-2xl border border-border bg-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Megaphone class="size-6" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-foreground">
                            {{ t('ads.title') }}
                        </h1>
                        <p
                            class="mt-0.5 text-xs leading-relaxed text-muted-foreground sm:text-sm"
                        >
                            {{ t('ads.subtitle') }}
                        </p>
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="rounded-lg text-xs"
                    @click="guideOpen = true"
                >
                    <HelpCircle class="size-4 text-primary" />
                    <span>{{ t('ads.guideButton') }}</span>
                </Button>
            </div>

            <div
                class="no-scrollbar mt-4 flex flex-nowrap items-center gap-2 overflow-x-auto border-t border-border pt-3.5 sm:flex-wrap sm:overflow-visible"
            >
                <Button
                    type="button"
                    size="sm"
                    :variant="activeTab === 'promote' ? 'secondary' : 'ghost'"
                    :class="[
                        'min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap',
                        activeTab === 'promote'
                            ? 'border-transparent bg-primary-soft font-semibold text-accent-foreground'
                            : '',
                    ]"
                    @click="activeTab = 'promote'"
                >
                    <Layers class="size-4" />
                    <span>{{ t('ads.tabs.promote') }}</span>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="activeTab === 'campaigns' ? 'secondary' : 'ghost'"
                    :class="[
                        'min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap',
                        activeTab === 'campaigns'
                            ? 'border-transparent bg-primary-soft font-semibold text-accent-foreground'
                            : '',
                    ]"
                    @click="activeTab = 'campaigns'"
                >
                    <Megaphone class="size-4" />
                    <span
                        >{{ t('ads.tabs.campaigns') }} ({{
                            campaigns.length
                        }})</span
                    >
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="activeTab === 'reports' ? 'secondary' : 'ghost'"
                    :class="[
                        'min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap',
                        activeTab === 'reports'
                            ? 'border-transparent bg-primary-soft font-semibold text-accent-foreground'
                            : '',
                    ]"
                    @click="activeTab = 'reports'"
                >
                    <BarChart3 class="size-4" />
                    <span>{{ t('ads.tabs.reports') }}</span>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="activeTab === 'billing' ? 'secondary' : 'ghost'"
                    :class="[
                        'min-h-9 shrink-0 rounded-lg text-xs whitespace-nowrap',
                        activeTab === 'billing'
                            ? 'border-transparent bg-primary-soft font-semibold text-accent-foreground'
                            : '',
                    ]"
                    @click="activeTab = 'billing'"
                >
                    <CreditCard class="size-4" />
                    <span>{{ t('ads.tabs.billing') }}</span>
                </Button>
            </div>
        </section>

        <!-- TAB 1: 5-STEP CAMPAIGN BUILDER + LIVE PREVIEW (Mockup 3) -->
        <div
            v-if="activeTab === 'promote'"
            class="grid items-start gap-5 lg:grid-cols-12"
        >
            <!-- Left Builder Column (Steps 1-5) -->
            <form
                @submit.prevent="launchCampaign"
                class="space-y-4 lg:col-span-7"
            >
                <!-- Step 1: Pick Post -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('ads.step1Title') }}
                    </h2>

                    <div
                        v-if="selectedPost"
                        class="flex items-start gap-3 rounded-xl border border-border bg-muted/25 p-3"
                    >
                        <img
                            v-if="selectedPost.image"
                            :src="selectedPost.image"
                            :alt="
                                selectedPost.title ||
                                t('ads.extra.postPreviewAlt')
                            "
                            class="size-16 shrink-0 rounded-lg object-cover"
                        />
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-semibold text-foreground"
                            >
                                {{
                                    selectedPost.title ||
                                    selectedPost.content.slice(0, 80)
                                }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-muted-foreground">
                                {{ selectedPost.category_label }} ·
                                {{ selectedPost.reactions_count }} 👍 ·
                                {{ selectedPost.comments_count }} 💬 ·
                                {{ selectedPost.shares_count }} ↗
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ t('ads.noEligiblePosts') }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="rounded-lg text-xs"
                            @click="postPickerOpen = true"
                        >
                            <Pencil class="size-3.5 text-primary" />
                            <span>{{ t('ads.pickAnotherPost') }}</span>
                        </Button>
                        <InputError :message="form.errors.post_id" />
                    </div>
                </section>

                <!-- Step 2: Objective -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('ads.step2Title') }}
                    </h2>

                    <div class="space-y-2">
                        <label
                            v-for="opt in objectiveOptions"
                            :key="opt.value"
                            :class="[
                                'flex cursor-pointer items-center justify-between gap-3 rounded-xl border p-3.5 transition-colors',
                                form.objective === opt.value
                                    ? 'border-primary bg-primary-soft/60'
                                    : 'border-border hover:border-primary/40',
                            ]"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary"
                                >
                                    <Users
                                        v-if="opt.value === 'reach'"
                                        class="size-4"
                                    />
                                    <ThumbsUp
                                        v-else-if="opt.value === 'engagement'"
                                        class="size-4"
                                    />
                                    <ExternalLink v-else class="size-4" />
                                </span>
                                <div>
                                    <p
                                        class="text-xs font-semibold text-foreground sm:text-sm"
                                    >
                                        {{ opt.label }}
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ opt.description }}
                                    </p>
                                </div>
                            </div>
                            <input
                                v-model="form.objective"
                                type="radio"
                                name="objective"
                                :value="opt.value"
                                class="size-4 accent-primary"
                            />
                        </label>
                    </div>
                    <InputError :message="form.errors.objective" />
                </section>

                <!-- Step 3: Targeting + Optional CTA URL -->
                <section
                    class="space-y-3.5 rounded-xl border border-border bg-card p-5"
                >
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('ads.step3Title') }}
                    </h2>

                    <div class="space-y-2">
                        <Label class="text-xs">{{
                            t('ads.topicsLabel')
                        }}</Label>
                        <div
                            class="flex min-h-10 flex-wrap items-center gap-1.5 rounded-xl border border-input bg-background p-2"
                        >
                            <Badge
                                v-for="topic in form.target_topics"
                                :key="topic"
                                variant="soft"
                                class="gap-1 text-xs"
                            >
                                <span>{{ topic }}</span>
                                <button
                                    type="button"
                                    :aria-label="
                                        t('ads.extra.removeTopic', { topic })
                                    "
                                    @click="removeTopic(topic)"
                                >
                                    <X class="size-3" />
                                </button>
                            </Badge>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-1.5 pt-1 text-xs"
                        >
                            <span class="text-muted-foreground">{{
                                t('ads.suggestions')
                            }}</span>
                            <button
                                v-for="cat in topicSuggestions"
                                :key="cat.value"
                                type="button"
                                class="rounded-full border border-border px-2.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary"
                                @click="addTopic(cat.label)"
                            >
                                {{ cat.label }}
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="ad-target-url" class="text-xs">{{
                                t('ads.targetUrlLabel')
                            }}</Label>
                            <Input
                                id="ad-target-url"
                                v-model="form.target_url"
                                type="url"
                                :placeholder="t('ads.extra.urlPlaceholder')"
                            />
                            <InputError :message="form.errors.target_url" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="ad-cta-label" class="text-xs">{{
                                t('ads.ctaLabel')
                            }}</Label>
                            <Input
                                id="ad-cta-label"
                                v-model="form.cta_label"
                                type="text"
                                maxlength="60"
                                :placeholder="t('ads.learnMoreDefault')"
                            />
                            <InputError :message="form.errors.cta_label" />
                        </div>
                    </div>
                </section>

                <!-- Step 4: Schedule -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5"
                >
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('ads.step4Title') }}
                    </h2>

                    <div class="space-y-2">
                        <label
                            :class="[
                                'flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors',
                                form.schedule_mode === 'immediate'
                                    ? 'border-primary bg-primary-soft/60'
                                    : 'border-border',
                            ]"
                        >
                            <input
                                v-model="form.schedule_mode"
                                type="radio"
                                value="immediate"
                                class="size-4 accent-primary"
                            />
                            <Zap class="size-4 text-primary" />
                            <div>
                                <p
                                    class="text-xs font-semibold text-foreground"
                                >
                                    {{ t('ads.runNow') }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ t('ads.runNowDesc') }}
                                </p>
                            </div>
                        </label>

                        <label
                            :class="[
                                'flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors',
                                form.schedule_mode === 'scheduled'
                                    ? 'border-primary bg-primary-soft/60'
                                    : 'border-border',
                            ]"
                        >
                            <input
                                v-model="form.schedule_mode"
                                type="radio"
                                value="scheduled"
                                class="size-4 accent-primary"
                            />
                            <Calendar class="size-4 text-muted-foreground" />
                            <div>
                                <p
                                    class="text-xs font-semibold text-foreground"
                                >
                                    {{ t('ads.scheduleRun') }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ t('ads.scheduleRunDesc') }}
                                </p>
                            </div>
                        </label>
                    </div>

                    <div
                        v-if="form.schedule_mode === 'scheduled'"
                        class="grid gap-3 pt-1 sm:grid-cols-2"
                    >
                        <div class="grid gap-1.5">
                            <Label for="ad-starts-at" class="text-xs">{{
                                t('ads.startsAt')
                            }}</Label>
                            <Input
                                id="ad-starts-at"
                                v-model="form.starts_at"
                                type="datetime-local"
                            />
                            <InputError :message="form.errors.starts_at" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="ad-ends-at" class="text-xs">{{
                                t('ads.endsAt')
                            }}</Label>
                            <Input
                                id="ad-ends-at"
                                v-model="form.ends_at"
                                type="datetime-local"
                            />
                            <InputError :message="form.errors.ends_at" />
                        </div>
                    </div>
                </section>

                <!-- Step 5: Budget + Launch Button -->
                <section
                    class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('ads.step5Title') }}
                    </h2>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                form.budget_type === 'daily'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            class="rounded-lg text-xs"
                            @click="form.budget_type = 'daily'"
                        >
                            {{ t('ads.budgetDaily') }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                form.budget_type === 'total'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            class="rounded-lg text-xs"
                            @click="form.budget_type = 'total'"
                        >
                            {{ t('ads.budgetTotal') }}
                        </Button>
                    </div>

                    <div v-if="form.budget_type === 'daily'" class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <Label for="ad-daily-budget" class="text-xs">{{
                                t('ads.dailyBudgetLabel')
                            }}</Label>
                            <Input
                                id="ad-daily-budget"
                                v-model.number="form.daily_budget"
                                type="number"
                                :min="pricing.min_daily_budget_vnd"
                                :max="pricing.max_daily_budget_vnd"
                                step="10000"
                                class="w-40 text-end font-semibold"
                            />
                        </div>
                        <input
                            v-model.number="form.daily_budget"
                            type="range"
                            :min="pricing.min_daily_budget_vnd"
                            :max="1000000"
                            step="10000"
                            class="w-full accent-primary"
                        />
                        <div
                            class="flex justify-between text-[11px] text-muted-foreground"
                        >
                            <span>{{
                                formatVnd(pricing.min_daily_budget_vnd, locale)
                            }}</span>
                            <span class="font-semibold text-foreground">{{
                                formatVnd(form.daily_budget, locale)
                            }}</span>
                            <span>{{ formatVnd(1000000, locale) }}</span>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <Label for="ad-duration" class="text-xs">{{
                                t('ads.durationDays')
                            }}</Label>
                            <select
                                id="ad-duration"
                                v-model.number="form.duration_days"
                                class="h-9 rounded-lg border border-input bg-background px-3 text-xs font-medium"
                            >
                                <option :value="3">3</option>
                                <option :value="7">7</option>
                                <option :value="14">14</option>
                                <option :value="30">30</option>
                            </select>
                        </div>
                    </div>

                    <div v-else class="grid gap-1.5">
                        <Label for="ad-total-budget" class="text-xs">{{
                            t('ads.totalBudgetLabel')
                        }}</Label>
                        <Input
                            id="ad-total-budget"
                            v-model.number="form.total_budget"
                            type="number"
                            inputmode="numeric"
                            :min="pricing.min_total_budget_vnd"
                            :max="pricing.max_total_budget_vnd"
                            step="50000"
                        />
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-primary/20 bg-primary-soft p-3.5"
                    >
                        <div>
                            <p class="text-xs font-bold text-foreground">
                                {{ t('ads.estimatedTotalBudget') }}
                            </p>
                            <p
                                v-if="form.budget_type === 'daily'"
                                class="text-[11px] text-muted-foreground"
                            >
                                {{
                                    t('ads.durationSummary', {
                                        days: form.duration_days,
                                        amount: formatVnd(
                                            form.daily_budget,
                                            locale,
                                        ),
                                    })
                                }}
                            </p>
                        </div>
                        <p class="text-base font-bold text-primary">
                            {{ formatVnd(effectiveTotalBudget, locale) }}
                        </p>
                    </div>

                    <InputError
                        :message="
                            (form.errors as Record<string, string | undefined>)
                                .budget ||
                            form.errors.daily_budget ||
                            form.errors.total_budget
                        "
                    />

                    <Button
                        type="submit"
                        class="h-11 w-full rounded-xl font-semibold"
                        :disabled="form.processing || !selectedPost"
                    >
                        <Spinner v-if="form.processing" />
                        <Rocket v-else class="size-4" />
                        <span>{{
                            form.processing
                                ? t('ads.launching')
                                : t('ads.launchButton')
                        }}</span>
                    </Button>
                </section>
            </form>

            <!-- Right Preview + Estimates + Wallet Balance Column (Mockup 3) -->
            <div class="space-y-4 lg:col-span-5">
                <!-- Live Ad Preview -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <div>
                        <h2 class="text-sm font-bold text-foreground">
                            {{ t('ads.previewTitle') }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ads.previewSubtitle') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                previewMode === 'desktop'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            class="rounded-lg text-xs"
                            @click="previewMode = 'desktop'"
                        >
                            <Monitor class="size-3.5" />
                            <span>{{ t('ads.previewDesktop') }}</span>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                previewMode === 'mobile'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            class="rounded-lg text-xs"
                            @click="previewMode = 'mobile'"
                        >
                            <Smartphone class="size-3.5" />
                            <span>{{ t('ads.previewMobile') }}</span>
                        </Button>
                    </div>

                    <div
                        v-if="selectedPost"
                        :class="[
                            'rounded-xl border border-border bg-background p-4 transition-[max-width] duration-200',
                            previewMode === 'mobile'
                                ? 'mx-auto max-w-[300px]'
                                : 'w-full',
                        ]"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5">
                                <Avatar class="size-9 rounded-full">
                                    <AvatarImage
                                        v-if="user?.avatar"
                                        :src="user.avatar"
                                        :alt="user.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-xs font-bold text-accent-foreground"
                                    >
                                        {{
                                            getInitials(
                                                user?.name ??
                                                    t(
                                                        'common.extra.userFallback',
                                                    ),
                                            )
                                        }}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <p
                                        class="text-xs font-bold text-foreground"
                                    >
                                        {{ user?.name }}
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ t('ads.sponsoredTag') }} · 🌐
                                    </p>
                                </div>
                            </div>
                            <Badge
                                variant="soft"
                                class="text-[10px] font-semibold"
                            >
                                <Zap class="me-1 size-3" />
                                {{ t('ads.sponsoredTag') }}
                            </Badge>
                        </div>

                        <div class="mt-3 space-y-2 text-xs">
                            <p
                                v-if="selectedPost.title"
                                class="text-sm font-bold text-foreground"
                            >
                                {{ selectedPost.title }}
                            </p>
                            <p class="line-clamp-3 text-muted-foreground">
                                {{ selectedPost.content }}
                            </p>
                            <img
                                v-if="selectedPost.image"
                                :src="selectedPost.image"
                                :alt="
                                    selectedPost.title ||
                                    t('ads.extra.adCreativeAlt')
                                "
                                class="max-h-44 w-full rounded-lg object-cover"
                            />
                        </div>

                        <div
                            class="mt-3 flex items-center justify-between rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs"
                        >
                            <span class="truncate font-semibold text-primary">
                                {{
                                    form.cta_label.trim() ||
                                    t('ads.learnMoreDefault')
                                }}
                            </span>
                            <ArrowRight class="size-3.5 text-primary" />
                        </div>
                    </div>
                </section>

                <!-- Reference Performance Estimates -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <div>
                        <h2
                            class="flex items-center gap-2 text-sm font-bold text-foreground"
                        >
                            <TrendingUp class="size-4 text-primary" />
                            <span>{{ t('ads.estimatesTitle') }}</span>
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ads.estimatesSubtitle') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div
                            class="rounded-xl border border-border bg-muted/25 p-2.5"
                        >
                            <Users class="mx-auto size-4 text-primary" />
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ t('ads.estReach') }}
                            </p>
                            <p class="mt-0.5 text-xs font-bold text-foreground">
                                {{ formatNumber(estimatedReachMin, locale) }}–{{
                                    formatNumber(estimatedReachMax, locale)
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-border bg-muted/25 p-2.5"
                        >
                            <MousePointerClick
                                class="mx-auto size-4 text-primary"
                            />
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ t('ads.estClicks') }}
                            </p>
                            <p class="mt-0.5 text-xs font-bold text-foreground">
                                {{
                                    formatNumber(estimatedClicksMin, locale)
                                }}–{{
                                    formatNumber(estimatedClicksMax, locale)
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-border bg-muted/25 p-2.5"
                        >
                            <WalletIcon class="mx-auto size-4 text-primary" />
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ t('ads.estAvgCpc') }}
                            </p>
                            <p class="mt-0.5 text-xs font-bold text-foreground">
                                ~
                                {{ formatVnd(pricing.click_cost_vnd, locale) }}
                            </p>
                        </div>
                    </div>

                    <p
                        class="text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ t('ads.estimatesDisclaimer') }}
                    </p>
                </section>

                <!-- Wallet Sufficiency Box -->
                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p
                                class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
                            >
                                <WalletIcon class="size-4 text-primary" />
                                <span>{{ t('ads.walletBoxTitle') }}</span>
                            </p>
                            <p class="mt-1 text-xl font-bold text-foreground">
                                {{ formatVnd(wallet.balance, locale) }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            class="rounded-lg text-xs"
                            as-child
                        >
                            <Link :href="walletIndex()">
                                <Plus class="size-3.5" />
                                <span>{{ t('ads.topUpShort') }}</span>
                            </Link>
                        </Button>
                    </div>

                    <div
                        class="flex items-center gap-1.5 text-xs font-medium"
                        :class="
                            hasSufficientWallet
                                ? 'text-success'
                                : 'text-destructive'
                        "
                    >
                        <CheckCircle2
                            v-if="hasSufficientWallet"
                            class="size-4 shrink-0"
                        />
                        <AlertCircle v-else class="size-4 shrink-0" />
                        <span>
                            {{
                                hasSufficientWallet
                                    ? t('ads.sufficientWallet', {
                                          amount: formatVnd(
                                              effectiveTotalBudget,
                                              locale,
                                          ),
                                      })
                                    : t('ads.insufficientWallet', {
                                          amount: formatVnd(
                                              effectiveTotalBudget -
                                                  wallet.balance,
                                              locale,
                                          ),
                                      })
                            }}
                        </span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            :class="[
                                'h-full transition-[width] duration-200',
                                hasSufficientWallet
                                    ? 'bg-primary'
                                    : 'bg-destructive',
                            ]"
                            :style="{ width: `${walletUsagePercent}%` }"
                        />
                    </div>

                    <p
                        v-if="hasSufficientWallet"
                        class="text-end text-[11px] text-muted-foreground"
                    >
                        {{
                            t('ads.remainingAfter', {
                                amount: formatVnd(remainingWalletAfter, locale),
                            })
                        }}
                    </p>
                </section>

                <!-- Notes Box -->
                <section
                    class="space-y-2 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <h3
                        class="flex items-center gap-1.5 text-xs font-bold text-foreground"
                    >
                        <Info class="size-4 text-primary" />
                        <span>{{ t('ads.notesTitle') }}</span>
                    </h3>
                    <ul
                        class="list-disc space-y-1.5 pl-4 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        <li>{{ t('ads.note1') }}</li>
                        <li>{{ t('ads.note2') }}</li>
                        <li>{{ t('ads.note3') }}</li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- TAB 2: MY CAMPAIGNS -->
        <section v-else-if="activeTab === 'campaigns'" class="space-y-3">
            <article
                v-for="campaign in campaigns"
                :key="campaign.id"
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold text-foreground">
                                {{ campaign.name }}
                            </h2>
                            <Badge
                                :variant="
                                    campaign.status === 'active'
                                        ? 'default'
                                        : campaign.status === 'rejected'
                                          ? 'destructive'
                                          : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{ campaign.status_label }}
                            </Badge>
                            <Badge variant="outline" class="text-[11px]">
                                {{ campaign.objective_label }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ formatVnd(campaign.spent_amount, locale) }} /
                            {{ formatVnd(campaign.total_budget, locale) }} ·
                            {{
                                formatNumber(campaign.impressions_count, locale)
                            }}
                            {{ t('ads.extra.viewsUnit') }} ·
                            {{ formatNumber(campaign.clicks_count, locale) }}
                            {{ t('ads.extra.clicksUnit') }} ({{
                                campaign.ctr_percent
                            }}% {{ t('ads.extra.ctrUnit') }})
                        </p>
                        <p
                            v-if="campaign.rejection_reason"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ campaign.rejection_reason }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="campaign.status === 'active'"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-8 rounded-lg text-xs"
                            @click="updateCampaignStatus(campaign.id, 'pause')"
                        >
                            <Pause class="size-3.5" />
                            <span>{{ t('ads.pause') }}</span>
                        </Button>
                        <Button
                            v-if="campaign.status === 'paused'"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-8 rounded-lg text-xs"
                            @click="updateCampaignStatus(campaign.id, 'resume')"
                        >
                            <Play class="size-3.5" />
                            <span>{{ t('ads.resume') }}</span>
                        </Button>
                        <Button
                            v-if="
                                campaign.status === 'pending_review' ||
                                campaign.status === 'active' ||
                                campaign.status === 'paused'
                            "
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="min-h-9 rounded-lg text-xs text-destructive hover:text-destructive sm:min-h-8"
                            @click="cancelTargetId = campaign.id"
                        >
                            <XCircle class="size-3.5" />
                            <span>{{ t('ads.cancelAndRefund') }}</span>
                        </Button>
                    </div>
                </div>
            </article>

            <ConfirmActionDialog
                :open="cancelTargetId !== null"
                :title="t('ads.cancelAndRefund')"
                :description="t('ads.note2')"
                :confirm-label="t('ads.cancelAndRefund')"
                destructive
                @update:open="
                    (val) => {
                        if (!val) cancelTargetId = null;
                    }
                "
                @confirm="
                    () => {
                        if (cancelTargetId !== null) {
                            updateCampaignStatus(cancelTargetId, 'cancel');
                            cancelTargetId = null;
                        }
                    }
                "
            />

            <EmptyState
                v-if="campaigns.length === 0"
                :icon="Megaphone"
                :title="t('ads.tabs.campaigns')"
                :description="t('ads.emptyCampaigns')"
            />
        </section>

        <!-- TAB 3: PERFORMANCE REPORTS -->
        <section v-else-if="activeTab === 'reports'" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    class="rounded-xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('ads.activeCampaigns') }}
                    </p>
                    <p class="mt-1.5 text-2xl font-bold text-primary">
                        {{ statsSummary.active_campaigns }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('ads.totalImpressions') }}
                    </p>
                    <p class="mt-1.5 text-2xl font-bold text-foreground">
                        {{
                            formatNumber(statsSummary.total_impressions, locale)
                        }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('ads.totalClicks') }} ({{
                            statsSummary.ctr_percent
                        }}%)
                    </p>
                    <p class="mt-1.5 text-2xl font-bold text-foreground">
                        {{ formatNumber(statsSummary.total_clicks, locale) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('ads.totalSpent') }}
                    </p>
                    <p class="mt-1.5 text-2xl font-bold text-foreground">
                        {{ formatVnd(statsSummary.total_spent, locale) }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5 shadow-xs">
                <h2 class="text-sm font-bold text-foreground">
                    {{ t('ads.dailyChartTitle') }}
                </h2>
                <div
                    v-if="dailyStats.length > 0"
                    class="mt-4 divide-y divide-border"
                >
                    <div
                        v-for="row in dailyStats"
                        :key="row.date"
                        class="flex items-center justify-between py-2.5 text-xs"
                    >
                        <span class="font-medium text-foreground">{{
                            row.date
                        }}</span>
                        <div
                            class="flex items-center gap-4 text-muted-foreground"
                        >
                            <span
                                >{{ formatNumber(row.impressions, locale) }}
                                {{ t('ads.extra.viewsUnit') }}</span
                            >
                            <span
                                >{{ formatNumber(row.clicks, locale) }}
                                {{ t('ads.extra.clicksUnit') }}</span
                            >
                            <span class="font-semibold text-foreground">{{
                                formatVnd(row.spent, locale)
                            }}</span>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-muted-foreground">
                    {{ t('ads.emptyCampaigns') }}
                </p>
            </div>
        </section>

        <!-- TAB 4: BILLING & INVOICES -->
        <section
            v-else
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-foreground">
                        {{ t('wallet.balance') }}:
                        <span class="text-primary">{{
                            formatVnd(wallet.balance, locale)
                        }}</span>
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ t('wallet.heldBalance') }}:
                        {{ formatVnd(wallet.held_balance, locale) }}
                    </p>
                </div>
                <Button size="sm" class="rounded-lg" as-child>
                    <Link :href="walletIndex()">
                        <Plus class="size-4" />
                        <span>{{ t('ads.topUpShort') }}</span>
                    </Link>
                </Button>
            </div>

            <div v-if="ledgerEntries.length > 0" class="divide-y divide-border">
                <div
                    v-for="entry in ledgerEntries"
                    :key="entry.id"
                    class="flex items-center justify-between gap-3 py-2.5 text-xs"
                >
                    <div>
                        <p class="font-semibold text-foreground">
                            {{ entry.type_label }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ entry.description }}
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="font-bold text-foreground">
                            {{ formatVnd(entry.amount, locale) }}
                        </p>
                        <p
                            class="flex items-center justify-end gap-1 text-[11px] text-muted-foreground"
                        >
                            <Clock class="size-3" />
                            <span>{{
                                formatDate(entry.created_at, locale)
                            }}</span>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Post Picker Modal -->
        <Dialog v-model:open="postPickerOpen">
            <DialogContent structured class="max-w-lg rounded-2xl">
                <div
                    class="shrink-0 border-b border-border/70 px-5 pe-12 pt-4 pb-3.5 sm:px-6 sm:pt-5 sm:pb-4"
                >
                    <div
                        class="mx-auto mb-3 h-1 w-10 rounded-full bg-border sm:hidden"
                        aria-hidden="true"
                    />
                    <DialogHeader class="text-start">
                        <DialogTitle>{{
                            t('ads.selectPostModal')
                        }}</DialogTitle>
                    </DialogHeader>
                </div>
                <AutoHideScrollArea
                    v-if="eligiblePosts.length > 0"
                    class="max-h-96 flex-1 space-y-2 px-5 py-4 sm:px-6"
                >
                    <button
                        v-for="item in eligiblePosts"
                        :key="item.id"
                        type="button"
                        class="flex w-full items-start gap-3 rounded-xl border border-border p-3 text-start transition-colors hover:border-primary/50 hover:bg-muted/40"
                        @click="selectPost(item)"
                    >
                        <img
                            v-if="item.image"
                            :src="item.image"
                            :alt="item.title || t('ads.extra.postAlt')"
                            class="size-12 shrink-0 rounded-lg object-cover"
                        />
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-semibold text-foreground"
                            >
                                {{ item.title || item.content.slice(0, 80) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ item.category_label }} ·
                                {{ item.reactions_count }} 👍
                            </p>
                        </div>
                    </button>
                </AutoHideScrollArea>
                <p
                    v-else
                    class="px-5 py-6 text-xs text-muted-foreground sm:px-6"
                >
                    {{ t('ads.noEligiblePosts') }}
                </p>
            </DialogContent>
        </Dialog>

        <!-- Usage Guide Modal -->
        <Dialog v-model:open="guideOpen">
            <DialogContent structured class="max-w-md rounded-2xl">
                <div
                    class="shrink-0 border-b border-border/70 px-5 pe-12 pt-4 pb-3.5 sm:px-6 sm:pt-5 sm:pb-4"
                >
                    <div
                        class="mx-auto mb-3 h-1 w-10 rounded-full bg-border sm:hidden"
                        aria-hidden="true"
                    />
                    <DialogHeader class="text-start">
                        <DialogTitle>{{ t('ads.guideTitle') }}</DialogTitle>
                    </DialogHeader>
                </div>
                <AutoHideScrollArea
                    class="flex-1 space-y-2.5 px-5 py-4 text-sm text-muted-foreground sm:px-6"
                >
                    <p>{{ t('ads.guidePoints.p1') }}</p>
                    <p>{{ t('ads.guidePoints.p2') }}</p>
                    <p>{{ t('ads.guidePoints.p3') }}</p>
                </AutoHideScrollArea>
            </DialogContent>
        </Dialog>
    </div>
</template>
