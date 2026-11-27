<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    Bookmark,
    Check,
    Copy,
    ExternalLink,
    Eye,
    Flag,
    Lock,
    MessageCircle,
    MoreHorizontal,
    Pencil,
    Pin,
    PinOff,
    Share2,
    Trash2,
    Users,
    Zap,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ReportController from '@/actions/App/Http/Controllers/Moderation/ReportController';
import PostController from '@/actions/App/Http/Controllers/Social/PostController';
import PostInteractionController from '@/actions/App/Http/Controllers/Social/PostInteractionController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import ImageLightbox from '@/components/common/ImageLightbox.vue';
import BoostPostModal from '@/components/monetization/BoostPostModal.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import AiBadge from '@/components/ai/AiBadge.vue';
import MemberBadges from '@/components/growth/MemberBadges.vue';
import InputError from '@/components/InputError.vue';
import ReactionPicker from '@/components/social/ReactionPicker.vue';
import {
    usePostViewTracker,
    type PostViewSource,
} from '@/composables/usePostViewTracker';
import {
    formatCompactNumber,
    formatDateTime,
    formatNumber,
} from '@/lib/formatters';
import { appToast } from '@/lib/toast';
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
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import { login } from '@/routes';
import type { CategoryOption, PublicFeedPost } from '@/types';

const props = withDefaults(
    defineProps<{
        post: PublicFeedPost;
        truncate?: boolean;
        categories?: CategoryOption[];
        canPin?: boolean;
        communitySlug?: string | null;
        viewSource?: PostViewSource;
    }>(),
    {
        truncate: true,
        categories: () => [],
        canPin: false,
        communitySlug: null,
        viewSource: 'feed',
    },
);

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const articleRef = ref<HTMLElement | null>(null);
const isContentExpanded = ref(false);
const isEditing = ref(false);
const reportOpen = ref(false);
const boostModalOpen = ref(false);
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

usePostViewTracker(articleRef, {
    postId: props.post.id,
    source: props.viewSource,
    isPromoted: Boolean(props.post.is_sponsored),
});

// Optimistic local interaction state with rollback on network/server failure
const optimisticReaction = ref(props.post.viewer_reaction);
const optimisticReactionsCount = ref(props.post.reactions_count);
const optimisticBookmarked = ref(props.post.is_bookmarked);
const reacting = ref(false);
const bookmarking = ref(false);

watch(
    () => [
        props.post.viewer_reaction,
        props.post.reactions_count,
        props.post.is_bookmarked,
    ],
    ([reaction, count, bookmarked]) => {
        optimisticReaction.value = reaction as typeof optimisticReaction.value;
        optimisticReactionsCount.value = count as number;
        optimisticBookmarked.value = Boolean(bookmarked);
    },
);

function openImageViewer(index: number): void {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
}

async function copyPostContent(): Promise<void> {
    const text = [props.post.title, props.post.content]
        .filter(Boolean)
        .join('\n\n');

    try {
        await navigator.clipboard.writeText(text);
        appToast.success(t('post.copiedText'));
    } catch {
        appToast.error(t('common.copyFailed'));
    }
}

const editForm = useForm({
    title: props.post.title ?? '',
    content: props.post.content,
    category: props.post.category,
});

const reportForm = useForm({
    target_type: 'post',
    target_id: props.post.id,
    reason: 'spam',
    details: '',
});

const shouldTruncateContent = computed(
    () =>
        props.truncate &&
        props.post.content.length > 280 &&
        !isContentExpanded.value,
);

const displayedContent = computed(() =>
    shouldTruncateContent.value
        ? `${props.post.content.slice(0, 280).trimEnd()}...`
        : props.post.content,
);

const profileHref = computed(() =>
    props.post.author
        ? ProfileViewController.show.url(props.post.author.username)
        : '#',
);

function requireUser(): boolean {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return false;
    }

    return true;
}

function submitReaction(type: 'like' | 'love' | 'insightful'): void {
    if (!requireUser() || reacting.value) {
        return;
    }

    const previousReaction = optimisticReaction.value;
    const previousCount = optimisticReactionsCount.value;

    if (previousReaction === type) {
        optimisticReaction.value = null;
        optimisticReactionsCount.value = Math.max(0, previousCount - 1);
    } else if (previousReaction === null) {
        optimisticReaction.value = type;
        optimisticReactionsCount.value = previousCount + 1;
    } else {
        optimisticReaction.value = type;
    }

    reacting.value = true;

    router.post(
        PostInteractionController.react.url(props.post.id),
        { type },
        {
            preserveScroll: true,
            onError: () => {
                optimisticReaction.value = previousReaction;
                optimisticReactionsCount.value = previousCount;
            },
            onFinish: () => {
                reacting.value = false;
            },
        },
    );
}

function submitBookmark(): void {
    if (!requireUser() || bookmarking.value) {
        return;
    }

    const previousBookmarked = optimisticBookmarked.value;
    optimisticBookmarked.value = !previousBookmarked;
    bookmarking.value = true;

    router.post(
        PostInteractionController.bookmark.url(props.post.id),
        {},
        {
            preserveScroll: true,
            onError: () => {
                optimisticBookmarked.value = previousBookmarked;
            },
            onFinish: () => {
                bookmarking.value = false;
            },
        },
    );
}

function submitPollVote(optionId: number): void {
    if (!requireUser()) {
        return;
    }

    router.post(
        PostInteractionController.votePoll.url(props.post.id),
        { option_id: optionId },
        { preserveScroll: true },
    );
}

async function handleShare(): Promise<void> {
    const absoluteUrl = `${window.location.origin}${PostController.show.url(props.post.id)}`;

    try {
        await navigator.clipboard.writeText(absoluteUrl);
    } catch {
        // Fallback silently if clipboard access is unavailable
    }

    appToast.success(t('post.shareCopied'));

    if (user.value) {
        router.post(
            PostInteractionController.share.url(props.post.id),
            {},
            { preserveScroll: true },
        );
    }
}

function saveEdit(): void {
    editForm.patch(PostController.update.url(props.post.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
        },
    });
}

const confirmDeleteOpen = ref(false);
const deletingPost = ref(false);

function deletePost(): void {
    confirmDeleteOpen.value = true;
}

function executeDeletePost(): void {
    deletingPost.value = true;
    router.delete(PostController.destroy.url(props.post.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingPost.value = false;
            confirmDeleteOpen.value = false;
        },
    });
}

function togglePin(): void {
    const slug = props.communitySlug || props.post.community?.slug;

    if (!slug) {
        return;
    }

    router.post(
        CommunityController.togglePin.url([slug, props.post.id]),
        {},
        { preserveScroll: true },
    );
}

function openReportDialog(): void {
    if (!requireUser()) {
        return;
    }

    reportForm.target_id = props.post.id;
    reportOpen.value = true;
}

function submitReport(): void {
    reportForm.post(ReportController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            reportOpen.value = false;
            reportForm.reset('details');
        },
    });
}
</script>

<template>
    <article
        ref="articleRef"
        class="rounded-xl border border-border bg-card p-3.5 shadow-xs transition-colors sm:p-5"
    >
        <!-- Header -->
        <header class="flex items-start justify-between gap-2.5 sm:gap-3">
            <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
                <Link :href="profileHref" class="shrink-0">
                    <Avatar class="size-10 shrink-0 rounded-full">
                        <AvatarImage
                            v-if="post.author?.avatar"
                            :src="post.author.avatar"
                            :alt="post.author.name"
                        />
                        <AvatarFallback
                            class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                        >
                            {{ getInitials(post.author?.name ?? 'User') }}
                        </AvatarFallback>
                    </Avatar>
                </Link>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Link
                            :href="profileHref"
                            class="max-w-full truncate text-sm font-semibold text-foreground hover:text-primary"
                        >
                            {{ post.author?.name ?? 'Member' }}
                        </Link>
                        <PremiumBadge
                            v-if="
                                post.author?.is_premium &&
                                !post.author?.is_ai_agent
                            "
                            size="sm"
                        />
                        <AiBadge
                            v-if="
                                post.author?.is_ai_agent ||
                                post.content_origin === 'ai_generated'
                            "
                            :disclosure="post.author?.ai_disclosure"
                            size="sm"
                        />
                        <MemberBadges
                            v-if="post.author?.badges?.length"
                            :badges="post.author.badges"
                            size="sm"
                            :max="1"
                        />
                        <BadgeCheck
                            v-if="post.author?.is_verified"
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <Badge
                            v-if="post.is_sponsored"
                            variant="soft"
                            class="h-5 gap-1 rounded-md px-2 text-[10px] font-semibold"
                        >
                            <Zap class="size-2.5" />
                            {{ t('post.sponsoredBadge') }}
                        </Badge>
                        <Badge
                            v-else
                            variant="secondary"
                            class="h-5 rounded-md px-2 text-[10px] font-medium"
                        >
                            {{ post.category_label }}
                        </Badge>
                        <Link
                            v-if="post.community"
                            :href="
                                CommunityController.show.url(
                                    post.community.slug,
                                )
                            "
                        >
                            <Badge
                                variant="soft"
                                class="h-5 max-w-[160px] gap-1 truncate rounded-md border border-primary/20 px-2 text-[10px] font-medium transition-colors hover:bg-primary/15"
                            >
                                <Users class="size-2.5 shrink-0" />
                                <span class="truncate">{{
                                    post.community.name
                                }}</span>
                            </Badge>
                        </Link>
                        <Badge
                            v-if="post.visibility === 'private'"
                            variant="outline"
                            class="h-5 gap-1 rounded-md px-1.5 text-[10px]"
                        >
                            <Lock class="size-2.5" />
                            {{ t('post.privateBadge') }}
                        </Badge>
                    </div>
                    <p class="truncate text-xs text-muted-foreground">
                        <span
                            v-if="post.is_sponsored"
                            class="font-medium text-primary"
                        >
                            {{ t('post.sponsoredBadge') }} ·
                        </span>
                        <span v-else-if="post.author?.headline">
                            {{ post.author.headline }} ·
                        </span>
                        <span v-else-if="post.author?.username">
                            @{{ post.author.username }} ·
                        </span>
                        <Link
                            :href="PostController.show.url(post.id)"
                            class="hover:underline"
                        >
                            {{
                                post.published_at
                                    ? formatDateTime(post.published_at, locale)
                                    : ''
                            }}
                        </Link>
                    </p>
                </div>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-10 shrink-0 rounded-full text-muted-foreground sm:size-8 sm:rounded-lg"
                        :aria-label="t('accessibility.postOptions')"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52 rounded-xl">
                    <DropdownMenuItem
                        class="min-h-10 cursor-pointer"
                        @click="copyPostContent"
                    >
                        <Copy class="mr-2 size-4" />
                        <span>{{ t('post.copyText') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canPin && (communitySlug || post.community?.slug)"
                        class="min-h-10 cursor-pointer"
                        @click="togglePin"
                    >
                        <PinOff v-if="post.is_pinned" class="mr-2 size-4" />
                        <Pin v-else class="mr-2 size-4" />
                        <span>{{
                            post.is_pinned ? t('post.unpin') : t('post.pin')
                        }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="post.can_edit"
                        class="min-h-10 cursor-pointer"
                        @click="isEditing = !isEditing"
                    >
                        <Pencil class="mr-2 size-4" />
                        <span>{{ t('post.edit') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="post.can_boost && !post.boost_summary"
                        class="min-h-10 cursor-pointer text-primary focus:text-primary"
                        @click="boostModalOpen = true"
                    >
                        <Zap class="mr-2 size-4" />
                        <span>{{ t('monetization.boost.ctaButton') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="user && user.id !== post.author?.id"
                        class="min-h-10 cursor-pointer"
                        @click="openReportDialog"
                    >
                        <Flag class="mr-2 size-4" />
                        <span>{{ t('report.action') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="post.can_delete"
                        class="min-h-10 cursor-pointer text-destructive focus:text-destructive"
                        @click="deletePost"
                    >
                        <Trash2 class="mr-2 size-4" />
                        <span>{{ t('post.delete') }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>

        <!-- Edit form or Content -->
        <div v-if="isEditing" class="mt-4 space-y-3">
            <Input
                v-model="editForm.title"
                type="text"
                maxlength="180"
                :placeholder="t('home.composer.titlePlaceholder')"
            />
            <textarea
                v-model="editForm.content"
                rows="4"
                required
                class="w-full rounded-xl border border-input bg-muted/30 px-3 py-2 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <select
                    v-if="categories.length > 0"
                    v-model="editForm.category"
                    class="h-8 rounded-lg border border-input bg-card px-2.5 text-xs"
                >
                    <option
                        v-for="cat in categories"
                        :key="cat.value"
                        :value="cat.value"
                    >
                        {{ cat.label }}
                    </option>
                </select>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="rounded-lg text-xs"
                        @click="isEditing = false"
                    >
                        {{ t('post.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        class="rounded-lg text-xs"
                        :disabled="editForm.processing"
                        @click="saveEdit"
                    >
                        {{ t('post.saveChanges') }}
                    </Button>
                </div>
            </div>
        </div>

        <div v-else class="mt-3 space-y-3">
            <h2
                v-if="post.title"
                class="text-base leading-snug font-bold [overflow-wrap:anywhere] break-words text-foreground sm:text-lg"
            >
                <Link
                    :href="PostController.show.url(post.id)"
                    class="hover:text-primary"
                >
                    {{ post.title }}
                </Link>
            </h2>

            <div
                class="text-[15px] leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-line text-foreground/90 sm:text-sm"
            >
                {{ displayedContent }}
                <button
                    v-if="truncate && post.content.length > 280"
                    type="button"
                    class="ml-1.5 inline-flex min-h-8 items-center font-medium text-primary hover:underline"
                    @click="isContentExpanded = !isContentExpanded"
                >
                    {{
                        isContentExpanded
                            ? t('post.seeLess')
                            : t('post.seeMore')
                    }}
                </button>
            </div>

            <!-- Hashtags -->
            <div
                v-if="post.hashtags && post.hashtags.length > 0"
                class="no-scrollbar flex flex-nowrap gap-1.5 overflow-x-auto sm:flex-wrap"
            >
                <Link
                    v-for="tag in post.hashtags"
                    :key="tag.id"
                    :href="`/topics/${tag.slug}`"
                    class="shrink-0 rounded-md bg-primary-soft px-2.5 py-1 text-xs font-medium text-accent-foreground transition-colors hover:bg-primary/15"
                >
                    #{{ tag.name }}
                </Link>
            </div>

            <!-- Responsive Images (Swipe Snap Gallery on Mobile + Fullscreen Lightbox) -->
            <div
                v-if="post.media.length === 1"
                class="overflow-hidden rounded-xl border border-border bg-muted/40"
            >
                <button
                    type="button"
                    class="block w-full cursor-zoom-in focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="openImageViewer(0)"
                >
                    <img
                        :src="post.media[0].url"
                        :alt="
                            post.media[0].alt_text || post.title || 'Post image'
                        "
                        loading="lazy"
                        decoding="async"
                        class="max-h-[420px] w-full object-cover"
                    />
                </button>
            </div>

            <div
                v-else-if="post.media.length > 1"
                class="no-scrollbar flex snap-x snap-mandatory gap-2 overflow-x-auto sm:grid sm:grid-cols-2 sm:overflow-visible"
            >
                <button
                    v-for="(media, idx) in post.media"
                    :key="media.id"
                    type="button"
                    class="relative aspect-[4/3] w-[82%] shrink-0 cursor-zoom-in snap-center overflow-hidden rounded-xl border border-border bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-full"
                    @click="openImageViewer(idx)"
                >
                    <img
                        :src="media.url"
                        :alt="media.alt_text || post.title || 'Post image'"
                        loading="lazy"
                        decoding="async"
                        class="size-full object-cover"
                    />
                    <span
                        class="absolute right-2 bottom-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white sm:hidden"
                    >
                        {{ idx + 1 }}/{{ post.media.length }}
                    </span>
                </button>
            </div>

            <!-- Link Preview -->
            <a
                v-if="post.link_url"
                :href="post.link_url"
                target="_blank"
                rel="ugc nofollow noopener noreferrer"
                class="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-3.5 transition-colors hover:border-primary/40"
            >
                <img
                    v-if="post.link_meta?.image"
                    :src="post.link_meta.image"
                    alt=""
                    loading="lazy"
                    class="size-16 shrink-0 rounded-lg object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p
                        class="flex items-center gap-1 text-[11px] font-medium text-primary"
                    >
                        <span>{{
                            post.link_meta?.domain || post.link_url
                        }}</span>
                        <ExternalLink class="size-3" />
                    </p>
                    <p
                        class="mt-0.5 truncate text-sm font-semibold text-foreground"
                    >
                        {{ post.link_meta?.title || post.link_url }}
                    </p>
                    <p
                        v-if="post.link_meta?.description"
                        class="mt-0.5 line-clamp-2 text-xs text-muted-foreground"
                    >
                        {{ post.link_meta.description }}
                    </p>
                </div>
            </a>

            <!-- Poll -->
            <div
                v-if="post.poll"
                class="space-y-2.5 rounded-xl border border-border bg-muted/25 p-4"
            >
                <p class="text-sm font-semibold text-foreground">
                    {{ post.poll.question }}
                </p>

                <div class="space-y-2">
                    <button
                        v-for="option in post.poll.options"
                        :key="option.id"
                        type="button"
                        :disabled="post.poll.is_expired"
                        :class="[
                            'relative flex w-full items-center justify-between overflow-hidden rounded-lg border px-3.5 py-2.5 text-left text-xs transition-colors',
                            post.poll.voted_option_ids.includes(option.id)
                                ? 'border-primary bg-primary-soft font-semibold text-foreground'
                                : 'border-border bg-card text-foreground hover:border-primary/40',
                        ]"
                        @click="submitPollVote(option.id)"
                    >
                        <span
                            class="pointer-events-none absolute inset-y-0 left-0 bg-primary/12 transition-all"
                            :style="{ width: `${option.percentage}%` }"
                        />
                        <span class="relative flex items-center gap-2">
                            <Check
                                v-if="
                                    post.poll.voted_option_ids.includes(
                                        option.id,
                                    )
                                "
                                class="size-3.5 text-primary"
                            />
                            <span>{{ option.label }}</span>
                        </span>
                        <span
                            class="relative font-semibold text-muted-foreground"
                        >
                            {{ option.percentage }}% ({{ option.votes_count }})
                        </span>
                    </button>
                </div>

                <p class="text-[11px] text-muted-foreground">
                    {{
                        t('post.totalVotes', {
                            count: post.poll.total_votes,
                        })
                    }}
                    <span v-if="post.poll.is_expired">
                        · {{ t('post.pollEnded') }}
                    </span>
                </p>
            </div>

            <!-- Owner Boost Status Strip -->
            <div
                v-if="post.boost_summary"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/25 bg-primary-soft/60 px-3.5 py-2 text-xs"
            >
                <span
                    class="inline-flex items-center gap-1.5 font-semibold text-accent-foreground"
                >
                    <Zap class="size-3.5 text-primary" />
                    <span>{{ post.boost_summary.status_label }}</span>
                </span>
                <span class="text-[11px] text-muted-foreground">
                    {{
                        t('post.extra.boostMetrics', {
                            impressions: post.boost_summary.impressions_count,
                            clicks: post.boost_summary.clicks_count,
                        })
                    }}
                </span>
            </div>

            <!-- Sponsored Ad Call-to-Action Strip -->
            <a
                v-if="post.ad_campaign"
                :href="post.ad_campaign.click_url"
                :target="
                    post.ad_campaign.has_external_url ? '_blank' : undefined
                "
                :rel="
                    post.ad_campaign.has_external_url
                        ? 'noopener noreferrer'
                        : undefined
                "
                class="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/40 px-4 py-2.5 text-xs font-semibold text-foreground transition-colors hover:bg-surface-hover"
            >
                <span class="truncate">{{ post.ad_campaign.cta_label }}</span>
                <span
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-[11px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover"
                >
                    <span>{{ post.ad_campaign.cta_label }}</span>
                    <ArrowRight class="size-3.5" />
                </span>
            </a>
        </div>

        <!-- Engagement Action Bar (Uniform icons, tabular-nums, zero dropdown arrow) -->
        <footer
            class="mt-3.5 flex items-center justify-between gap-1 border-t border-border pt-2 text-xs text-muted-foreground"
        >
            <div class="flex flex-wrap items-center gap-0.5 sm:gap-1.5">
                <ReactionPicker
                    :post-id="post.id"
                    :active-reaction="optimisticReaction"
                    :count="optimisticReactionsCount"
                    :disabled="reacting"
                    @select="submitReaction"
                />

                <Button
                    variant="ghost"
                    size="sm"
                    :title="
                        t('post.commentsTitle', { count: post.comments_count })
                    "
                    :aria-label="
                        t('post.commentsTitle', { count: post.comments_count })
                    "
                    class="min-h-10 gap-1.5 rounded-lg px-2.5 text-xs text-muted-foreground hover:bg-surface-hover hover:text-foreground sm:min-h-9"
                    as-child
                >
                    <Link :href="PostController.show.url(post.id)">
                        <MessageCircle
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="tabular-nums">{{
                            formatCompactNumber(post.comments_count, locale)
                        }}</span>
                    </Link>
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :title="t('post.shareAction')"
                    :aria-label="
                        t('post.sharesExact', {
                            count: formatNumber(post.shares_count, locale),
                        })
                    "
                    class="min-h-10 gap-1.5 rounded-lg px-2.5 text-xs text-muted-foreground hover:bg-surface-hover hover:text-foreground sm:min-h-9"
                    @click="handleShare"
                >
                    <Share2 class="size-4 shrink-0" aria-hidden="true" />
                    <span class="tabular-nums">{{
                        formatCompactNumber(post.shares_count, locale)
                    }}</span>
                </Button>

                <span
                    data-test="post-views-count"
                    :title="
                        t('post.viewsExact', {
                            count: formatNumber(post.views_count ?? 0, locale),
                        })
                    "
                    :aria-label="
                        t('post.viewsExact', {
                            count: formatNumber(post.views_count ?? 0, locale),
                        })
                    "
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-lg px-2.5 text-xs text-muted-foreground sm:min-h-9"
                >
                    <Eye class="size-4 shrink-0" aria-hidden="true" />
                    <span class="tabular-nums">{{
                        formatCompactNumber(post.views_count ?? 0, locale)
                    }}</span>
                </span>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                :class="[
                    'size-10 shrink-0 rounded-lg sm:size-9',
                    optimisticBookmarked
                        ? 'text-primary'
                        : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                ]"
                :title="
                    optimisticBookmarked
                        ? t('post.bookmarked')
                        : t('post.bookmark')
                "
                :aria-label="
                    optimisticBookmarked
                        ? t('post.bookmarked')
                        : t('post.bookmark')
                "
                @click="submitBookmark"
            >
                <Bookmark
                    class="size-4"
                    :fill="optimisticBookmarked ? 'currentColor' : 'none'"
                />
            </Button>
        </footer>

        <ImageLightbox
            v-if="post.media.length > 0"
            v-model:open="lightboxOpen"
            :images="
                post.media.map((m) => ({
                    id: m.id,
                    url: m.url,
                    alt: m.alt_text || post.title || 'Post image',
                }))
            "
            :initial-index="lightboxIndex"
        />

        <Dialog v-model:open="reportOpen">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ t('report.title') }}</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitReport" class="space-y-4">
                    <div class="grid gap-1.5">
                        <Label :for="`post-report-reason-${post.id}`">{{
                            t('report.reason')
                        }}</Label>
                        <select
                            :id="`post-report-reason-${post.id}`"
                            v-model="reportForm.reason"
                            class="h-9 rounded-lg border border-input bg-background px-3 text-sm"
                        >
                            <option value="spam">
                                {{ t('report.reasons.spam') }}
                            </option>
                            <option value="harassment">
                                {{ t('report.reasons.harassment') }}
                            </option>
                            <option value="misinformation">
                                {{ t('report.reasons.misinformation') }}
                            </option>
                            <option value="copyright">
                                {{ t('report.reasons.copyright') }}
                            </option>
                            <option value="other">
                                {{ t('report.reasons.other') }}
                            </option>
                        </select>
                        <InputError :message="reportForm.errors.reason" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`post-report-details-${post.id}`">{{
                            t('report.details')
                        }}</Label>
                        <textarea
                            :id="`post-report-details-${post.id}`"
                            v-model="reportForm.details"
                            rows="3"
                            maxlength="1000"
                            class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="reportForm.errors.details" />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                {{ t('post.cancel') }}
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="reportForm.processing">
                            {{ t('report.submit') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmActionDialog
            v-model:open="confirmDeleteOpen"
            :title="t('post.delete')"
            :description="t('post.confirmDelete')"
            :confirm-label="t('post.delete')"
            :processing="deletingPost"
            destructive
            @confirm="executeDeletePost"
        />

        <BoostPostModal
            v-if="post.can_boost"
            v-model:open="boostModalOpen"
            :post-id="post.id"
            :post-excerpt="post.title || post.content.slice(0, 140)"
        />
    </article>
</template>
