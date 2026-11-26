<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BookOpen,
    Globe,
    Hash,
    Lock,
    Newspaper,
    Pin,
    Settings,
    Share2,
    ShieldCheck,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import EmptyState from '@/components/common/EmptyState.vue';
import BannerSlot from '@/components/monetization/BannerSlot.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import PostCard from '@/components/social/PostCard.vue';
import PostComposer from '@/components/social/PostComposer.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { login } from '@/routes';
import type {
    ActiveBannerDisplay,
    CategoryOption,
    CommunityDetail,
    CommunityMemberItem,
    HashtagItem,
    PublicFeedPost,
} from '@/types';

const props = defineProps<{
    tab: 'posts' | 'topics' | 'members' | 'rules';
    activeCategory: string;
    activeSort: 'latest' | 'trending';
    categories: CategoryOption[];
    community: CommunityDetail;
    posts: PublicFeedPost[];
    members: CommunityMemberItem[];
    leaderboard: CommunityMemberItem[];
    trending_hashtags: HashtagItem[];
    banners?: Record<string, ActiveBannerDisplay | null>;
}>();

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const editOpen = ref(false);
const rulesInput = ref(props.community.rules.join('\n'));

const editForm = useForm({
    name: props.community.name,
    category: props.community.category,
    description: props.community.description ?? '',
});

function navigateCommunity(
    params: Partial<{ tab: string; category: string; sort: string }>,
): void {
    router.get(
        CommunityController.show.url(props.community.slug, {
            query: {
                tab: params.tab ?? props.tab,
                category: params.category ?? props.activeCategory,
                sort: params.sort ?? props.activeSort,
            },
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function toggleJoin(): void {
    if (!user.value) {
        toast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    router.post(
        CommunityController.toggleMembership.url(props.community.slug),
        {},
        { preserveScroll: true },
    );
}

async function shareCommunity(): Promise<void> {
    const url = `${window.location.origin}${CommunityController.show.url(props.community.slug)}`;

    try {
        await navigator.clipboard.writeText(url);
    } catch {
        // Ignore clipboard errors gracefully
    }

    toast.success(t('post.shareCopied'));
}

function submitEdit(): void {
    editForm
        .transform((data) => ({
            ...data,
            rules: rulesInput.value
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean)
                .slice(0, 10),
        }))
        .patch(CommunityController.update.url(props.community.slug), {
            preserveScroll: true,
            onSuccess: () => {
                editOpen.value = false;
            },
        });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="community.name" />

        <!-- Community Cover & Header Card (Mockup 2) -->
        <section
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div class="h-36 w-full border-b border-border bg-muted/70 sm:h-44">
                <img
                    v-if="community.cover"
                    :src="community.cover"
                    :alt="community.name"
                    class="size-full object-cover"
                />
            </div>

            <div class="px-5 pb-4 sm:px-6">
                <div
                    class="-mt-10 flex flex-wrap items-end justify-between gap-4"
                >
                    <div class="flex items-end gap-3.5">
                        <Avatar
                            class="size-20 rounded-2xl border-4 border-card shadow-2xs"
                        >
                            <AvatarImage
                                v-if="community.avatar"
                                :src="community.avatar"
                                :alt="community.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-lg font-bold text-accent-foreground"
                            >
                                {{ getInitials(community.name) }}
                            </AvatarFallback>
                        </Avatar>

                        <div class="pb-1">
                            <div class="flex items-center gap-1.5">
                                <h1 class="text-xl font-bold text-foreground">
                                    {{ community.name }}
                                </h1>
                                <BadgeCheck
                                    v-if="community.is_verified"
                                    class="size-5 text-primary"
                                />
                            </div>
                            <p
                                class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                            >
                                <span class="inline-flex items-center gap-1">
                                    <Globe
                                        v-if="community.visibility === 'public'"
                                        class="size-3.5"
                                    />
                                    <Lock v-else class="size-3.5" />
                                    {{ community.category_label }}
                                </span>
                                <span>·</span>
                                <span>
                                    {{
                                        t('communities.membersCount', {
                                            count: community.members_count,
                                        })
                                    }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="!community.is_owner"
                            type="button"
                            size="sm"
                            :variant="
                                community.is_joined ? 'outline' : 'default'
                            "
                            class="rounded-lg"
                            @click="toggleJoin"
                        >
                            {{
                                community.is_joined
                                    ? t('communities.leave')
                                    : t('communities.join')
                            }}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-9 rounded-lg"
                            :aria-label="t('communities.extra.shareCommunity')"
                            @click="shareCommunity"
                        >
                            <Share2 class="size-4" />
                        </Button>

                        <Dialog
                            v-if="community.is_owner"
                            v-model:open="editOpen"
                        >
                            <DialogTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="rounded-lg"
                                >
                                    <Settings class="size-4" />
                                    <span>{{
                                        t('communities.editCommunity')
                                    }}</span>
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>{{
                                        t('communities.editCommunity')
                                    }}</DialogTitle>
                                </DialogHeader>
                                <form
                                    @submit.prevent="submitEdit"
                                    class="space-y-4"
                                >
                                    <div class="grid gap-1.5">
                                        <Label for="edit-comm-name">{{
                                            t('communities.name')
                                        }}</Label>
                                        <Input
                                            id="edit-comm-name"
                                            v-model="editForm.name"
                                            required
                                        />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label for="edit-comm-desc">{{
                                            t('communities.description')
                                        }}</Label>
                                        <textarea
                                            id="edit-comm-desc"
                                            v-model="editForm.description"
                                            rows="3"
                                            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label for="edit-comm-rules">{{
                                            t('communities.rulesInput')
                                        }}</Label>
                                        <textarea
                                            id="edit-comm-rules"
                                            v-model="rulesInput"
                                            rows="4"
                                            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <DialogFooter class="gap-2">
                                        <DialogClose as-child>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                            >
                                                {{ t('post.cancel') }}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            :disabled="editForm.processing"
                                        >
                                            <Spinner
                                                v-if="editForm.processing"
                                            />
                                            <span>{{
                                                t('post.saveChanges')
                                            }}</span>
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <p
                    v-if="community.description"
                    class="mt-3 text-sm leading-relaxed text-muted-foreground"
                >
                    {{ community.description }}
                </p>

                <!-- 4 Tabs (Mockup 2) -->
                <div
                    class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-border pt-3"
                >
                    <Button
                        type="button"
                        size="sm"
                        :variant="tab === 'posts' ? 'secondary' : 'ghost'"
                        class="rounded-lg text-xs"
                        @click="navigateCommunity({ tab: 'posts' })"
                    >
                        <Newspaper class="size-4" />
                        <span>{{ t('communities.tabs.posts') }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="tab === 'topics' ? 'secondary' : 'ghost'"
                        class="rounded-lg text-xs"
                        @click="navigateCommunity({ tab: 'topics' })"
                    >
                        <Hash class="size-4" />
                        <span>{{ t('communities.tabs.topics') }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="tab === 'members' ? 'secondary' : 'ghost'"
                        class="rounded-lg text-xs"
                        @click="navigateCommunity({ tab: 'members' })"
                    >
                        <Users class="size-4" />
                        <span>{{ t('communities.tabs.members') }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="tab === 'rules' ? 'secondary' : 'ghost'"
                        class="rounded-lg text-xs"
                        @click="navigateCommunity({ tab: 'rules' })"
                    >
                        <BookOpen class="size-4" />
                        <span>{{ t('communities.tabs.rules') }}</span>
                    </Button>
                </div>
            </div>
        </section>

        <BannerSlot
            :banner="banners?.desktop_community_top"
            class="hidden sm:block"
        />
        <BannerSlot :banner="banners?.mobile_community" class="sm:hidden" />

        <!-- Tab: Posts -->
        <template v-if="tab === 'posts'">
            <PostComposer
                v-if="community.is_joined"
                :categories="categories"
                :default-category="community.category"
                :community-id="community.id"
            />

            <!-- Sub-topic pills + Sort -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        :class="[
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                            activeCategory === 'all'
                                ? 'bg-primary-soft font-semibold text-accent-foreground'
                                : 'border border-border bg-card text-muted-foreground hover:bg-surface-hover',
                        ]"
                        @click="navigateCommunity({ category: 'all' })"
                    >
                        {{ t('communities.filterAllTopics') }}
                    </button>
                    <button
                        v-for="cat in categories"
                        :key="cat.value"
                        type="button"
                        :class="[
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                            activeCategory === cat.value
                                ? 'bg-primary-soft font-semibold text-accent-foreground'
                                : 'border border-border bg-card text-muted-foreground hover:bg-surface-hover',
                        ]"
                        @click="navigateCommunity({ category: cat.value })"
                    >
                        {{ cat.label }}
                    </button>
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            activeSort === 'latest' ? 'secondary' : 'outline'
                        "
                        class="h-8 rounded-lg text-xs"
                        @click="navigateCommunity({ sort: 'latest' })"
                    >
                        {{ t('communities.sortLatest') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            activeSort === 'trending' ? 'secondary' : 'outline'
                        "
                        class="h-8 rounded-lg text-xs"
                        @click="navigateCommunity({ sort: 'trending' })"
                    >
                        {{ t('communities.sortTrending') }}
                    </Button>
                </div>
            </div>

            <section v-if="posts.length > 0" class="space-y-4">
                <div v-for="post in posts" :key="post.id" class="space-y-1.5">
                    <div
                        v-if="post.is_pinned"
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-[11px] font-semibold text-accent-foreground"
                    >
                        <Pin class="size-3" />
                        <span>{{ t('communities.pinnedBadge') }}</span>
                    </div>
                    <PostCard
                        :post="post"
                        :categories="categories"
                        :can-pin="community.can_moderate"
                        :community-slug="community.slug"
                    />
                </div>
            </section>

            <EmptyState
                v-else
                :icon="Newspaper"
                :title="community.name"
                :description="t('home.empty.description')"
            />
        </template>

        <!-- Tab: Topics / Hashtags -->
        <section
            v-else-if="tab === 'topics'"
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2 class="text-base font-semibold text-foreground">
                {{ t('communities.trendingHashtagsTitle') }}
            </h2>
            <div
                v-if="trending_hashtags.length > 0"
                class="flex flex-wrap gap-2"
            >
                <Badge
                    v-for="tag in trending_hashtags"
                    :key="tag.id"
                    variant="secondary"
                    class="gap-1.5 rounded-lg px-3 py-1.5 text-xs"
                >
                    <span>#{{ tag.name }}</span>
                    <span class="text-muted-foreground"
                        >({{ tag.posts_count }})</span
                    >
                </Badge>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('communities.noHashtags') }}
            </p>
        </section>

        <!-- Tab: Members & Leaderboard -->
        <section
            v-else-if="tab === 'members'"
            class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2
                class="flex items-center gap-2 text-base font-semibold text-foreground"
            >
                <Trophy class="size-4 text-primary" />
                <span>{{ t('communities.tabs.members') }}</span>
            </h2>
            <div class="divide-y divide-border">
                <div
                    v-for="member in members"
                    :key="member.id"
                    class="flex items-center justify-between gap-3 py-3"
                >
                    <Link
                        v-if="member.user"
                        :href="
                            ProfileViewController.show.url(member.user.username)
                        "
                        class="flex items-center gap-3"
                    >
                        <Avatar class="size-9 rounded-full">
                            <AvatarImage
                                v-if="member.user.avatar"
                                :src="member.user.avatar"
                                :alt="member.user.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                            >
                                {{ getInitials(member.user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <p
                                class="flex items-center gap-1 text-sm font-semibold text-foreground"
                            >
                                <span>{{ member.user.name }}</span>
                                <PremiumBadge
                                    v-if="member.user.is_premium"
                                    size="sm"
                                />
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ member.role_label }}
                            </p>
                        </div>
                    </Link>
                    <Badge variant="secondary" class="text-xs">
                        {{ t('communities.points', { count: member.points }) }}
                    </Badge>
                </div>
            </div>
        </section>

        <!-- Tab: Rules -->
        <section
            v-else
            class="space-y-3 rounded-xl border border-border bg-card p-5"
        >
            <h2
                class="flex items-center gap-2 text-base font-semibold text-foreground"
            >
                <ShieldCheck class="size-4 text-primary" />
                <span>{{ t('communities.rulesTitle') }}</span>
            </h2>
            <ol
                v-if="community.rules.length > 0"
                class="space-y-2.5 text-sm text-foreground"
            >
                <li
                    v-for="(rule, idx) in community.rules"
                    :key="idx"
                    class="flex items-start gap-2.5 rounded-lg border border-border/70 bg-muted/30 p-3"
                >
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[11px] font-bold text-accent-foreground"
                    >
                        {{ idx + 1 }}
                    </span>
                    <span>{{ rule }}</span>
                </li>
            </ol>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('communities.noRules') }}
            </p>
        </section>
    </div>
</template>
