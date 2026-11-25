<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, MessageSquareText } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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

watch(
    () => [props.posts, props.nextCursor, props.hasMore],
    ([newPosts, newCursor, newHasMore]) => {
        organicItems.value = [...(newPosts as PublicFeedPost[])];
        cursor.value = newCursor as string | null;
        moreAvailable.value = Boolean(newHasMore);
        loadError.value = false;
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
    router.get(
        HomeController.index.url({ query: { tab: tabKey } }),
        {},
        {
            preserveState: true,
            preserveScroll: false,
        },
    );
}

async function loadMorePosts(): Promise<void> {
    if (!cursor.value || loadingMore.value) {
        return;
    }

    loadingMore.value = true;
    loadError.value = false;

    try {
        const response = await fetch(
            HomeController.more.url({
                query: {
                    tab: props.activeTab,
                    cursor: cursor.value,
                },
            }),
            {
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

        organicItems.value = [...organicItems.value, ...payload.data];
        cursor.value = payload.next_cursor;
        moreAvailable.value = payload.has_more;
    } catch {
        loadError.value = true;
    } finally {
        loadingMore.value = false;
    }
}
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

        <!-- News Feed Category Tabs -->
        <div
            role="tablist"
            :aria-label="t('home.feed.categoriesLabel')"
            class="flex flex-wrap items-center gap-2"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.key"
                :class="[
                    'rounded-full px-3.5 py-1.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
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
        <section v-if="feedItems.length > 0" class="space-y-4">
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

            <div
                v-if="moreAvailable && !loadingMore"
                class="flex justify-center pt-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-lg"
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
