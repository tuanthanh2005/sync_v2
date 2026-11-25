<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, ArrowUp, MessageSquareText } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import HomeController from '@/actions/App/Http/Controllers/HomeController';
import EmptyState from '@/components/common/EmptyState.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import LoadingSkeleton from '@/components/common/LoadingSkeleton.vue';
import PostCard from '@/components/social/PostCard.vue';
import PostComposer from '@/components/social/PostComposer.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import type { CategoryOption, PublicFeedPost } from '@/types';

const props = withDefaults(
    defineProps<{
        posts?: PublicFeedPost[];
        sponsoredPost?: PublicFeedPost | null;
        nextCursor?: string | null;
        hasMore?: boolean;
        activeTab?: string;
        categories?: CategoryOption[];
    }>(),
    {
        posts: () => [],
        sponsoredPost: null,
        nextCursor: null,
        hasMore: false,
        activeTab: 'trending',
        categories: () => [],
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

const tabs = computed(() => [
    { key: 'trending', label: t('home.feed.tabs.trending') },
    { key: 'latest', label: t('home.feed.tabs.latest') },
    { key: 'finance', label: t('home.feed.tabs.finance') },
    { key: 'technology', label: t('home.feed.tabs.technology') },
    { key: 'business', label: t('home.feed.tabs.business') },
    { key: 'qa', label: t('home.feed.tabs.qa') },
    { key: 'marketplace', label: t('home.feed.tabs.marketplace') },
]);

function switchTab(tabKey: string): void {
    hasNewPostsBanner.value = false;
    router.get(
        HomeController.index.url({ query: { tab: tabKey } }),
        {},
        {
            preserveState: true,
            preserveScroll: false,
        },
    );
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
        void checkForNewerPosts();
    }, 90_000);
});

onBeforeUnmount(() => {
    feedObserver?.disconnect();
    loadAbortController?.abort();
    if (freshnessTimer !== null) {
        clearInterval(freshnessTimer);
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

        <!-- News Feed Category Tabs (Horizontal Snap Strip on Mobile) -->
        <div
            role="tablist"
            :aria-label="t('home.feed.categoriesLabel')"
            class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto pb-0.5 select-none sm:flex-wrap sm:overflow-visible"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.key"
                :class="[
                    'min-h-9 shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    activeTab === tab.key
                        ? 'bg-primary-soft font-semibold text-accent-foreground'
                        : 'border border-border bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                ]"
                @click="switchTab(tab.key)"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- Post Composer -->
        <PostComposer :categories="categories" />

        <!-- Public Posts Stream -->
        <section v-if="feedItems.length > 0" class="space-y-3.5 sm:space-y-4">
            <PostCard
                v-for="post in feedItems"
                :key="
                    post.is_sponsored
                        ? `ad-${post.ad_campaign?.id ?? post.id}`
                        : `post-${post.id}`
                "
                :post="post"
                :categories="categories"
            />

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
