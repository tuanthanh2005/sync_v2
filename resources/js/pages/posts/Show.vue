<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3, CornerDownRight, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommentController from '@/actions/App/Http/Controllers/Social/CommentController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import AiBadge from '@/components/ai/AiBadge.vue';
import InputError from '@/components/InputError.vue';
import PostCard from '@/components/social/PostCard.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { useLivePostViewAnalytics } from '@/composables/usePostViewTracker';
import { formatDateTime, formatNumber } from '@/lib/formatters';
import { appToast } from '@/lib/toast';
import { home, login, register } from '@/routes';
import type { CommentItem, PublicFeedPost } from '@/types';

type RelatedPostItem = {
    id: number;
    title: string;
    excerpt: string;
    path: string;
    url: string;
    author_name?: string;
    created_at: string | null;
};

type SeoInternalLinks = {
    related_posts: RelatedPostItem[];
    more_from_author: RelatedPostItem[];
    related_community: {
        name: string;
        slug: string;
        path: string;
        members_count: number;
    } | null;
    related_topics: Array<{
        name: string;
        slug: string;
        path: string;
        posts_count: number;
    }>;
};

type SeoLanguageInfo = {
    current: string;
    original: string;
    is_translation: boolean;
    alternates: Array<{ hreflang: string; href: string }>;
};

const props = defineProps<{
    post: PublicFeedPost;
    comments: CommentItem[];
    seoInternalLinks?: SeoInternalLinks;
    seoLanguage?: SeoLanguageInfo;
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const replyingToId = ref<number | null>(null);
const replyContent = ref('');
const deleteCommentId = ref<number | null>(null);
const deletingComment = ref(false);

const liveViewAnalytics = useLivePostViewAnalytics(
    () => props.post.id,
    () => props.post.view_analytics,
);

const commentForm = useForm({
    content: '',
});

function submitComment(): void {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    if (commentForm.processing) {
        return;
    }

    commentForm.post(CommentController.store.url(props.post.id), {
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset();
        },
    });
}

function submitReply(parentId: number): void {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    const body = replyContent.value.trim();

    if (!body) {
        return;
    }

    router.post(
        CommentController.store.url(props.post.id),
        { content: body, parent_id: parentId },
        {
            preserveScroll: true,
            onSuccess: () => {
                replyContent.value = '';
                replyingToId.value = null;
            },
        },
    );
}

function requestDeleteComment(commentId: number): void {
    deleteCommentId.value = commentId;
}

function executeDeleteComment(): void {
    if (deleteCommentId.value === null) {
        return;
    }

    deletingComment.value = true;
    router.delete(CommentController.destroy.url(deleteCommentId.value), {
        preserveScroll: true,
        onFinish: () => {
            deletingComment.value = false;
            deleteCommentId.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="post.title || post.content.slice(0, 60)" />

        <div>
            <Button variant="ghost" size="sm" class="rounded-lg" as-child>
                <Link :href="home()">
                    <ArrowLeft class="size-4" />
                    <span>{{ t('post.backToFeed') }}</span>
                </Link>
            </Button>
        </div>

        <PostCard :post="post" :truncate="false" view-source="detail" />

        <!-- Post View Analytics Breakdown (Author & Staff Only, Zero Viewer PII) -->
        <section
            v-if="liveViewAnalytics"
            data-test="post-view-analytics"
            class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <BarChart3 class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('post.viewAnalytics.title') }}</span>
                </h2>
                <span class="text-[11px] text-muted-foreground">
                    {{ t('post.viewAnalytics.privacyNote') }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.totalViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-base font-bold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(liveViewAnalytics.total_views, locale)
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.humanViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-base font-bold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(liveViewAnalytics.human_views, locale)
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.guestViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-base font-bold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(liveViewAnalytics.guest_views, locale)
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.uniqueHumanViewers') }}
                    </p>
                    <p
                        class="mt-0.5 text-base font-bold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(
                                liveViewAnalytics.unique_human_viewers,
                                locale,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.organicViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-sm font-semibold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(
                                liveViewAnalytics.organic_views,
                                locale,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.promotedHumanViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-sm font-semibold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(
                                liveViewAnalytics.promoted_human_views,
                                locale,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.aiViews') }}
                    </p>
                    <p
                        class="mt-0.5 text-sm font-semibold text-foreground tabular-nums"
                    >
                        {{ formatNumber(liveViewAnalytics.ai_views, locale) }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-border/70 bg-muted/25 px-3 py-2"
                >
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('post.viewAnalytics.aiContentAccesses') }}
                    </p>
                    <p
                        class="mt-0.5 text-sm font-semibold text-foreground tabular-nums"
                    >
                        {{
                            formatNumber(
                                liveViewAnalytics.ai_content_accesses,
                                locale,
                            )
                        }}
                    </p>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border/70 pt-2.5 text-[11px] text-muted-foreground"
            >
                <span>{{ t('post.viewAnalytics.aiSeparationNotice') }}</span>
                <div class="flex flex-wrap items-center gap-3 tabular-nums">
                    <span v-if="liveViewAnalytics.invalid_views > 0">
                        {{ t('post.viewAnalytics.invalidViews') }}:
                        {{
                            formatNumber(
                                liveViewAnalytics.invalid_views,
                                locale,
                            )
                        }}
                    </span>
                    <span v-if="liveViewAnalytics.legacy_views > 0">
                        {{ t('post.viewAnalytics.legacyViews') }}:
                        {{
                            formatNumber(liveViewAnalytics.legacy_views, locale)
                        }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Subtle Guest Organic Conversion CTA -->
        <section
            v-if="!user"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/25 bg-primary-soft/50 p-4"
        >
            <div class="min-w-0">
                <p class="text-sm font-semibold text-foreground">
                    {{ t('seo.post.guestCtaTitle') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('seo.post.guestCtaDesc') }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="login()">{{ t('nav.login') }}</Link>
                </Button>
                <Button size="sm" as-child>
                    <Link :href="register()">{{ t('nav.register') }}</Link>
                </Button>
            </div>
        </section>

        <!-- Available Published Language Versions -->
        <section
            v-if="seoLanguage && seoLanguage.alternates.length > 2"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 text-xs"
        >
            <span class="font-medium text-muted-foreground">
                {{ t('seo.post.availableLanguages') }}:
            </span>
            <a
                v-for="alt in seoLanguage.alternates.filter(
                    (item) => item.hreflang !== 'x-default',
                )"
                :key="alt.hreflang"
                :href="alt.href"
                :class="[
                    'rounded-full border px-2.5 py-0.5 font-medium uppercase transition-colors',
                    alt.hreflang === seoLanguage.current
                        ? 'border-primary bg-primary-soft text-primary'
                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground',
                ]"
            >
                {{ alt.hreflang }}
            </a>
        </section>

        <!-- Comments Section -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2 class="text-base font-semibold text-foreground">
                {{ t('post.commentsTitle', { count: post.comments_count }) }}
            </h2>

            <form @submit.prevent="submitComment" class="space-y-2.5">
                <textarea
                    v-model="commentForm.content"
                    rows="2"
                    required
                    maxlength="3000"
                    :placeholder="t('post.commentPlaceholder')"
                    class="w-full rounded-xl border border-input bg-muted/30 px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                />
                <InputError :message="commentForm.errors.content" />
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="commentForm.processing"
                        class="rounded-lg text-xs"
                    >
                        <Spinner v-if="commentForm.processing" />
                        <span>{{ t('post.sendComment') }}</span>
                    </Button>
                </div>
            </form>

            <div v-if="comments.length > 0" class="space-y-4 pt-2">
                <div
                    v-for="comment in comments"
                    :key="comment.id"
                    class="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <Link
                                v-if="comment.author"
                                :href="
                                    ProfileViewController.show.url(
                                        comment.author.username,
                                    )
                                "
                                class="shrink-0"
                            >
                                <Avatar class="size-8 rounded-full">
                                    <AvatarImage
                                        v-if="comment.author.avatar"
                                        :src="comment.author.avatar"
                                        :alt="comment.author.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-[11px] font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(comment.author.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Link>
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-1 text-xs font-semibold text-foreground"
                                >
                                    <span>{{
                                        comment.author?.name || 'Member'
                                    }}</span>
                                    <PremiumBadge
                                        v-if="
                                            comment.author?.is_premium &&
                                            !comment.author?.is_ai_agent
                                        "
                                        size="sm"
                                    />
                                    <AiBadge
                                        v-if="
                                            comment.author?.is_ai_agent ||
                                            comment.is_ai_generated
                                        "
                                        :disclosure="
                                            comment.author?.ai_disclosure
                                        "
                                        size="sm"
                                    />
                                    <span
                                        class="ml-1 font-normal text-muted-foreground"
                                    >
                                        {{
                                            comment.created_at
                                                ? formatDateTime(
                                                      comment.created_at,
                                                      locale,
                                                  )
                                                : ''
                                        }}
                                    </span>
                                </p>
                                <p
                                    class="mt-1 text-sm [overflow-wrap:anywhere] break-words whitespace-pre-line text-foreground/90"
                                >
                                    {{ comment.content }}
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="min-h-9 rounded-lg px-2.5 text-xs text-muted-foreground sm:min-h-7"
                                @click="
                                    replyingToId =
                                        replyingToId === comment.id
                                            ? null
                                            : comment.id
                                "
                            >
                                {{ t('post.reply') }}
                            </Button>
                            <Button
                                v-if="comment.can_delete"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-9 text-muted-foreground hover:text-destructive sm:size-7"
                                @click="requestDeleteComment(comment.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>

                    <!-- Reply box -->
                    <div
                        v-if="replyingToId === comment.id"
                        class="flex items-center gap-2 sm:pl-10"
                    >
                        <input
                            v-model="replyContent"
                            type="text"
                            maxlength="3000"
                            :placeholder="t('post.replyPlaceholder')"
                            class="min-h-10 min-w-0 flex-1 rounded-lg border border-input bg-card px-3 text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-9"
                            @keydown.enter.prevent="submitReply(comment.id)"
                        />
                        <Button
                            type="button"
                            size="sm"
                            class="min-h-10 shrink-0 rounded-lg text-xs sm:min-h-9"
                            @click="submitReply(comment.id)"
                        >
                            {{ t('post.sendComment') }}
                        </Button>
                    </div>

                    <!-- Nested Replies -->
                    <div
                        v-if="comment.replies.length > 0"
                        class="space-y-2.5 border-l-2 border-border pl-3 sm:ml-6 sm:pl-4"
                    >
                        <div
                            v-for="reply in comment.replies"
                            :key="reply.id"
                            class="flex items-start justify-between gap-2 rounded-lg bg-card p-3"
                        >
                            <div class="flex min-w-0 items-start gap-2">
                                <CornerDownRight
                                    class="mt-1 size-3.5 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="flex flex-wrap items-center gap-1 text-xs font-semibold text-foreground"
                                    >
                                        <span>{{
                                            reply.author?.name || 'Member'
                                        }}</span>
                                        <PremiumBadge
                                            v-if="
                                                reply.author?.is_premium &&
                                                !reply.author?.is_ai_agent
                                            "
                                            size="sm"
                                        />
                                        <AiBadge
                                            v-if="
                                                reply.author?.is_ai_agent ||
                                                reply.is_ai_generated
                                            "
                                            :disclosure="
                                                reply.author?.ai_disclosure
                                            "
                                            size="sm"
                                        />
                                        <span
                                            class="ml-1 font-normal text-muted-foreground"
                                        >
                                            {{
                                                reply.created_at
                                                    ? formatDateTime(
                                                          reply.created_at,
                                                          locale,
                                                      )
                                                    : ''
                                            }}
                                        </span>
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-line text-foreground/90"
                                    >
                                        {{ reply.content }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                v-if="reply.can_delete"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-9 shrink-0 text-muted-foreground hover:text-destructive sm:size-7"
                                @click="requestDeleteComment(reply.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <p v-else class="py-4 text-center text-xs text-muted-foreground">
                {{ t('post.noComments') }}
            </p>
        </section>

        <!-- Crawlable SEO Internal Linking Sections -->
        <section
            v-if="
                seoInternalLinks &&
                (seoInternalLinks.related_posts.length > 0 ||
                    seoInternalLinks.more_from_author.length > 0 ||
                    seoInternalLinks.related_topics.length > 0)
            "
            class="space-y-4"
        >
            <div
                v-if="seoInternalLinks.related_topics.length > 0"
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('seo.post.relatedTopics') }}
                </h2>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    <Link
                        v-for="topic in seoInternalLinks.related_topics"
                        :key="topic.slug"
                        :href="topic.path"
                        class="rounded-full border border-border bg-muted/40 px-3 py-1 text-xs font-medium text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                    >
                        #{{ topic.name }}
                    </Link>
                    <Link
                        v-if="seoInternalLinks.related_community"
                        :href="seoInternalLinks.related_community.path"
                        class="rounded-full border border-primary/30 bg-primary-soft px-3 py-1 text-xs font-medium text-primary"
                    >
                        {{ seoInternalLinks.related_community.name }}
                    </Link>
                </div>
            </div>

            <div
                v-if="seoInternalLinks.related_posts.length > 0"
                class="rounded-xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('seo.post.relatedPosts') }}
                </h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <Link
                        v-for="rp in seoInternalLinks.related_posts"
                        :key="rp.id"
                        :href="rp.path"
                        class="group block rounded-lg border border-border/80 bg-muted/20 p-3 transition-colors hover:border-primary/40 hover:bg-surface-hover"
                    >
                        <p
                            class="line-clamp-2 text-xs font-semibold text-foreground group-hover:text-primary"
                        >
                            {{ rp.title }}
                        </p>
                        <p
                            class="mt-1 line-clamp-2 text-[11px] text-muted-foreground"
                        >
                            {{ rp.excerpt }}
                        </p>
                    </Link>
                </div>
            </div>

            <div
                v-if="seoInternalLinks.more_from_author.length > 0"
                class="rounded-xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('seo.post.moreFromAuthor') }}
                </h2>
                <ul class="mt-2.5 space-y-2">
                    <li
                        v-for="mp in seoInternalLinks.more_from_author"
                        :key="mp.id"
                    >
                        <Link
                            :href="mp.path"
                            class="block text-xs font-medium text-foreground transition-colors hover:text-primary"
                        >
                            {{ mp.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </section>

        <ConfirmActionDialog
            :open="deleteCommentId !== null"
            :title="t('post.delete')"
            :description="t('post.confirmDelete')"
            :confirm-label="t('post.delete')"
            :processing="deletingComment"
            destructive
            @update:open="
                (val) => {
                    if (!val) deleteCommentId = null;
                }
            "
            @confirm="executeDeleteComment"
        />
    </div>
</template>
