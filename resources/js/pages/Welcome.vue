<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUp,
    BriefcaseBusiness,
    ChevronLeft,
    ChevronRight,
    Clock,
    Cpu,
    LayoutGrid,
    MessageCircleQuestion,
    MessageSquareText,
    ShoppingBag,
    Sparkles,
    TrendingUp,
    Users,
    Wallet,
} from '@lucide/vue';
import type { Component } from 'vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import HomeController from '@/actions/App/Http/Controllers/HomeController';
import EmptyState from '@/components/common/EmptyState.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import LoadingSkeleton from '@/components/common/LoadingSkeleton.vue';
import AdSenseSlot from '@/components/monetization/AdSenseSlot.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PostCard from '@/components/social/PostCard.vue';
import PostComposer from '@/components/social/PostComposer.vue';
import { Button } from '@/components/ui/button';
import { isModalRuntimeActive } from '@/composables/useModalRuntimeLock';
import { login, register } from '@/routes';
import type {
    ActiveBannerDisplay,
    AdSensePageConfig,
    CategoryOption,
    PublicFeedPost,
} from '@/types';

const props = withDefaults(
    defineProps<{
        posts?: PublicFeedPost[];
        sponsoredPost?: PublicFeedPost | null;
        banners?: Record<string, ActiveBannerDisplay | null>;
        adSense?: AdSensePageConfig | null;
        nextCursor?: string | null;
        hasMore?: boolean;
        activeTab?: string;
        categories?: CategoryOption[];
        feedConfig?: {
            enable_trending?: boolean;
            enable_recommended?: boolean;
        };
    }>(),
    {
        posts: () => [],
        sponsoredPost: null,
        banners: () => ({}),
        adSense: null,
        nextCursor: null,
        hasMore: false,
        activeTab: 'trending',
        categories: () => [],
        feedConfig: () => ({ enable_trending: true, enable_recommended: true }),
    },
);

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const brandName = computed(() => page.props.name);

const feedItems = computed<PublicFeedPost[]>(() => {
    const items = [...organicItems.value];

    if (props.sponsoredPost) {
        const insertIndex = items.length >= 1 ? 1 : 0;
        items.splice(insertIndex, 0, props.sponsoredPost);
    }

    return items;
});
const organicItems = ref<PublicFeedPost[]>([...props.posts]);
const cursor = ref<string | null>(props.nextCursor);
const moreAvailable = ref<boolean>(props.hasMore);
const loadingMore = ref(false);
const loadError = ref(false);
const hasNewPostsBanner = ref(false);
const loadMoreSentinel = ref<HTMLElement | null>(null);

let feedObserver: IntersectionObserver | null = null;
let freshnessTimer: ReturnType<typeof setInterval> | null = null;
let loadAbortController: AbortController | null = null;

const MAX_RENDERED_POSTS = 120;

watch(
    () => [props.posts, props.nextCursor, props.hasMore],
    ([newPosts, newCursor, newHasMore]) => {
        organicItems.value = [...(newPosts as PublicFeedPost[])];
        cursor.value = newCursor as string | null;
        moreAvailable.value = Boolean(newHasMore);
        loadError.value = false;
        hasNewPostsBanner.value = false;
    },
);

const tabs = computed<Array<{ key: string; label: string; icon: Component }>>(
    () => [
        ...(props.feedConfig?.enable_trending !== false
            ? [
                  {
                      key: 'trending',
                      label: t('home.feed.tabs.trending'),
                      icon: TrendingUp,
                  },
              ]
            : []),
        ...(props.feedConfig?.enable_recommended !== false
            ? [
                  {
                      key: 'recommended',
                      label: t('home.feed.tabs.recommended'),
                      icon: Sparkles,
                  },
              ]
            : []),
        { key: 'latest', label: t('home.feed.tabs.latest'), icon: Clock },
        ...(user.value
            ? [
                  {
                      key: 'following',
                      label: t('home.feed.tabs.following'),
                      icon: Users,
                  },
              ]
            : []),
        { key: 'finance', label: t('home.feed.tabs.finance'), icon: Wallet },
        { key: 'technology', label: t('home.feed.tabs.technology'), icon: Cpu },
        {
            key: 'business',
            label: t('home.feed.tabs.business'),
            icon: BriefcaseBusiness,
        },
        {
            key: 'qa',
            label: t('home.feed.tabs.qa'),
            icon: MessageCircleQuestion,
        },
        {
            key: 'marketplace',
            label: t('home.feed.tabs.marketplace'),
            icon: ShoppingBag,
        },
    ],
);

const tabListRef = ref<HTMLElement | null>(null);
const tabButtonRefs = ref<Record<string, HTMLButtonElement | null>>({});
const canScrollTabsLeft = ref(false);
const canScrollTabsRight = ref(false);

function updateTabScrollState(): void {
    const el = tabListRef.value;
    if (!el) {
        canScrollTabsLeft.value = false;
        canScrollTabsRight.value = false;
        return;
    }

    const maxScroll = el.scrollWidth - el.clientWidth;
    if (maxScroll <= 2) {
        canScrollTabsLeft.value = false;
        canScrollTabsRight.value = false;
        return;
    }

    const absLeft = Math.abs(el.scrollLeft);
    canScrollTabsLeft.value = absLeft > 4;
    canScrollTabsRight.value = absLeft < maxScroll - 4;
}

function scrollTabsBy(direction: -1 | 1): void {
    const el = tabListRef.value;
    if (!el) {
        return;
    }

    el.scrollBy({
        left: direction * Math.max(180, Math.round(el.clientWidth * 0.55)),
        behavior: 'smooth',
    });
}

function handleTabWheel(event: WheelEvent): void {
    const el = tabListRef.value;
    if (!el || el.scrollWidth <= el.clientWidth) {
        return;
    }

    if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
        event.preventDefault();
        el.scrollLeft += event.deltaY;
        updateTabScrollState();
    }
}

function scrollActiveTabIntoView(tabKey: string): void {
    void nextTick(() => {
        const el = tabButtonRefs.value[tabKey];
        if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({
                inline: 'center',
                block: 'nearest',
                behavior: 'smooth',
            });
        }
        updateTabScrollState();
    });
}

watch(
    () => props.activeTab,
    (tabKey) => {
        scrollActiveTabIntoView(tabKey);
    },
);

function switchTab(tabKey: string): void {
    hasNewPostsBanner.value = false;
    scrollActiveTabIntoView(tabKey);
    router.get(
        HomeController.index.url({ query: { tab: tabKey } }),
        {},
        {
            preserveState: true,
            preserveScroll: false,
        },
    );
}

function handlePostHidden(postId: number): void {
    organicItems.value = organicItems.value.filter((p) => p.id !== postId);
}

function reloadFreshFeed(): void {
    hasNewPostsBanner.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    router.reload({
        only: ['posts', 'sponsoredPost', 'nextCursor', 'hasMore'],
    });
}

async function loadMorePosts(): Promise<void> {
    if (
        !cursor.value ||
        loadingMore.value ||
        organicItems.value.length >= MAX_RENDERED_POSTS
    ) {
        return;
    }

    loadingMore.value = true;
    loadError.value = false;

    loadAbortController?.abort();
    loadAbortController = new AbortController();

    try {
        const response = await fetch(
            HomeController.more.url({
                query: {
                    tab: props.activeTab,
                    cursor: cursor.value,
                },
            }),
            {
                signal: loadAbortController.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) {
            throw new Error('Failed to load feed');
        }

        const payload = (await response.json()) as {
            data: PublicFeedPost[];
            next_cursor: string | null;
            has_more: boolean;
        };

        const existingIds = new Set(organicItems.value.map((p) => p.id));
        const uniqueNew = payload.data.filter((p) => !existingIds.has(p.id));

        organicItems.value = [...organicItems.value, ...uniqueNew];
        cursor.value = payload.next_cursor;
        moreAvailable.value =
            payload.has_more && organicItems.value.length < MAX_RENDERED_POSTS;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            loadError.value = true;
        }
    } finally {
        loadingMore.value = false;
    }
}

async function checkForNewerPosts(): Promise<void> {
    if (
        typeof document === 'undefined' ||
        document.hidden ||
        !navigator.onLine ||
        window.scrollY < 320 ||
        hasNewPostsBanner.value ||
        organicItems.value.length === 0
    ) {
        return;
    }

    try {
        const response = await fetch(
            HomeController.more.url({
                query: { tab: props.activeTab },
            }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (!response.ok) {
            return;
        }
        const payload = (await response.json()) as { data?: PublicFeedPost[] };
        const latestTopId = payload.data?.[0]?.id;
        const currentTopId = organicItems.value[0]?.id;
        if (latestTopId && currentTopId && latestTopId !== currentTopId) {
            hasNewPostsBanner.value = true;
        }
    } catch {
        // Ignore silent background freshness check failures
    }
}

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    if ('IntersectionObserver' in window && loadMoreSentinel.value) {
        feedObserver = new IntersectionObserver(
            (entries) => {
                if (
                    entries.some((entry) => entry.isIntersecting) &&
                    moreAvailable.value &&
                    !loadingMore.value &&
                    !loadError.value
                ) {
                    void loadMorePosts();
                }
            },
            { rootMargin: '320px 0px' },
        );
        feedObserver.observe(loadMoreSentinel.value);
    }

    freshnessTimer = setInterval(() => {
        if (
            document.hidden ||
            !navigator.onLine ||
            isModalRuntimeActive.value
        ) {
            return;
        }
        void checkForNewerPosts();
    }, 90_000);

    void nextTick(() => updateTabScrollState());
    window.addEventListener('resize', updateTabScrollState, { passive: true });
});

onBeforeUnmount(() => {
    feedObserver?.disconnect();
    loadAbortController?.abort();
    if (freshnessTimer !== null) {
        clearInterval(freshnessTimer);
    }
    if (typeof window !== 'undefined') {
        window.removeEventListener('resize', updateTabScrollState);
    }
});
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('home.pageTitle')" />

        <!-- Guest-only Commercial Hero Banner -->
        <section
            v-if="!user"
            class="overflow-hidden rounded-2xl border border-border bg-card p-6 sm:p-8"
        >
            <p
                class="inline-flex items-center rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold tracking-wider text-accent-foreground uppercase"
            >
                {{ t('home.hero.eyebrow', { brand: brandName }) }}
            </p>

            <h1
                class="mt-3 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
            >
                {{ t('home.hero.heading') }}
            </h1>

            <p
                class="mt-2.5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base"
            >
                {{ t('home.hero.description') }}
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <Button class="rounded-lg font-semibold" as-child>
                    <Link :href="register()">
                        <span>{{ t('home.hero.primaryCta') }}</span>
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
                <Button
                    variant="secondary"
                    class="rounded-lg font-medium"
                    as-child
                >
                    <Link :href="login()">
                        {{ t('home.hero.secondaryCta') }}
                    </Link>
                </Button>
            </div>
        </section>

        <!-- Non-disruptive New Posts Pill (never jumps reading position automatically) -->
        <div
            v-if="hasNewPostsBanner"
            class="sticky top-16 z-30 flex justify-center sm:top-20"
        >
            <Button
                type="button"
                size="sm"
                class="min-h-10 gap-1.5 rounded-full px-4 text-xs font-semibold shadow-md"
                @click="reloadFreshFeed"
            >
                <ArrowUp class="size-3.5" aria-hidden="true" />
                <span>{{ t('home.feed.newPostsAvailable') }}</span>
            </Button>
        </div>

        <!-- News Feed Category Tabs (Single-row horizontal scroll with hidden scrollbar) -->
        <div class="relative min-w-0">
            <div
                ref="tabListRef"
                role="tablist"
                :aria-label="t('home.feed.categoriesLabel')"
                class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto scroll-smooth py-0.5 select-none"
                @scroll.passive="updateTabScrollState"
                @wheel="handleTabWheel"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    :ref="
                        (el) => {
                            tabButtonRefs[tab.key] =
                                el as HTMLButtonElement | null;
                        }
                    "
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    :class="[
                        'inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        activeTab === tab.key
                            ? 'border-primary/25 bg-primary-soft font-semibold text-accent-foreground'
                            : 'border-border bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                    ]"
                    @click="switchTab(tab.key)"
                >
                    <component
                        :is="tab.icon || LayoutGrid"
                        :class="[
                            'size-4 shrink-0',
                            activeTab === tab.key ? 'text-primary' : '',
                        ]"
                        aria-hidden="true"
                    />
                    <span>{{ tab.label }}</span>
                </button>
            </div>

            <!-- Subtle Start/End scroll arrows when overflowing (Desktop-friendly) -->
            <div
                v-if="canScrollTabsLeft"
                class="pointer-events-none absolute inset-y-0 start-0 hidden items-center bg-gradient-to-r from-background via-background/85 to-transparent pe-4 sm:flex"
            >
                <button
                    type="button"
                    :aria-label="t('common.previous')"
                    class="pointer-events-auto flex size-7 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-xs transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="scrollTabsBy(-1)"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </button>
            </div>

            <div
                v-if="canScrollTabsRight"
                class="pointer-events-none absolute inset-y-0 end-0 hidden items-center bg-gradient-to-l from-background via-background/85 to-transparent ps-4 sm:flex"
            >
                <button
                    type="button"
                    :aria-label="t('common.next')"
                    class="pointer-events-auto flex size-7 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-xs transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="scrollTabsBy(1)"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>

        <!-- Desktop Feed Top Banner (auto-collapses if vacant) -->
        <BannerSlot
            :banner="banners?.desktop_feed_top"
            class="hidden sm:block"
        />

        <!-- Post Composer -->
        <PostComposer :categories="categories" />

        <!-- Mobile Below Composer Banner (auto-collapses if vacant) -->
        <BannerSlot
            :banner="banners?.mobile_after_composer"
            class="sm:hidden"
        />

        <!-- Public Posts Stream -->
        <section v-if="feedItems.length > 0" class="space-y-3.5 sm:space-y-4">
            <template
                v-for="(post, idx) in feedItems"
                :key="
                    post.is_sponsored
                        ? `ad-${post.ad_campaign?.id ?? post.id}`
                        : `post-${post.id}`
                "
            >
                <PostCard
                    :post="post"
                    :categories="categories"
                    @hidden="handlePostHidden"
                />
                <BannerSlot
                    v-if="idx === 2 && banners?.mobile_in_feed"
                    :banner="banners.mobile_in_feed"
                    class="sm:hidden"
                />
                <AdSenseSlot
                    v-if="idx === 3"
                    :config="adSense"
                    slot-key="in_feed"
                    :has-direct-banner="Boolean(banners?.mobile_in_feed)"
                />
            </template>

            <LoadingSkeleton v-if="loadingMore" variant="feed" :count="2" />

            <ErrorState
                v-if="loadError"
                :message="t('home.feed.loadingMore')"
                @retry="loadMorePosts"
            />

            <div ref="loadMoreSentinel" class="h-1" aria-hidden="true" />

            <div
                v-if="moreAvailable && !loadingMore"
                class="flex justify-center pt-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 rounded-xl px-6 sm:min-h-9"
                    @click="loadMorePosts"
                >
                    {{ t('home.feed.loadMore') }}
                </Button>
            </div>
        </section>

        <!-- Professional Empty State -->
        <EmptyState
            v-else
            :icon="MessageSquareText"
            :title="t('home.empty.title', { brand: brandName })"
            :description="t('home.empty.description')"
        />
    </div>
</template>
