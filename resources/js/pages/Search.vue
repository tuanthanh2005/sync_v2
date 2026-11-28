<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    ChevronLeft,
    ChevronRight,
    Clock,
    Compass,
    Cpu,
    Hash,
    History,
    LayoutGrid,
    MessageCircleQuestion,
    Newspaper,
    Search as SearchIcon,
    ShoppingBag,
    Sparkles,
    TrendingUp,
    UserRound,
    Users,
    Wallet,
    X,
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
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import PostController from '@/actions/App/Http/Controllers/Social/PostController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import SearchController from '@/actions/App/Http/Controllers/Social/SearchController';
import AiBadge from '@/components/ai/AiBadge.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import LoadingSkeleton from '@/components/common/LoadingSkeleton.vue';
import MemberBadges from '@/components/growth/MemberBadges.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import PostCard from '@/components/social/PostCard.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import { formatNumber } from '@/lib/formatters';
import type {
    ActiveBannerDisplay,
    CategoryOption,
    CommunitySummary,
    HashtagItem,
    PublicFeedPost,
    SearchUserResult,
} from '@/types';

type SearchCommunityCard = Pick<
    CommunitySummary,
    | 'id'
    | 'name'
    | 'slug'
    | 'category_label'
    | 'avatar'
    | 'is_verified'
    | 'members_count'
>;

type SearchDiscoveryPayload = {
    has_real_trending: boolean;
    topics: HashtagItem[];
    categories: CategoryOption[];
    featured_posts: PublicFeedPost[];
    latest_posts: PublicFeedPost[];
    suggested_communities: SearchCommunityCard[];
    notable_accounts: SearchUserResult[];
};

type SuggestionsResponse = {
    query: string;
    users: Array<{
        id: number;
        name: string;
        username: string;
        avatar: string | null;
        is_verified: boolean;
    }>;
    communities: Array<{
        id: number;
        name: string;
        slug: string;
        members_count: number;
        avatar: string | null;
    }>;
    topics: HashtagItem[];
    posts: Array<{ id: number; title: string }>;
};

const props = withDefaults(
    defineProps<{
        mode?: 'explore' | 'search' | 'hashtag';
        query: string;
        hashtag: string;
        active_type?: string;
        posts: PublicFeedPost[];
        users: SearchUserResult[];
        banners?: Record<string, ActiveBannerDisplay | null>;
        communities: SearchCommunityCard[];
        hashtags: HashtagItem[];
        counts?: {
            all: number;
            posts: number;
            users: number;
            communities: number;
            topics: number;
        };
        pagination?: {
            current_page: number;
            last_page: number;
            total: number;
            has_more: boolean;
        };
        discovery?: SearchDiscoveryPayload;
    }>(),
    {
        mode: 'explore',
        active_type: 'all',
        banners: () => ({}),
        counts: () => ({
            all: 0,
            posts: 0,
            users: 0,
            communities: 0,
            topics: 0,
        }),
        pagination: () => ({
            current_page: 1,
            last_page: 1,
            total: 0,
            has_more: false,
        }),
        discovery: undefined,
    },
);

const CATEGORY_ICON_MAP: Record<string, Component> = {
    LayoutGrid,
    TrendingUp,
    Clock,
    Wallet,
    Cpu,
    BriefcaseBusiness,
    MessageCircleQuestion,
    ShoppingBag,
};

const RECENT_STORAGE_KEY = 'syncviet_recent_searches_v1';

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const keyword = ref(props.query);
const isSearching = ref(false);
const searchError = ref(false);
const suggestionsOpen = ref(false);
const loadingSuggestions = ref(false);
const suggestions = ref<SuggestionsResponse | null>(null);
const recentSearches = ref<string[]>([]);
const searchBoxRef = ref<HTMLElement | null>(null);
const categoryListRef = ref<HTMLElement | null>(null);
const canScrollCategoriesStart = ref(false);
const canScrollCategoriesEnd = ref(false);

let suggestTimer: ReturnType<typeof setTimeout> | null = null;
let suggestAbort: AbortController | null = null;
let categoryResizeObserver: ResizeObserver | null = null;

watch(
    () => props.query,
    (val) => {
        keyword.value = val;
    },
);

const isExploreMode = computed<boolean>(
    () =>
        props.mode === 'explore' &&
        !props.hashtag &&
        props.query.trim().length < 2,
);

const hasAnyResults = computed<boolean>(
    () =>
        props.posts.length > 0 ||
        props.users.length > 0 ||
        props.communities.length > 0 ||
        props.hashtags.length > 0,
);

const hasSuggestionsContent = computed<boolean>(() => {
    const s = suggestions.value;
    if (!s) {
        return false;
    }

    return (
        s.users.length > 0 ||
        s.communities.length > 0 ||
        s.topics.length > 0 ||
        s.posts.length > 0
    );
});

const resultTabs = computed(() => [
    {
        key: 'all',
        label: t('search.tabs.all'),
        count: props.counts?.all ?? 0,
    },
    {
        key: 'posts',
        label: t('search.tabs.posts'),
        count: props.counts?.posts ?? props.posts.length,
    },
    {
        key: 'users',
        label: t('search.tabs.users'),
        count: props.counts?.users ?? props.users.length,
    },
    {
        key: 'communities',
        label: t('search.tabs.communities'),
        count: props.counts?.communities ?? props.communities.length,
    },
    {
        key: 'topics',
        label: t('search.tabs.topics'),
        count: props.counts?.topics ?? props.hashtags.length,
    },
]);

function categoryIcon(name?: string): Component {
    return (name && CATEGORY_ICON_MAP[name]) || LayoutGrid;
}

function updateCategoryScrollState(): void {
    const el = categoryListRef.value;
    if (!el) {
        canScrollCategoriesStart.value = false;
        canScrollCategoriesEnd.value = false;
        return;
    }

    const maxScroll = el.scrollWidth - el.clientWidth;
    if (maxScroll <= 2) {
        canScrollCategoriesStart.value = false;
        canScrollCategoriesEnd.value = false;
        return;
    }

    const absScroll = Math.abs(el.scrollLeft);
    canScrollCategoriesStart.value = absScroll > 4;
    canScrollCategoriesEnd.value = absScroll < maxScroll - 4;
}

function scrollCategoriesBy(direction: -1 | 1): void {
    const el = categoryListRef.value;
    if (!el) {
        return;
    }

    const isRtl =
        typeof window !== 'undefined' &&
        window.getComputedStyle(el).direction === 'rtl';
    const dirFactor = isRtl ? -1 : 1;
    const step = Math.max(180, Math.round(el.clientWidth * 0.55));

    el.scrollBy({
        left: dirFactor * direction * step,
        behavior: 'smooth',
    });
}

function handleCategoryWheel(event: WheelEvent): void {
    const el = categoryListRef.value;
    if (!el || el.scrollWidth <= el.clientWidth) {
        return;
    }

    if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
        event.preventDefault();
        const isRtl =
            typeof window !== 'undefined' &&
            window.getComputedStyle(el).direction === 'rtl';
        el.scrollLeft += (isRtl ? -1 : 1) * event.deltaY;
        updateCategoryScrollState();
    }
}

watch(categoryListRef, (el) => {
    categoryResizeObserver?.disconnect();
    categoryResizeObserver = null;

    if (el && typeof ResizeObserver !== 'undefined') {
        categoryResizeObserver = new ResizeObserver(() => {
            updateCategoryScrollState();
        });
        categoryResizeObserver.observe(el);
    }

    void nextTick(() => updateCategoryScrollState());
});

watch(
    () => [props.discovery?.categories, isExploreMode.value, locale.value],
    () => {
        void nextTick(() => updateCategoryScrollState());
    },
);

function loadRecentSearches(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const raw = window.sessionStorage.getItem(RECENT_STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw) as unknown;
            if (Array.isArray(parsed)) {
                recentSearches.value = parsed
                    .filter(
                        (item): item is string =>
                            typeof item === 'string' && item.trim() !== '',
                    )
                    .slice(0, 6);
            }
        }
    } catch {
        // Ignore storage errors
    }
}

function saveRecentSearch(term: string): void {
    const clean = term.trim();
    if (clean.length < 2 || typeof window === 'undefined') {
        return;
    }

    const next = [
        clean,
        ...recentSearches.value.filter(
            (item) => item.toLowerCase() !== clean.toLowerCase(),
        ),
    ].slice(0, 6);

    recentSearches.value = next;
    try {
        window.sessionStorage.setItem(RECENT_STORAGE_KEY, JSON.stringify(next));
    } catch {
        // Ignore storage errors
    }
}

function clearRecentSearches(): void {
    recentSearches.value = [];
    if (typeof window !== 'undefined') {
        try {
            window.sessionStorage.removeItem(RECENT_STORAGE_KEY);
        } catch {
            // Ignore storage errors
        }
    }
}

async function fetchSuggestions(raw: string): Promise<void> {
    const q = raw.trim();
    if (q.length < 2) {
        suggestions.value = null;
        loadingSuggestions.value = false;
        return;
    }

    suggestAbort?.abort();
    suggestAbort = new AbortController();
    loadingSuggestions.value = true;

    try {
        const response = await fetch(
            SearchController.suggestions.url({ query: { q } }),
            {
                signal: suggestAbort.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (!response.ok) {
            suggestions.value = null;
            return;
        }
        suggestions.value = (await response.json()) as SuggestionsResponse;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            suggestions.value = null;
        }
    } finally {
        loadingSuggestions.value = false;
    }
}

function handleKeywordInput(): void {
    if (suggestTimer !== null) {
        clearTimeout(suggestTimer);
    }

    const q = keyword.value.trim();
    if (q.length < 2) {
        suggestions.value = null;
        return;
    }

    suggestionsOpen.value = true;
    suggestTimer = setTimeout(() => {
        void fetchSuggestions(q);
    }, 300);
}

function executeSearch(
    overrideQuery?: string,
    overrideType?: string,
    pageNumber = 1,
): void {
    if (suggestTimer !== null) {
        clearTimeout(suggestTimer);
    }
    suggestAbort?.abort();
    suggestionsOpen.value = false;
    searchError.value = false;

    const q = (overrideQuery ?? keyword.value).trim();
    keyword.value = q;

    if (q.length >= 2) {
        saveRecentSearch(q);
    }

    const queryParams: Record<string, string | number> = {};
    if (q !== '') {
        queryParams.q = q;
    }
    if (props.hashtag && q === '') {
        queryParams.hashtag = props.hashtag;
    }
    if (overrideType && overrideType !== 'all') {
        queryParams.type = overrideType;
    }
    if (pageNumber > 1) {
        queryParams.page = pageNumber;
    }

    isSearching.value = true;

    router.get(
        SearchController.url({ query: queryParams }),
        {},
        {
            preserveState: true,
            replace: pageNumber === 1,
            onError: () => {
                searchError.value = true;
            },
            onFinish: () => {
                isSearching.value = false;
            },
        },
    );
}

function resetToExplore(): void {
    keyword.value = '';
    suggestions.value = null;
    suggestionsOpen.value = false;
    searchError.value = false;
    executeSearch('', 'all', 1);
}

function handleOutsideClick(event: MouseEvent): void {
    const target = event.target as Node | null;
    if (target && searchBoxRef.value && !searchBoxRef.value.contains(target)) {
        suggestionsOpen.value = false;
    }
}

onMounted(() => {
    loadRecentSearches();
    if (typeof document !== 'undefined') {
        document.addEventListener('mousedown', handleOutsideClick);
    }
    if (typeof window !== 'undefined') {
        window.addEventListener('resize', updateCategoryScrollState, {
            passive: true,
        });
    }
    void nextTick(() => updateCategoryScrollState());
});

onBeforeUnmount(() => {
    if (suggestTimer !== null) {
        clearTimeout(suggestTimer);
    }
    suggestAbort?.abort();
    categoryResizeObserver?.disconnect();
    categoryResizeObserver = null;
    if (typeof document !== 'undefined') {
        document.removeEventListener('mousedown', handleOutsideClick);
    }
    if (typeof window !== 'undefined') {
        window.removeEventListener('resize', updateCategoryScrollState);
    }
});
</script>

<template>
    <div class="min-w-0 space-y-5">
        <Head
            :title="
                query
                    ? t('search.resultsTitle', { query })
                    : t('search.exploreTitle')
            "
        />

        <!-- Explore & Search Header + Debounced Typeahead Box -->
        <section
            ref="searchBoxRef"
            class="relative space-y-3 rounded-2xl border border-border bg-card p-4 shadow-xs sm:p-5"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Compass class="size-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 class="text-lg font-bold text-foreground">
                            {{ t('search.exploreHeading') }}
                        </h1>
                        <p class="text-xs text-muted-foreground">
                            {{ t('search.exploreSubtitle') }}
                        </p>
                    </div>
                </div>
            </div>

            <form
                @submit.prevent="executeSearch()"
                class="flex items-center gap-2 rounded-xl border border-input bg-muted/35 px-3 py-1.5 transition-colors focus-within:border-primary/50 focus-within:bg-card"
            >
                <SearchIcon
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="keyword"
                    type="search"
                    inputmode="search"
                    enterkeyhint="search"
                    autocomplete="off"
                    :aria-label="t('search.placeholder')"
                    :placeholder="t('search.placeholder')"
                    class="min-w-0 flex-1 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                    @focus="
                        if (keyword.trim().length >= 2) suggestionsOpen = true;
                    "
                    @input="handleKeywordInput"
                    @keydown.escape="suggestionsOpen = false"
                />
                <button
                    v-if="keyword"
                    type="button"
                    class="flex size-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    :aria-label="t('search.clearFilter')"
                    @click="resetToExplore"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
                <Button
                    type="submit"
                    size="sm"
                    class="min-h-9 shrink-0 rounded-lg px-4"
                >
                    {{ t('search.button') }}
                </Button>
            </form>

            <!-- State 2: Debounced Live Suggestions Popover -->
            <div
                v-if="
                    suggestionsOpen &&
                    keyword.trim().length >= 2 &&
                    (loadingSuggestions || hasSuggestionsContent)
                "
                role="listbox"
                :aria-label="t('search.suggestionsLabel')"
                class="absolute inset-x-4 top-[calc(100%-0.5rem)] z-30 mt-1 overflow-hidden rounded-xl border border-border bg-card shadow-lg sm:inset-x-5"
            >
                <div
                    v-if="loadingSuggestions"
                    class="space-y-2 p-3 text-xs text-muted-foreground"
                >
                    <div class="h-4 w-2/3 animate-pulse rounded bg-muted" />
                    <div class="h-4 w-1/2 animate-pulse rounded bg-muted" />
                </div>

                <div
                    v-else-if="suggestions"
                    class="max-h-80 divide-y divide-border overflow-y-auto overscroll-contain text-xs"
                >
                    <div v-if="suggestions.topics.length > 0" class="p-2.5">
                        <p
                            class="px-2 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ t('search.tabs.topics') }}
                        </p>
                        <div class="flex flex-wrap gap-1.5 px-1">
                            <Link
                                v-for="tag in suggestions.topics"
                                :key="tag.id"
                                :href="
                                    SearchController.url({
                                        query: { hashtag: tag.slug },
                                    })
                                "
                                class="inline-flex items-center gap-1 rounded-lg bg-primary-soft/70 px-2.5 py-1 font-medium text-accent-foreground hover:bg-primary-soft"
                                @click="suggestionsOpen = false"
                            >
                                <span>#{{ tag.name }}</span>
                                <span class="opacity-70"
                                    >({{ tag.posts_count }})</span
                                >
                            </Link>
                        </div>
                    </div>

                    <div v-if="suggestions.users.length > 0" class="p-2">
                        <p
                            class="px-2 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ t('search.usersSection') }}
                        </p>
                        <Link
                            v-for="u in suggestions.users"
                            :key="u.id"
                            :href="ProfileViewController.show.url(u.username)"
                            class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-surface-hover"
                            @click="suggestionsOpen = false"
                        >
                            <Avatar class="size-7 rounded-full">
                                <AvatarImage
                                    v-if="u.avatar"
                                    :src="u.avatar"
                                    :alt="u.name"
                                />
                                <AvatarFallback
                                    class="bg-primary-soft text-[10px] font-semibold text-accent-foreground"
                                >
                                    {{ getInitials(u.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                class="truncate font-semibold text-foreground"
                                >{{ u.name }}</span
                            >
                            <span class="truncate text-muted-foreground"
                                >@{{ u.username }}</span
                            >
                        </Link>
                    </div>

                    <div v-if="suggestions.communities.length > 0" class="p-2">
                        <p
                            class="px-2 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ t('search.communitiesSection') }}
                        </p>
                        <Link
                            v-for="c in suggestions.communities"
                            :key="c.id"
                            :href="CommunityController.show.url(c.slug)"
                            class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-surface-hover"
                            @click="suggestionsOpen = false"
                        >
                            <span
                                class="truncate font-semibold text-foreground"
                                >{{ c.name }}</span
                            >
                            <span class="shrink-0 text-muted-foreground">
                                {{
                                    t('communities.membersCount', {
                                        count: c.members_count,
                                    })
                                }}
                            </span>
                        </Link>
                    </div>

                    <div v-if="suggestions.posts.length > 0" class="p-2">
                        <p
                            class="px-2 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ t('search.postsSection') }}
                        </p>
                        <Link
                            v-for="p in suggestions.posts"
                            :key="p.id"
                            :href="PostController.show.url(p.id)"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-surface-hover"
                            @click="suggestionsOpen = false"
                        >
                            <Newspaper
                                class="size-3.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span class="truncate text-foreground">{{
                                p.title
                            }}</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Personal Recent Searches (session-scoped, clearable) -->
            <div
                v-if="recentSearches.length > 0 && isExploreMode"
                class="no-scrollbar flex flex-nowrap items-center gap-1.5 overflow-x-auto pt-0.5 text-xs sm:flex-wrap sm:overflow-visible"
            >
                <span
                    class="inline-flex shrink-0 items-center gap-1 text-[11px] whitespace-nowrap text-muted-foreground"
                >
                    <History class="size-3.5" aria-hidden="true" />
                    <span>{{ t('search.recentSearches') }}:</span>
                </span>
                <button
                    v-for="term in recentSearches"
                    :key="term"
                    type="button"
                    class="shrink-0 rounded-full border border-border bg-muted/40 px-2.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                    @click="executeSearch(term)"
                >
                    {{ term }}
                </button>
                <button
                    type="button"
                    class="ms-auto shrink-0 text-[11px] whitespace-nowrap text-muted-foreground hover:text-destructive"
                    @click="clearRecentSearches"
                >
                    {{ t('search.clearHistory') }}
                </button>
            </div>
        </section>

        <BannerSlot
            :banner="banners?.desktop_explore_top"
            class="hidden sm:block"
        />
        <BannerSlot :banner="banners?.mobile_explore" class="sm:hidden" />

        <!-- Active Hashtag Pill -->
        <div
            v-if="hashtag"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/20 bg-primary-soft px-4 py-2.5 text-xs"
        >
            <div class="flex items-center gap-2 font-medium text-foreground">
                <Hash class="size-4 text-primary" aria-hidden="true" />
                <span>{{ t('search.filteringByHashtag') }}:</span>
                <Badge variant="soft" class="font-semibold">
                    #{{ hashtag }}
                </Badge>
            </div>
            <button
                type="button"
                class="font-medium text-primary hover:underline"
                @click="resetToExplore"
            >
                {{ t('search.clearFilter') }}
            </button>
        </div>

        <!-- State 6: Explicit Search Error -->
        <ErrorState
            v-if="searchError"
            :message="t('search.errorLoadFailed')"
            @retry="executeSearch()"
        />

        <!-- State 3: Searching Loading Skeleton -->
        <LoadingSkeleton v-else-if="isSearching" variant="feed" :count="3" />

        <!-- State 1: Explore Discovery Mode (No Keyword Yet — NEVER "No results") -->
        <template v-else-if="isExploreMode && discovery">
            <!-- Categories Quick Browse -->
            <section
                v-if="discovery.categories.length > 0"
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('search.exploreCategories') }}
                </h2>
                <div class="relative min-w-0">
                    <div
                        ref="categoryListRef"
                        :aria-label="t('search.exploreCategories')"
                        class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto overscroll-x-contain scroll-smooth py-0.5 select-none"
                        @scroll.passive="updateCategoryScrollState"
                        @wheel="handleCategoryWheel"
                    >
                        <Link
                            v-for="cat in discovery.categories"
                            :key="cat.value"
                            :href="
                                HomeController.index.url({
                                    query: { tab: cat.value },
                                })
                            "
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-border bg-muted/30 px-3 py-2 text-xs font-medium whitespace-nowrap text-foreground transition-colors hover:border-primary/40 hover:bg-primary-soft/50 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <component
                                :is="categoryIcon(cat.icon)"
                                class="size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ cat.label }}</span>
                        </Link>
                    </div>

                    <div
                        v-if="canScrollCategoriesStart"
                        class="pointer-events-none absolute inset-y-0 start-0 z-10 flex items-center bg-gradient-to-r from-card via-card/90 to-transparent pe-4 rtl:bg-gradient-to-l"
                    >
                        <button
                            type="button"
                            :aria-label="t('common.previous')"
                            class="pointer-events-auto flex size-7 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-xs transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="scrollCategoriesBy(-1)"
                        >
                            <ChevronLeft
                                class="size-4 rtl:rotate-180"
                                aria-hidden="true"
                            />
                        </button>
                    </div>

                    <div
                        v-if="canScrollCategoriesEnd"
                        class="pointer-events-none absolute inset-y-0 end-0 z-10 flex items-center bg-gradient-to-l from-card via-card/90 to-transparent ps-4 rtl:bg-gradient-to-r"
                    >
                        <button
                            type="button"
                            :aria-label="t('common.next')"
                            class="pointer-events-auto flex size-7 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-xs transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="scrollCategoriesBy(1)"
                        >
                            <ChevronRight
                                class="size-4 rtl:rotate-180"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                </div>
            </section>

            <!-- Active Topics / Hashtags -->
            <section
                v-if="discovery.topics.length > 0"
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Hash class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('search.topicsOfInterest') }}</span>
                </h2>
                <div
                    class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto scroll-smooth py-0.5 sm:flex-wrap sm:overflow-visible sm:py-0"
                >
                    <Link
                        v-for="tag in discovery.topics"
                        :key="tag.id"
                        :href="
                            SearchController.url({
                                query: { hashtag: tag.slug },
                            })
                        "
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border bg-muted/35 px-3 py-1.5 text-xs font-medium whitespace-nowrap text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                    >
                        <span>#{{ tag.name }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >({{ formatNumber(tag.posts_count, locale) }})</span
                        >
                    </Link>
                </div>
            </section>

            <!-- Suggested Communities -->
            <section
                v-if="discovery.suggested_communities.length > 0"
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Users class="size-4 text-primary" aria-hidden="true" />
                        <span>{{ t('search.suggestedCommunities') }}</span>
                    </h2>
                    <Link
                        :href="CommunityController.index.url()"
                        class="shrink-0 text-xs font-medium text-primary hover:underline"
                    >
                        {{ t('search.viewAll') }}
                    </Link>
                </div>
                <div
                    class="no-scrollbar flex snap-x snap-mandatory flex-nowrap gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0"
                >
                    <Link
                        v-for="community in discovery.suggested_communities"
                        :key="community.id"
                        :href="CommunityController.show.url(community.slug)"
                        class="flex w-[250px] shrink-0 snap-start items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40 hover:bg-surface-hover/50 sm:w-auto sm:shrink"
                    >
                        <Avatar class="size-10 shrink-0 rounded-xl">
                            <AvatarImage
                                v-if="community.avatar"
                                :src="community.avatar"
                                :alt="community.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{ getInitials(community.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ community.name }}
                                </p>
                                <BadgeCheck
                                    v-if="community.is_verified"
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ community.category_label }} ·
                                {{
                                    t('communities.membersCount', {
                                        count: community.members_count,
                                    })
                                }}
                            </p>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- Notable Accounts -->
            <section
                v-if="discovery.notable_accounts.length > 0"
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <UserRound class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('search.notableAccounts') }}</span>
                </h2>
                <div
                    class="no-scrollbar flex snap-x snap-mandatory flex-nowrap gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0"
                >
                    <Link
                        v-for="person in discovery.notable_accounts"
                        :key="person.id"
                        :href="ProfileViewController.show.url(person.username)"
                        class="flex w-[250px] shrink-0 snap-start items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40 hover:bg-surface-hover/50 sm:w-auto sm:shrink"
                    >
                        <Avatar class="size-10 shrink-0 rounded-full">
                            <AvatarImage
                                v-if="person.avatar"
                                :src="person.avatar"
                                :alt="person.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{ getInitials(person.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ person.name }}
                                </p>
                                <PremiumBadge
                                    v-if="
                                        person.is_premium && !person.is_ai_agent
                                    "
                                    size="sm"
                                />
                                <AiBadge v-if="person.is_ai_agent" size="sm" />
                                <MemberBadges
                                    v-if="person.badges?.length"
                                    :badges="person.badges"
                                    size="sm"
                                    :max="1"
                                />
                                <BadgeCheck
                                    v-if="person.is_verified"
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                @{{ person.username }}
                                <span v-if="person.headline">
                                    · {{ person.headline }}
                                </span>
                            </p>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- Featured / Notable Posts (honest label: only labeled Trending if real engagement exists) -->
            <section
                v-if="discovery.featured_posts.length > 0"
                class="space-y-3.5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Sparkles class="size-4 text-primary" aria-hidden="true" />
                    <span>{{
                        discovery.has_real_trending
                            ? t('search.featuredPosts')
                            : t('search.latestPosts')
                    }}</span>
                </h2>
                <PostCard
                    v-for="post in discovery.featured_posts"
                    :key="post.id"
                    :post="post"
                    view-source="search"
                />
            </section>

            <!-- Latest Posts -->
            <section
                v-if="discovery.latest_posts.length > 0"
                class="space-y-3.5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Clock class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('search.latestPosts') }}</span>
                </h2>
                <PostCard
                    v-for="post in discovery.latest_posts"
                    :key="post.id"
                    :post="post"
                    view-source="search"
                />
            </section>
        </template>

        <!-- State 4: Search Has Results -->
        <template v-else-if="hasAnyResults">
            <!-- Result Category Filter Tabs -->
            <div
                v-if="!hashtag"
                role="tablist"
                :aria-label="t('search.filterTabsLabel')"
                class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0"
            >
                <button
                    v-for="tab in resultTabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="active_type === tab.key"
                    :class="[
                        'inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors',
                        active_type === tab.key
                            ? 'border-primary/25 bg-primary-soft font-semibold text-accent-foreground'
                            : 'border-border bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                    ]"
                    @click="executeSearch(keyword, tab.key, 1)"
                >
                    <span>{{ tab.label }}</span>
                    <span
                        class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold tabular-nums"
                    >
                        {{ formatNumber(tab.count, locale) }}
                    </span>
                </button>
            </div>

            <!-- Matching Topics / Hashtags -->
            <section
                v-if="
                    hashtags.length > 0 &&
                    (active_type === 'all' || active_type === 'topics')
                "
                class="min-w-0 space-y-2.5 rounded-xl border border-border bg-card p-4 sm:p-5"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('search.hashtagsSection') }}
                </h2>
                <div
                    class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto scroll-smooth py-0.5 sm:flex-wrap sm:overflow-visible sm:py-0"
                >
                    <Link
                        v-for="tag in hashtags"
                        :key="tag.id"
                        :href="
                            SearchController.url({
                                query: { hashtag: tag.slug },
                            })
                        "
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium whitespace-nowrap text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                    >
                        <span>#{{ tag.name }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >({{ formatNumber(tag.posts_count, locale) }})</span
                        >
                    </Link>
                </div>
            </section>

            <!-- Matching Communities -->
            <section
                v-if="
                    communities.length > 0 &&
                    (active_type === 'all' || active_type === 'communities')
                "
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 sm:p-5"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Users class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('search.communitiesSection') }}</span>
                </h2>
                <div
                    class="no-scrollbar flex snap-x snap-mandatory flex-nowrap gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0"
                >
                    <Link
                        v-for="community in communities"
                        :key="community.id"
                        :href="CommunityController.show.url(community.slug)"
                        class="flex w-[250px] shrink-0 snap-start items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40 sm:w-auto sm:shrink"
                    >
                        <Avatar class="size-10 shrink-0 rounded-xl">
                            <AvatarImage
                                v-if="community.avatar"
                                :src="community.avatar"
                                :alt="community.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{ getInitials(community.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ community.name }}
                                </p>
                                <BadgeCheck
                                    v-if="community.is_verified"
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ community.category_label }} ·
                                {{
                                    t('communities.membersCount', {
                                        count: community.members_count,
                                    })
                                }}
                            </p>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- Matching Users -->
            <section
                v-if="
                    users.length > 0 &&
                    (active_type === 'all' || active_type === 'users')
                "
                class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('search.usersSection') }}
                </h2>
                <div
                    class="no-scrollbar flex snap-x snap-mandatory flex-nowrap gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0"
                >
                    <Link
                        v-for="person in users"
                        :key="person.id"
                        :href="ProfileViewController.show.url(person.username)"
                        class="flex w-[250px] shrink-0 snap-start items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40 sm:w-auto sm:shrink"
                    >
                        <Avatar class="size-10 shrink-0 rounded-full">
                            <AvatarImage
                                v-if="person.avatar"
                                :src="person.avatar"
                                :alt="person.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{ getInitials(person.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ person.name }}
                                </p>
                                <PremiumBadge
                                    v-if="
                                        person.is_premium && !person.is_ai_agent
                                    "
                                    size="sm"
                                />
                                <AiBadge v-if="person.is_ai_agent" size="sm" />
                                <BadgeCheck
                                    v-if="person.is_verified"
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                @{{ person.username }}
                            </p>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- Matching Posts + Pagination -->
            <section
                v-if="
                    posts.length > 0 &&
                    (active_type === 'all' || active_type === 'posts')
                "
                class="space-y-4"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('search.postsSection') }}
                </h2>
                <PostCard
                    v-for="post in posts"
                    :key="post.id"
                    :post="post"
                    view-source="search"
                />

                <div
                    v-if="pagination && pagination.last_page > 1"
                    class="flex items-center justify-center gap-2 pt-2"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="pagination.current_page <= 1"
                        @click="
                            executeSearch(
                                keyword,
                                active_type,
                                pagination.current_page - 1,
                            )
                        "
                    >
                        {{ t('common.previous') }}
                    </Button>
                    <span
                        class="px-2 text-xs text-muted-foreground tabular-nums"
                    >
                        {{ pagination.current_page }} /
                        {{ pagination.last_page }}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!pagination.has_more"
                        @click="
                            executeSearch(
                                keyword,
                                active_type,
                                pagination.current_page + 1,
                            )
                        "
                    >
                        {{ t('common.next') }}
                    </Button>
                </div>
            </section>
        </template>

        <!-- State 5: No Results For Query + Clearly Separated Discovery Recommendations -->
        <template v-else>
            <section
                class="rounded-2xl border border-border bg-card p-6 text-center shadow-xs sm:p-8"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
                >
                    <SearchIcon class="size-6" aria-hidden="true" />
                </div>
                <h2 class="mt-3 text-base font-bold text-foreground">
                    {{
                        t('search.noResultsForQuery', {
                            query: query || `#${hashtag}`,
                        })
                    }}
                </h2>
                <p
                    class="mx-auto mt-1.5 max-w-md text-xs leading-relaxed text-muted-foreground"
                >
                    {{ t('search.noResultsTips') }}
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        class="rounded-lg"
                        @click="resetToExplore"
                    >
                        <Compass class="size-4" aria-hidden="true" />
                        <span>{{ t('search.exploreMore') }}</span>
                    </Button>
                </div>
            </section>

            <!-- Clearly separated Discovery Recommendations -->
            <div v-if="discovery" class="space-y-4">
                <div class="flex items-center gap-2 px-1">
                    <Sparkles class="size-4 text-primary" aria-hidden="true" />
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        {{ t('search.recommendationsHeading') }}
                    </p>
                </div>

                <section
                    v-if="discovery.topics.length > 0"
                    class="min-w-0 space-y-2.5 rounded-xl border border-border bg-card p-4 sm:p-5"
                >
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('search.topicsOfInterest') }}
                    </h3>
                    <div
                        class="no-scrollbar flex flex-nowrap items-center gap-2 overflow-x-auto scroll-smooth py-0.5 sm:flex-wrap sm:overflow-visible sm:py-0"
                    >
                        <Link
                            v-for="tag in discovery.topics"
                            :key="tag.id"
                            :href="
                                SearchController.url({
                                    query: { hashtag: tag.slug },
                                })
                            "
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium whitespace-nowrap text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                        >
                            <span>#{{ tag.name }}</span>
                            <span class="text-muted-foreground tabular-nums"
                                >({{ tag.posts_count }})</span
                            >
                        </Link>
                    </div>
                </section>

                <section
                    v-if="discovery.suggested_communities.length > 0"
                    class="min-w-0 space-y-3 rounded-xl border border-border bg-card p-4 sm:p-5"
                >
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('search.suggestedCommunities') }}
                    </h3>
                    <div
                        class="no-scrollbar flex snap-x snap-mandatory flex-nowrap gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0"
                    >
                        <Link
                            v-for="community in discovery.suggested_communities"
                            :key="community.id"
                            :href="CommunityController.show.url(community.slug)"
                            class="flex w-[250px] shrink-0 snap-start items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40 sm:w-auto sm:shrink"
                        >
                            <Avatar class="size-9 shrink-0 rounded-xl">
                                <AvatarImage
                                    v-if="community.avatar"
                                    :src="community.avatar"
                                    :alt="community.name"
                                />
                                <AvatarFallback
                                    class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                                >
                                    {{ getInitials(community.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ community.name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ community.category_label }}
                                </p>
                            </div>
                        </Link>
                    </div>
                </section>

                <section
                    v-if="discovery.latest_posts.length > 0"
                    class="space-y-3.5"
                >
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('search.latestPosts') }}
                    </h3>
                    <PostCard
                        v-for="post in discovery.latest_posts"
                        :key="post.id"
                        :post="post"
                        view-source="search"
                    />
                </section>
            </div>
        </template>
    </div>
</template>
