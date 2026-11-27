<script setup lang="ts">
import {
    AlertCircle,
    Heart,
    Lightbulb,
    RefreshCw,
    Search,
    SmilePlus,
    ThumbsUp,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PostInteractionController from '@/actions/App/Http/Controllers/Social/PostInteractionController';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import SocialUserListItem from '@/components/social/SocialUserListItem.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatCompactNumber, formatNumber } from '@/lib/formatters';
import type { SocialDirectoryUserItem } from '@/types';

type ReactionFilterKey = 'all' | 'like' | 'love' | 'insightful';

type ReactionUsersPayload = {
    total_count: number;
    counts_by_type: Record<'like' | 'love' | 'insightful', number>;
    active_type: string | null;
    items: SocialDirectoryUserItem[];
    pagination: {
        current_page: number;
        per_page: number;
        has_more: boolean;
        next_page: number | null;
    };
};

const REACTION_TABS: Array<{
    key: 'like' | 'love' | 'insightful';
    icon: Component;
    labelKey: string;
    iconClass: string;
    activeClass: string;
}> = [
    {
        key: 'like',
        icon: ThumbsUp,
        labelKey: 'post.like',
        iconClass: 'text-primary',
        activeClass: 'border-primary/40 bg-primary-soft text-primary',
    },
    {
        key: 'love',
        icon: Heart,
        labelKey: 'post.love',
        iconClass: 'text-destructive',
        activeClass:
            'border-destructive/40 bg-destructive-soft text-destructive',
    },
    {
        key: 'insightful',
        icon: Lightbulb,
        labelKey: 'post.insightful',
        iconClass: 'text-warning',
        activeClass: 'border-warning/40 bg-warning-soft text-warning',
    },
];

const props = withDefaults(
    defineProps<{
        open: boolean;
        postId: number;
        initialTotal?: number;
        initialType?: ReactionFilterKey;
    }>(),
    {
        initialTotal: 0,
        initialType: 'all',
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { t, locale } = useI18n();

const activeFilter = ref<ReactionFilterKey>('all');
const searchQuery = ref('');
const loading = ref(false);
const loadingMore = ref(false);
const errorMessage = ref<string | null>(null);
const totalCount = ref(props.initialTotal);
const countsByType = ref<Record<'like' | 'love' | 'insightful', number>>({
    like: 0,
    love: 0,
    insightful: 0,
});
const items = ref<SocialDirectoryUserItem[]>([]);
const hasMore = ref(false);
const nextPage = ref<number | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | null = null;
let activeAbortController: AbortController | null = null;

const populatedTabs = computed(() =>
    REACTION_TABS.filter((tab) => (countsByType.value[tab.key] ?? 0) > 0),
);

const showMultiTabs = computed(() => populatedTabs.value.length > 1);

const showSearch = computed(
    () => totalCount.value >= 8 || searchQuery.value.trim().length > 0,
);

async function fetchReactions(page = 1, append = false): Promise<void> {
    if (!props.open || props.postId <= 0) {
        return;
    }

    activeAbortController?.abort();
    activeAbortController = new AbortController();

    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
        errorMessage.value = null;
    }

    try {
        const query: Record<string, string | number> = {
            page,
            per_page: 15,
        };
        if (activeFilter.value !== 'all') {
            query.type = activeFilter.value;
        }
        const trimmed = searchQuery.value.trim();
        if (trimmed !== '') {
            query.search = trimmed;
        }

        const url = PostInteractionController.reactions.url(props.postId, {
            query,
        });
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            signal: activeAbortController.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = (await response.json()) as ReactionUsersPayload;
        totalCount.value = data.total_count;
        countsByType.value = {
            like: data.counts_by_type?.like ?? 0,
            love: data.counts_by_type?.love ?? 0,
            insightful: data.counts_by_type?.insightful ?? 0,
        };
        items.value = append ? [...items.value, ...data.items] : data.items;
        hasMore.value = Boolean(data.pagination?.has_more);
        nextPage.value = data.pagination?.next_page ?? null;
    } catch (err) {
        if ((err as { name?: string })?.name === 'AbortError') {
            return;
        }
        errorMessage.value = t('socialList.loadError');
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}

function selectFilter(next: ReactionFilterKey): void {
    if (activeFilter.value === next) {
        return;
    }
    activeFilter.value = next;
    void fetchReactions(1, false);
}

function clearSearch(): void {
    searchQuery.value = '';
    void fetchReactions(1, false);
}

function loadMore(): void {
    if (!hasMore.value || nextPage.value === null || loadingMore.value) {
        return;
    }
    void fetchReactions(nextPage.value, true);
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            activeFilter.value = props.initialType;
            searchQuery.value = '';
            totalCount.value = props.initialTotal;
            items.value = [];
            void fetchReactions(1, false);
        } else {
            activeAbortController?.abort();
        }
    },
);

watch(searchQuery, () => {
    if (!props.open) {
        return;
    }
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
    searchTimer = setTimeout(() => {
        void fetchReactions(1, false);
    }, 280);
});

onBeforeUnmount(() => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
    activeAbortController?.abort();
});
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            structured
            :show-close-button="false"
            data-test="reaction-users-modal"
            class="max-h-[85dvh] max-sm:top-auto max-sm:bottom-0 max-sm:left-0 max-sm:h-[86dvh] max-sm:max-h-[86dvh] max-sm:w-full max-sm:max-w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-t-2xl max-sm:rounded-b-none sm:max-w-[560px]"
        >
            <!-- Balanced Sticky Header -->
            <div
                class="flex min-h-15 shrink-0 items-center justify-between gap-4 border-b border-border bg-card px-5 py-3.5 sm:px-6"
            >
                <DialogHeader
                    class="flex min-w-0 flex-1 flex-row items-center gap-2.5 space-y-0 text-start"
                >
                    <DialogTitle
                        class="truncate text-base font-semibold text-foreground sm:text-lg"
                    >
                        {{ t('socialList.reactionsTitle') }}
                    </DialogTitle>
                    <span
                        class="inline-flex shrink-0 items-center rounded-full border border-border bg-muted/60 px-2.5 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums"
                    >
                        {{ formatNumber(totalCount, locale) }}
                    </span>
                </DialogHeader>

                <DialogClose as-child>
                    <button
                        type="button"
                        data-test="reaction-modal-close"
                        :aria-label="t('common.close')"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </button>
                </DialogClose>
            </div>

            <!-- Filter Tabs (only when multiple reaction types exist) / Summary Chip + Search -->
            <div
                v-if="totalCount > 0 || searchQuery.trim().length > 0"
                class="shrink-0 space-y-2.5 border-b border-border/80 bg-muted/20 px-5 py-2.5 sm:px-6"
            >
                <div
                    v-if="showMultiTabs"
                    role="tablist"
                    :aria-label="t('socialList.filterReactionsAria')"
                    class="no-scrollbar flex flex-nowrap items-center gap-1.5 overflow-x-auto py-0.5"
                >
                    <button
                        type="button"
                        role="tab"
                        data-test="reaction-tab-all"
                        :aria-selected="activeFilter === 'all'"
                        :class="[
                            'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                            activeFilter === 'all'
                                ? 'border-primary/40 bg-primary-soft font-semibold text-primary'
                                : 'border-border bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                        ]"
                        @click="selectFilter('all')"
                    >
                        <span>{{ t('socialList.allReactions') }}</span>
                        <span class="tabular-nums opacity-80">
                            {{ formatCompactNumber(totalCount, locale) }}
                        </span>
                    </button>

                    <button
                        v-for="tab in populatedTabs"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :data-test="`reaction-tab-${tab.key}`"
                        :aria-selected="activeFilter === tab.key"
                        :aria-label="`${t(tab.labelKey)} (${countsByType[tab.key]})`"
                        :class="[
                            'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                            activeFilter === tab.key
                                ? `${tab.activeClass} font-semibold`
                                : 'border-border bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                        ]"
                        @click="selectFilter(tab.key)"
                    >
                        <component
                            :is="tab.icon"
                            :class="['size-3.5 shrink-0', tab.iconClass]"
                            aria-hidden="true"
                        />
                        <span>{{ t(tab.labelKey) }}</span>
                        <span class="tabular-nums opacity-80">
                            {{
                                formatCompactNumber(
                                    countsByType[tab.key] ?? 0,
                                    locale,
                                )
                            }}
                        </span>
                    </button>
                </div>

                <!-- Single-type compact summary (no fake zero-count tabs) -->
                <div
                    v-else-if="populatedTabs.length === 1"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 font-medium text-foreground"
                    >
                        <component
                            :is="populatedTabs[0].icon"
                            :class="['size-3.5', populatedTabs[0].iconClass]"
                            aria-hidden="true"
                        />
                        <span>{{ t(populatedTabs[0].labelKey) }}</span>
                        <span class="text-muted-foreground tabular-nums">
                            {{ formatNumber(totalCount, locale) }}
                        </span>
                    </span>
                </div>

                <!-- Debounced search when list is long -->
                <div v-if="showSearch" class="relative">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="searchQuery"
                        type="search"
                        data-test="reaction-search-input"
                        :placeholder="t('socialList.searchPeople')"
                        class="h-9 rounded-xl bg-card ps-8.5 pe-8 text-xs sm:text-xs"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        :aria-label="t('socialList.clearSearch')"
                        class="absolute end-2 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                        @click="clearSearch"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <!-- Scrollable User List Body -->
            <AutoHideScrollArea
                class="min-h-0 flex-1 px-2.5 py-2 pb-[calc(0.75rem+env(safe-area-inset-bottom,0px))] sm:px-3.5 sm:py-2.5"
            >
                <!-- Loading Skeleton -->
                <div
                    v-if="loading"
                    class="space-y-1.5 p-1"
                    aria-busy="true"
                    aria-live="polite"
                >
                    <div
                        v-for="n in 4"
                        :key="n"
                        class="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2.5"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                class="size-11 shrink-0 animate-pulse rounded-full bg-muted"
                            />
                            <div class="min-w-0 flex-1 space-y-2">
                                <div
                                    class="h-3.5 w-32 animate-pulse rounded-md bg-muted"
                                />
                                <div
                                    class="h-3 w-44 animate-pulse rounded-md bg-muted/70"
                                />
                            </div>
                        </div>
                        <div
                            class="h-8 w-20 shrink-0 animate-pulse rounded-lg bg-muted"
                        />
                    </div>
                </div>

                <!-- Error State -->
                <div
                    v-else-if="errorMessage"
                    class="flex flex-col items-center justify-center gap-3 px-4 py-10 text-center"
                >
                    <div
                        class="flex size-11 items-center justify-center rounded-full bg-destructive-soft text-destructive"
                    >
                        <AlertCircle class="size-5" aria-hidden="true" />
                    </div>
                    <p class="text-sm font-medium text-foreground">
                        {{ errorMessage }}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="gap-1.5 rounded-lg"
                        @click="fetchReactions(1, false)"
                    >
                        <RefreshCw class="size-3.5" aria-hidden="true" />
                        <span>{{ t('socialList.retry') }}</span>
                    </Button>
                </div>

                <!-- Empty State -->
                <div
                    v-else-if="items.length === 0"
                    data-test="reaction-empty-state"
                    class="flex flex-col items-center justify-center gap-2.5 px-4 py-12 text-center"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground"
                    >
                        <Search
                            v-if="searchQuery.trim()"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <SmilePlus v-else class="size-5" aria-hidden="true" />
                    </div>
                    <p class="text-sm font-semibold text-foreground">
                        {{
                            searchQuery.trim()
                                ? t('socialList.noSearchResults')
                                : t('socialList.noReactionsYet')
                        }}
                    </p>
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{
                            searchQuery.trim()
                                ? t('socialList.noSearchResultsHint')
                                : t('socialList.noReactionsYetHint')
                        }}
                    </p>
                    <Button
                        v-if="searchQuery.trim()"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="mt-1 rounded-lg text-xs"
                        @click="clearSearch"
                    >
                        {{ t('socialList.clearSearch') }}
                    </Button>
                </div>

                <!-- User Rows -->
                <div v-else class="divide-y divide-border/50">
                    <SocialUserListItem
                        v-for="member in items"
                        :key="member.id"
                        :item="member"
                        show-reaction-badge
                        @navigate="emit('update:open', false)"
                    />
                </div>

                <!-- Load More Pagination -->
                <div
                    v-if="!loading && hasMore"
                    class="flex justify-center pt-3 pb-1"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="reaction-load-more"
                        :disabled="loadingMore"
                        class="min-w-[140px] gap-1.5 rounded-xl text-xs"
                        @click="loadMore"
                    >
                        <Spinner v-if="loadingMore" class="size-3.5" />
                        <span>{{ t('socialList.loadMore') }}</span>
                    </Button>
                </div>
            </AutoHideScrollArea>
        </DialogContent>
    </Dialog>
</template>
