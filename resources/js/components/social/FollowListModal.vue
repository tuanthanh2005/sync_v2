<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    Compass,
    Lock,
    RefreshCw,
    Search,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
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
import {
    getLiveFollowersCount,
    getLiveFollowingCount,
    syncProfileCounts,
} from '@/composables/useFollowState';
import { formatCompactNumber, formatNumber } from '@/lib/formatters';
import type { SocialDirectoryUserItem } from '@/types';

export type FollowModalTab = 'followers' | 'following';

type FollowListPayload = {
    tab: FollowModalTab;
    counts: {
        followers: number;
        following: number;
    };
    restricted: boolean;
    items: SocialDirectoryUserItem[];
    pagination: {
        current_page: number;
        per_page: number;
        has_more: boolean;
        next_page: number | null;
    };
};

const props = defineProps<{
    open: boolean;
    profileUserId: number;
    profileName: string;
    profileUsername: string;
    initialTab: FollowModalTab;
    initialFollowersCount: number;
    initialFollowingCount: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { t, locale } = useI18n();

const activeTab = ref<FollowModalTab>(props.initialTab);
const searchQuery = ref('');
const loading = ref(false);
const loadingMore = ref(false);
const restricted = ref(false);
const errorMessage = ref<string | null>(null);
const items = ref<SocialDirectoryUserItem[]>([]);
const hasMore = ref(false);
const nextPage = ref<number | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | null = null;
let activeAbortController: AbortController | null = null;

const liveFollowersCount = computed(() =>
    getLiveFollowersCount(props.profileUserId, props.initialFollowersCount),
);
const liveFollowingCount = computed(() =>
    getLiveFollowingCount(props.profileUserId, props.initialFollowingCount),
);

const activeCount = computed(() =>
    activeTab.value === 'followers'
        ? liveFollowersCount.value
        : liveFollowingCount.value,
);

const headerTitle = computed(() =>
    activeTab.value === 'followers'
        ? t('socialList.followersTitle')
        : t('socialList.followingTitle'),
);

const searchPlaceholder = computed(() =>
    activeTab.value === 'followers'
        ? t('socialList.searchFollowers')
        : t('socialList.searchFollowing'),
);

const showSearch = computed(
    () =>
        !restricted.value &&
        (activeCount.value > 0 || searchQuery.value.trim().length > 0),
);

async function fetchList(page = 1, append = false): Promise<void> {
    if (!props.open || !props.profileUsername) {
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
        const trimmed = searchQuery.value.trim();
        if (trimmed !== '') {
            query.search = trimmed;
        }

        const url =
            activeTab.value === 'followers'
                ? ProfileViewController.followers.url(props.profileUsername, {
                      query,
                  })
                : ProfileViewController.following.url(props.profileUsername, {
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

        const data = (await response.json()) as FollowListPayload;
        restricted.value = Boolean(data.restricted);

        if (data.counts) {
            syncProfileCounts(props.profileUserId, {
                followers: data.counts.followers,
                following: data.counts.following,
            });
        }

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

function switchTab(next: FollowModalTab): void {
    if (activeTab.value === next) {
        return;
    }
    activeTab.value = next;
    searchQuery.value = '';
    items.value = [];
    void fetchList(1, false);
}

function clearSearch(): void {
    searchQuery.value = '';
    void fetchList(1, false);
}

function loadMore(): void {
    if (!hasMore.value || nextPage.value === null || loadingMore.value) {
        return;
    }
    void fetchList(nextPage.value, true);
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            activeTab.value = props.initialTab;
            searchQuery.value = '';
            items.value = [];
            void fetchList(1, false);
        } else {
            activeAbortController?.abort();
        }
    },
);

watch(
    () => props.initialTab,
    (tab) => {
        if (props.open && tab !== activeTab.value) {
            activeTab.value = tab;
            searchQuery.value = '';
            items.value = [];
            void fetchList(1, false);
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
        void fetchList(1, false);
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
            data-test="follow-list-modal"
            class="max-h-[85dvh] max-sm:top-auto max-sm:bottom-0 max-sm:left-0 max-sm:h-[86dvh] max-sm:max-h-[86dvh] max-sm:w-full max-sm:max-w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-t-2xl max-sm:rounded-b-none sm:max-w-[560px]"
        >
            <!-- Clean Sticky Header -->
            <div
                class="flex min-h-15 shrink-0 items-center justify-between gap-4 border-b border-border bg-card px-5 py-3.5 sm:px-6"
            >
                <DialogHeader class="min-w-0 flex-1 gap-0.5 text-start">
                    <DialogTitle
                        class="flex items-center gap-2 text-base font-semibold text-foreground sm:text-lg"
                    >
                        <span class="truncate">{{ headerTitle }}</span>
                        <span
                            class="inline-flex shrink-0 items-center rounded-full border border-border bg-muted/60 px-2.5 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums"
                        >
                            {{ formatNumber(activeCount, locale) }}
                        </span>
                    </DialogTitle>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ profileName }} · @{{ profileUsername }}
                    </p>
                </DialogHeader>

                <DialogClose as-child>
                    <button
                        type="button"
                        data-test="follow-modal-close"
                        :aria-label="t('common.close')"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </button>
                </DialogClose>
            </div>

            <!-- 2-Tab Bar + Search -->
            <div
                class="shrink-0 space-y-2.5 border-b border-border/80 bg-muted/20 px-5 py-2.5 sm:px-6"
            >
                <div
                    role="tablist"
                    :aria-label="t('socialList.followTabsAria')"
                    class="grid grid-cols-2 gap-1.5 rounded-xl border border-border bg-muted/50 p-1"
                >
                    <button
                        type="button"
                        role="tab"
                        data-test="follow-tab-followers"
                        :aria-selected="activeTab === 'followers'"
                        :class="[
                            'flex min-h-8.5 items-center justify-center gap-1.5 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                            activeTab === 'followers'
                                ? 'bg-card font-semibold text-foreground shadow-2xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="switchTab('followers')"
                    >
                        <span>{{ t('socialList.followersTitle') }}</span>
                        <span class="text-[11px] tabular-nums opacity-75">
                            {{
                                formatCompactNumber(liveFollowersCount, locale)
                            }}
                        </span>
                    </button>

                    <button
                        type="button"
                        role="tab"
                        data-test="follow-tab-following"
                        :aria-selected="activeTab === 'following'"
                        :class="[
                            'flex min-h-8.5 items-center justify-center gap-1.5 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                            activeTab === 'following'
                                ? 'bg-card font-semibold text-foreground shadow-2xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="switchTab('following')"
                    >
                        <span>{{ t('socialList.followingTitle') }}</span>
                        <span class="text-[11px] tabular-nums opacity-75">
                            {{
                                formatCompactNumber(liveFollowingCount, locale)
                            }}
                        </span>
                    </button>
                </div>

                <div v-if="showSearch" class="relative">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="searchQuery"
                        type="search"
                        data-test="follow-search-input"
                        :placeholder="searchPlaceholder"
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

            <!-- Scrollable List Body -->
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
                                    class="h-3 w-48 animate-pulse rounded-md bg-muted/70"
                                />
                            </div>
                        </div>
                        <div
                            class="h-8 w-20 shrink-0 animate-pulse rounded-lg bg-muted"
                        />
                    </div>
                </div>

                <!-- Restricted / Blocked State -->
                <div
                    v-else-if="restricted"
                    class="flex flex-col items-center justify-center gap-2.5 px-4 py-12 text-center"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground"
                    >
                        <Lock class="size-5" aria-hidden="true" />
                    </div>
                    <p class="text-sm font-semibold text-foreground">
                        {{ t('socialList.restrictedTitle') }}
                    </p>
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{ t('socialList.restrictedHint') }}
                    </p>
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
                        @click="fetchList(1, false)"
                    >
                        <RefreshCw class="size-3.5" aria-hidden="true" />
                        <span>{{ t('socialList.retry') }}</span>
                    </Button>
                </div>

                <!-- Empty State (Rich, Actionable, Non-blank) -->
                <div
                    v-else-if="items.length === 0"
                    data-test="follow-empty-state"
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
                        <Users
                            v-else-if="activeTab === 'followers'"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <UserPlus v-else class="size-5" aria-hidden="true" />
                    </div>
                    <p class="text-sm font-semibold text-foreground">
                        {{
                            searchQuery.trim()
                                ? t('socialList.noSearchResults')
                                : activeTab === 'followers'
                                  ? t('socialList.noFollowersYet')
                                  : t('socialList.noFollowingYet')
                        }}
                    </p>
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{
                            searchQuery.trim()
                                ? t('socialList.noSearchResultsHint')
                                : activeTab === 'followers'
                                  ? t('socialList.noFollowersYetHint')
                                  : t('socialList.noFollowingYetHint')
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
                    <Button
                        v-else
                        size="sm"
                        variant="outline"
                        class="mt-1 gap-1.5 rounded-lg text-xs"
                        as-child
                    >
                        <Link
                            :href="CommunityController.index.url()"
                            @click="emit('update:open', false)"
                        >
                            <Compass class="size-3.5" aria-hidden="true" />
                            <span>{{
                                t('socialList.exploreCommunities')
                            }}</span>
                        </Link>
                    </Button>
                </div>

                <!-- User List -->
                <div v-else class="divide-y divide-border/50">
                    <SocialUserListItem
                        v-for="member in items"
                        :key="member.id"
                        :item="member"
                        @navigate="emit('update:open', false)"
                    />
                </div>

                <!-- Load More -->
                <div
                    v-if="!loading && hasMore"
                    class="flex justify-center pt-3 pb-1"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="follow-load-more"
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
