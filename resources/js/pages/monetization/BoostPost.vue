<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Sparkles, Wallet as WalletIcon, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BoostPostModal from '@/components/monetization/BoostPostModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { defaultCurrencyForLocale, formatMoney } from '@/lib/currency';
import { login } from '@/routes';
import { index as walletIndex } from '@/routes/wallet';
import type {
    BoostPackageItem,
    PaymentMethodPublic,
    PlatformCapabilities,
} from '@/types';

const props = defineProps<{
    packages: BoostPackageItem[];
    eligiblePosts: Array<{
        id: number;
        title: string;
        excerpt: string;
        reactions_count: number;
        comments_count: number;
        published_at: string | null;
    }>;
    paymentMethods: PaymentMethodPublic[];
    platformCapabilities: PlatformCapabilities;
    pendingOrdersCount: number;
    maxPendingOrders: number;
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const currency = ref<'VND' | 'USD'>(defaultCurrencyForLocale(locale.value));

const modalOpen = ref(false);
const activePostId = ref<number>(props.eligiblePosts[0]?.id ?? 0);
const activePostExcerpt = ref<string>(props.eligiblePosts[0]?.title ?? '');

function startBoostForPost(postId: number, excerpt: string): void {
    if (!user.value) {
        router.visit(login.url());

        return;
    }

    activePostId.value = postId;
    activePostExcerpt.value = excerpt;
    modalOpen.value = true;
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('monetization.boost.pageTitle')" />

        <section
            class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-border bg-card p-6 shadow-xs"
        >
            <div class="max-w-2xl space-y-2">
                <Badge variant="soft" class="gap-1.5 text-xs">
                    <Zap class="size-3.5" />
                    <span>{{
                        t('monetization.checkout.boostBadgeTitle')
                    }}</span>
                </Badge>
                <h1 class="text-2xl font-bold text-foreground">
                    {{ t('monetization.boost.heading') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('monetization.boost.subtitle') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <div
                    class="inline-flex rounded-xl border border-border bg-muted/40 p-1"
                >
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-xs font-semibold',
                            currency === 'VND'
                                ? 'bg-card text-primary shadow-2xs'
                                : 'text-muted-foreground',
                        ]"
                        @click="currency = 'VND'"
                    >
                        {{ t('monetization.checkout.currencyVnd') }}
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-xs font-semibold',
                            currency === 'USD'
                                ? 'bg-card text-primary shadow-2xs'
                                : 'text-muted-foreground',
                        ]"
                        @click="currency = 'USD'"
                    >
                        {{ t('monetization.checkout.currencyUsd') }}
                    </button>
                </div>
                <Button v-if="user" variant="outline" size="sm" as-child>
                    <Link :href="walletIndex()">
                        <WalletIcon class="size-4" />
                        <span>{{ t('monetization.wallet.title') }}</span>
                    </Link>
                </Button>
            </div>
        </section>

        <!-- Packages Grid -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="pkg in packages"
                :key="pkg.id"
                :class="[
                    'flex flex-col justify-between rounded-2xl border bg-card p-5 shadow-xs',
                    pkg.is_featured_slot
                        ? 'border-primary ring-1 ring-primary/30'
                        : 'border-border',
                ]"
            >
                <div class="space-y-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-bold text-foreground">
                            {{ pkg.name }}
                        </h2>
                        <Badge
                            v-if="pkg.is_featured_slot"
                            variant="soft"
                            class="gap-1 text-[10px]"
                        >
                            <Sparkles class="size-3" />
                            <span>{{ t('monetization.boost.featured') }}</span>
                        </Badge>
                    </div>
                    <p class="text-2xl font-bold text-primary">
                        {{
                            formatMoney(
                                currency === 'USD'
                                    ? pkg.price_usd_cents
                                    : pkg.price_vnd,
                                currency,
                                locale,
                            )
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ pkg.description }}
                    </p>
                    <ul class="space-y-1.5 pt-1 text-xs text-muted-foreground">
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.boost.bulletFixed')
                            }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <CheckCircle2
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <span>{{
                                t('monetization.boost.bulletTransparent')
                            }}</span>
                        </li>
                    </ul>
                </div>
            </article>
        </section>

        <!-- Eligible Posts Quick Boost -->
        <section
            v-if="user"
            class="space-y-3 rounded-2xl border border-border bg-card p-5"
        >
            <h2 class="text-base font-bold text-foreground">
                {{ t('monetization.boost.selectPostTitle') }}
            </h2>

            <div v-if="eligiblePosts.length > 0" class="divide-y divide-border">
                <div
                    v-for="p in eligiblePosts"
                    :key="p.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate text-sm font-semibold text-foreground"
                        >
                            {{ p.title }}
                        </p>
                        <p class="line-clamp-1 text-xs text-muted-foreground">
                            {{ p.excerpt }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        class="rounded-lg"
                        @click="startBoostForPost(p.id, p.title)"
                    >
                        <Zap class="size-3.5" />
                        <span>{{ t('monetization.boost.ctaButton') }}</span>
                    </Button>
                </div>
            </div>

            <p v-else class="text-xs text-muted-foreground">
                {{ t('monetization.boost.noEligiblePosts') }}
            </p>
        </section>

        <BoostPostModal
            v-if="activePostId > 0"
            v-model:open="modalOpen"
            :post-id="activePostId"
            :post-excerpt="activePostExcerpt"
        />
    </div>
</template>
