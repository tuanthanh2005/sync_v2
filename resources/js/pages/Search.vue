<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BadgeCheck, Hash, Search as SearchIcon, Users } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import SearchController from '@/actions/App/Http/Controllers/Social/SearchController';
import EmptyState from '@/components/common/EmptyState.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import PostCard from '@/components/social/PostCard.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import type {
    ActiveBannerDisplay,
    CommunitySummary,
    HashtagItem,
    PublicFeedPost,
    SearchUserResult,
} from '@/types';

const props = defineProps<{
    query: string;
    hashtag: string;
    posts: PublicFeedPost[];
    users: SearchUserResult[];
    banners?: Record<string, ActiveBannerDisplay | null>;
    communities: Array<
        Pick<
            CommunitySummary,
            | 'id'
            | 'name'
            | 'slug'
            | 'category_label'
            | 'avatar'
            | 'is_verified'
            | 'members_count'
        >
    >;
    hashtags: HashtagItem[];
}>();

const { t } = useI18n();
const { getInitials } = useInitials();
const keyword = ref(props.query);

watch(
    () => props.query,
    (val) => {
        keyword.value = val;
    },
);

let searchTimer: ReturnType<typeof setTimeout> | null = null;

function handleDebouncedInput(): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    const q = keyword.value.trim();
    if (q.length > 0 && q.length < 2) {
        return;
    }

    searchTimer = setTimeout(() => {
        submitSearch();
    }, 350);
}

function submitSearch(): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    router.get(
        SearchController.url({ query: { q: keyword.value.trim() } }),
        {},
        { preserveState: true, replace: true },
    );
}

onBeforeUnmount(() => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('search.title')" />

        <form
            @submit.prevent="submitSearch"
            class="flex items-center gap-2 rounded-xl border border-border bg-card p-3 shadow-xs"
        >
            <SearchIcon class="ml-1 size-4 shrink-0 text-muted-foreground" />
            <Input
                v-model="keyword"
                type="search"
                inputmode="search"
                enterkeyhint="search"
                :placeholder="t('search.placeholder')"
                class="min-w-0 flex-1 border-0 bg-transparent shadow-none focus-visible:ring-0"
                @input="handleDebouncedInput"
            />
            <Button
                type="submit"
                size="sm"
                class="min-h-10 shrink-0 rounded-lg sm:min-h-9"
            >
                {{ t('search.button') }}
            </Button>
        </form>

        <BannerSlot
            :banner="banners?.desktop_explore_top"
            class="hidden sm:block"
        />
        <BannerSlot :banner="banners?.mobile_explore" class="sm:hidden" />

        <div
            v-if="hashtag"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/20 bg-primary-soft px-4 py-2.5 text-xs"
        >
            <div class="flex items-center gap-2 font-medium text-foreground">
                <Hash class="size-4 text-primary" />
                <span>{{ t('search.filteringByHashtag') }}:</span>
                <Badge variant="soft" class="font-semibold">
                    #{{ hashtag }}
                </Badge>
            </div>
            <Link
                :href="
                    SearchController.url({
                        query: keyword ? { q: keyword } : undefined,
                    })
                "
                class="font-medium text-primary hover:underline"
            >
                {{ t('search.clearFilter') }}
            </Link>
        </div>

        <section
            v-if="hashtags.length > 0"
            class="space-y-2.5 rounded-xl border border-border bg-card p-5"
        >
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('search.hashtagsSection') }}
            </h2>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="tag in hashtags"
                    :key="tag.id"
                    :href="
                        SearchController.url({ query: { hashtag: tag.slug } })
                    "
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                >
                    <span>#{{ tag.name }}</span>
                    <span class="text-muted-foreground"
                        >({{ tag.posts_count }})</span
                    >
                </Link>
            </div>
        </section>

        <section
            v-if="communities.length > 0"
            class="space-y-3 rounded-xl border border-border bg-card p-5"
        >
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Users class="size-4 text-primary" />
                <span>{{ t('search.communitiesSection') }}</span>
            </h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="community in communities"
                    :key="community.id"
                    :href="CommunityController.show.url(community.slug)"
                    class="flex items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40"
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

        <section
            v-if="users.length > 0"
            class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('search.usersSection') }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="person in users"
                    :key="person.id"
                    :href="ProfileViewController.show.url(person.username)"
                    class="flex items-center gap-3 rounded-xl border border-border/80 p-3 transition-colors hover:border-primary/40"
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
                            <PremiumBadge v-if="person.is_premium" size="sm" />
                            <BadgeCheck
                                v-if="person.is_verified"
                                class="size-3.5 shrink-0 text-primary"
                            />
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            @{{ person.username }}
                        </p>
                    </div>
                </Link>
            </div>
        </section>

        <section v-if="posts.length > 0" class="space-y-4">
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('search.postsSection') }}
            </h2>
            <PostCard v-for="post in posts" :key="post.id" :post="post" />
        </section>

        <EmptyState
            v-if="
                users.length === 0 &&
                posts.length === 0 &&
                communities.length === 0 &&
                hashtags.length === 0
            "
            :icon="SearchIcon"
            :title="t('search.emptyTitle')"
            :description="t('search.emptyDescription')"
        />
    </div>
</template>
