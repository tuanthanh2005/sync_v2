<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    Calendar,
    Camera,
    Flag,
    Globe,
    MapPin,
    MessageSquare,
    Newspaper,
    Pencil,
    Settings,
    ShieldBan,
    ShieldCheck,
    UserMinus,
    UserPlus,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MessageController from '@/actions/App/Http/Controllers/Messaging/MessageController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReportDialog from '@/components/common/ReportDialog.vue';
import InputError from '@/components/InputError.vue';
import FollowListModal, {
    type FollowModalTab,
} from '@/components/social/FollowListModal.vue';
import PostCard from '@/components/social/PostCard.vue';
import UserBadges from '@/components/social/UserBadges.vue';
import AvatarUploader from '@/components/upload/AvatarUploader.vue';
import CoverImageUploader from '@/components/upload/CoverImageUploader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import {
    getLiveFollowersCount,
    getLiveFollowingCount,
    isUserFollowed,
    isUserFollowPending,
    toggleUserFollow,
} from '@/composables/useFollowState';
import { useInitials } from '@/composables/useInitials';
import {
    formatCompactNumber,
    formatDate,
    formatNumber,
} from '@/lib/formatters';
import { appToast } from '@/lib/toast';
import { login } from '@/routes';
import { edit as editProfileSettings } from '@/routes/profile';
import type { ProfileViewUser, PublicFeedPost } from '@/types';

const props = defineProps<{
    profile_user: ProfileViewUser;
    posts: PublicFeedPost[];
    bookmarks: PublicFeedPost[];
    tab: 'posts' | 'bookmarks';
}>();

const { t, locale } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const editOpen = ref(false);
const reportOpen = ref(false);
const followModalOpen = ref(false);
const followModalTab = ref<FollowModalTab>('followers');

const liveIsFollowing = computed(() =>
    isUserFollowed(props.profile_user.id, props.profile_user.is_following),
);
const liveFollowPending = computed(() =>
    isUserFollowPending(props.profile_user.id),
);
const liveFollowersCount = computed(() =>
    getLiveFollowersCount(
        props.profile_user.id,
        props.profile_user.followers_count,
    ),
);
const liveFollowingCount = computed(() =>
    getLiveFollowingCount(
        props.profile_user.id,
        props.profile_user.following_count,
    ),
);

function openFollowModal(nextTab: FollowModalTab): void {
    followModalTab.value = nextTab;
    followModalOpen.value = true;
}

const profileForm = useForm<{
    headline: string;
    bio: string;
    location: string;
    website_url: string;
    avatar: File | null;
    cover: File | null;
    remove_avatar: boolean;
    remove_cover: boolean;
}>({
    headline: props.profile_user.headline ?? '',
    bio: props.profile_user.bio ?? '',
    location: props.profile_user.location ?? '',
    website_url: props.profile_user.website_url ?? '',
    avatar: null,
    cover: null,
    remove_avatar: false,
    remove_cover: false,
});

function openEditModal(): void {
    profileForm.headline = props.profile_user.headline ?? '';
    profileForm.bio = props.profile_user.bio ?? '';
    profileForm.location = props.profile_user.location ?? '';
    profileForm.website_url = props.profile_user.website_url ?? '';
    profileForm.avatar = null;
    profileForm.cover = null;
    profileForm.remove_avatar = false;
    profileForm.remove_cover = false;
    profileForm.clearErrors();
    editOpen.value = true;
}

function switchTab(nextTab: 'posts' | 'bookmarks'): void {
    router.get(
        ProfileViewController.show.url(props.profile_user.username, {
            query: { tab: nextTab },
        }),
        {},
        { preserveScroll: true },
    );
}

async function handleFollow(): Promise<void> {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    const result = await toggleUserFollow({
        targetUserId: props.profile_user.id,
        targetUsername: props.profile_user.username,
        currentlyFollowing: liveIsFollowing.value,
        viewerUserId: user.value.id,
    });

    if (result.ok) {
        if (result.message) {
            appToast.success(result.message);
        }
    } else {
        appToast.error(result.message || t('socialList.followError'));
    }
}

function handleBlock(): void {
    if (!user.value) {
        return;
    }

    router.post(
        ProfileViewController.toggleBlock.url(props.profile_user.username),
        {},
        { preserveScroll: true },
    );
}

function startMessage(): void {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    router.post(MessageController.start.url(props.profile_user.username));
}

function submitProfileDetails(): void {
    if (profileForm.processing) {
        return;
    }

    profileForm
        .transform((data) => ({
            ...data,
            _method: 'patch',
        }))
        .post(ProfileViewController.updateDetails.url(), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                editOpen.value = false;
                profileForm.avatar = null;
                profileForm.cover = null;
                profileForm.remove_avatar = false;
                profileForm.remove_cover = false;
            },
        });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="`${profile_user.name} (@${profile_user.username})`" />

        <!-- Profile Header Card -->
        <section
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div
                class="relative h-36 w-full border-b border-border bg-muted/70 sm:h-48"
            >
                <img
                    v-if="profile_user.cover"
                    :src="profile_user.cover"
                    :alt="t('profile.cover')"
                    class="size-full object-cover"
                />
                <Button
                    v-if="profile_user.is_owner"
                    type="button"
                    size="sm"
                    variant="secondary"
                    class="absolute end-3 bottom-3 h-8 gap-1.5 rounded-lg border border-border/70 bg-card/95 px-2.5 text-xs shadow-xs hover:bg-card"
                    @click="openEditModal"
                >
                    <Camera class="size-3.5 text-primary" aria-hidden="true" />
                    <span>{{ t('upload.changeCover') }}</span>
                </Button>
            </div>

            <div class="px-5 pb-6 sm:px-6">
                <div
                    class="-mt-12 flex flex-wrap items-end justify-between gap-4"
                >
                    <div class="relative">
                        <Avatar
                            class="size-24 rounded-full border-4 border-card shadow-2xs"
                        >
                            <AvatarImage
                                v-if="profile_user.avatar"
                                :src="profile_user.avatar"
                                :alt="profile_user.name"
                            />
                            <AvatarFallback
                                class="bg-primary-soft text-xl font-bold text-accent-foreground"
                            >
                                {{ getInitials(profile_user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <button
                            v-if="profile_user.is_owner"
                            type="button"
                            class="absolute end-0 bottom-0 flex size-8 items-center justify-center rounded-full border-2 border-card bg-primary text-primary-foreground shadow-xs transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="t('upload.changeAvatar')"
                            :title="t('upload.changeAvatar')"
                            @click="openEditModal"
                        >
                            <Camera class="size-3.5" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-2">
                        <template v-if="profile_user.is_owner">
                            <Dialog v-model:open="editOpen">
                                <DialogTrigger as-child>
                                    <Button
                                        size="sm"
                                        class="rounded-lg"
                                        @click="openEditModal"
                                    >
                                        <Pencil class="size-4" />
                                        <span>{{
                                            t('profile.editProfile')
                                        }}</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent
                                    structured
                                    :show-close-button="false"
                                    class="sm:max-w-xl"
                                >
                                    <div
                                        class="flex min-h-16 shrink-0 items-center justify-between gap-4 border-b border-border bg-card px-5 py-4 sm:px-6"
                                    >
                                        <DialogHeader
                                            class="min-w-0 flex-1 gap-0 text-start"
                                        >
                                            <DialogTitle
                                                class="truncate text-lg font-semibold text-foreground"
                                            >
                                                {{ t('profile.editProfile') }}
                                            </DialogTitle>
                                        </DialogHeader>
                                        <DialogClose as-child>
                                            <button
                                                type="button"
                                                :aria-label="t('common.close')"
                                                class="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                                            >
                                                <X
                                                    class="size-5"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </DialogClose>
                                    </div>

                                    <form
                                        id="profile-edit-form"
                                        class="flex min-h-0 flex-1 flex-col"
                                        @submit.prevent="submitProfileDetails"
                                    >
                                        <!-- Upload progress bar -->
                                        <div
                                            v-if="
                                                profileForm.processing &&
                                                profileForm.progress
                                            "
                                            class="h-1 w-full shrink-0 overflow-hidden bg-muted"
                                            role="progressbar"
                                            :aria-valuenow="
                                                profileForm.progress.percentage
                                            "
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                        >
                                            <div
                                                class="h-full bg-primary transition-[width] duration-150"
                                                :style="{
                                                    width: `${profileForm.progress.percentage}%`,
                                                }"
                                            />
                                        </div>

                                        <AutoHideScrollArea
                                            class="min-h-0 flex-1 px-5 py-4"
                                        >
                                            <div class="space-y-5">
                                                <!-- Cover Image Section -->
                                                <div class="space-y-1.5">
                                                    <Label>{{
                                                        t('profile.cover')
                                                    }}</Label>
                                                    <CoverImageUploader
                                                        v-model="
                                                            profileForm.cover
                                                        "
                                                        v-model:removed="
                                                            profileForm.remove_cover
                                                        "
                                                        :current-url="
                                                            profile_user.cover
                                                        "
                                                        :external-error="
                                                            profileForm.errors
                                                                .cover
                                                        "
                                                        :disabled="
                                                            profileForm.processing
                                                        "
                                                    />
                                                </div>

                                                <!-- Avatar Section -->
                                                <div
                                                    class="rounded-xl border border-border/80 bg-muted/20 p-4"
                                                >
                                                    <AvatarUploader
                                                        v-model="
                                                            profileForm.avatar
                                                        "
                                                        v-model:removed="
                                                            profileForm.remove_avatar
                                                        "
                                                        :current-url="
                                                            profile_user.avatar
                                                        "
                                                        :display-name="
                                                            profile_user.name
                                                        "
                                                        :external-error="
                                                            profileForm.errors
                                                                .avatar
                                                        "
                                                        :disabled="
                                                            profileForm.processing
                                                        "
                                                    />
                                                </div>

                                                <div class="grid gap-1.5">
                                                    <Label for="headline">{{
                                                        t('profile.headline')
                                                    }}</Label>
                                                    <Input
                                                        id="headline"
                                                        v-model="
                                                            profileForm.headline
                                                        "
                                                        maxlength="120"
                                                    />
                                                    <InputError
                                                        :message="
                                                            profileForm.errors
                                                                .headline
                                                        "
                                                    />
                                                </div>

                                                <div class="grid gap-1.5">
                                                    <Label for="bio">{{
                                                        t('profile.bio')
                                                    }}</Label>
                                                    <textarea
                                                        id="bio"
                                                        v-model="
                                                            profileForm.bio
                                                        "
                                                        rows="3"
                                                        maxlength="1000"
                                                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                                                    />
                                                    <InputError
                                                        :message="
                                                            profileForm.errors
                                                                .bio
                                                        "
                                                    />
                                                </div>

                                                <div
                                                    class="grid gap-3 sm:grid-cols-2"
                                                >
                                                    <div class="grid gap-1.5">
                                                        <Label for="location">{{
                                                            t(
                                                                'profile.location',
                                                            )
                                                        }}</Label>
                                                        <Input
                                                            id="location"
                                                            v-model="
                                                                profileForm.location
                                                            "
                                                            maxlength="100"
                                                        />
                                                    </div>
                                                    <div class="grid gap-1.5">
                                                        <Label
                                                            for="website_url"
                                                            >{{
                                                                t(
                                                                    'profile.website',
                                                                )
                                                            }}</Label
                                                        >
                                                        <Input
                                                            id="website_url"
                                                            v-model="
                                                                profileForm.website_url
                                                            "
                                                            type="url"
                                                            placeholder="https://..."
                                                        />
                                                        <InputError
                                                            :message="
                                                                profileForm
                                                                    .errors
                                                                    .website_url
                                                            "
                                                        />
                                                    </div>
                                                </div>

                                                <p
                                                    class="flex items-start gap-1.5 text-[11px] leading-relaxed text-muted-foreground"
                                                >
                                                    <ShieldCheck
                                                        class="mt-0.5 size-3.5 shrink-0 text-primary/70"
                                                        aria-hidden="true"
                                                    />
                                                    <span>{{
                                                        t(
                                                            'upload.ownershipPolicyNotice',
                                                        )
                                                    }}</span>
                                                </p>
                                            </div>
                                        </AutoHideScrollArea>

                                        <DialogFooter
                                            class="shrink-0 gap-3 border-t border-border bg-card px-5 pt-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:px-6 sm:py-4"
                                        >
                                            <DialogClose as-child>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    class="rounded-lg"
                                                    :disabled="
                                                        profileForm.processing
                                                    "
                                                >
                                                    {{ t('post.cancel') }}
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                :disabled="
                                                    profileForm.processing
                                                "
                                                class="rounded-lg"
                                            >
                                                <Spinner
                                                    v-if="
                                                        profileForm.processing
                                                    "
                                                />
                                                <span>{{
                                                    t('profile.save')
                                                }}</span>
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>

                            <Button
                                variant="outline"
                                size="sm"
                                class="rounded-lg"
                                as-child
                            >
                                <Link :href="editProfileSettings()">
                                    <Settings class="size-4" />
                                    <span>{{
                                        t('profile.accountSettings')
                                    }}</span>
                                </Link>
                            </Button>
                        </template>

                        <template v-else>
                            <Button
                                size="sm"
                                :variant="
                                    liveIsFollowing ? 'outline' : 'default'
                                "
                                :disabled="liveFollowPending"
                                class="rounded-lg"
                                @click="handleFollow"
                            >
                                <Spinner
                                    v-if="liveFollowPending"
                                    class="size-4"
                                />
                                <UserMinus
                                    v-else-if="liveIsFollowing"
                                    class="size-4"
                                />
                                <UserPlus v-else class="size-4" />
                                <span>
                                    {{
                                        liveIsFollowing
                                            ? t('profile.following')
                                            : t('profile.follow')
                                    }}
                                </span>
                            </Button>

                            <Button
                                v-if="user && !profile_user.is_blocked"
                                variant="outline"
                                size="sm"
                                class="rounded-lg"
                                @click="startMessage"
                            >
                                <MessageSquare class="size-4" />
                                <span>{{ t('messages.messageUser') }}</span>
                            </Button>

                            <Button
                                v-if="user"
                                variant="ghost"
                                size="sm"
                                class="rounded-lg text-muted-foreground hover:text-destructive"
                                @click="handleBlock"
                            >
                                <ShieldBan class="size-4" />
                                <span>
                                    {{
                                        profile_user.is_blocked
                                            ? t('profile.unblock')
                                            : t('profile.block')
                                    }}
                                </span>
                            </Button>

                            <Button
                                v-if="user"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8 rounded-lg text-muted-foreground"
                                :aria-label="t('report.action')"
                                @click="reportOpen = true"
                            >
                                <Flag class="size-4" />
                            </Button>
                        </template>

                        <ReportDialog
                            v-if="user"
                            v-model:open="reportOpen"
                            target-type="user"
                            :target-id="profile_user.id"
                            default-reason="harassment"
                        />
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-bold text-foreground">
                            {{ profile_user.name }}
                        </h1>
                        <UserBadges
                            :badges="profile_user.compact_badges"
                            mode="compact"
                            size="md"
                            :max="2"
                            :fallback-is-premium="Boolean(profile_user.is_premium)"
                            :fallback-is-ai="Boolean(profile_user.is_ai_agent)"
                            :fallback-ai-disclosure="profile_user.ai_disclosure"
                        />
                    </div>
                    <div
                        v-if="(profile_user.profile_badges ?? profile_user.badges ?? []).length > 0"
                        class="pt-0.5"
                    >
                        <UserBadges
                            :badges="profile_user.profile_badges ?? profile_user.badges"
                            mode="showcase"
                            size="md"
                        />
                    </div>
                    <div
                        v-if="profile_user.is_ai_agent"
                        class="rounded-xl border border-[#14B8A6]/30 bg-[#14B8A6]/5 px-3.5 py-2.5 text-xs text-muted-foreground"
                    >
                        <strong class="font-semibold text-foreground">{{
                            t('ai.badge.profileBannerTitle')
                        }}</strong>
                        —
                        {{
                            profile_user.ai_disclosure ||
                            t('ai.badge.defaultDisclosure')
                        }}
                    </div>
                    <p class="text-sm text-muted-foreground">
                        @{{ profile_user.username }}
                        <span v-if="profile_user.headline">
                            · {{ profile_user.headline }}
                        </span>
                    </p>
                    <p
                        v-if="profile_user.bio"
                        class="max-w-2xl text-sm leading-relaxed text-foreground/90"
                    >
                        {{ profile_user.bio }}
                    </p>

                    <div
                        class="flex flex-wrap items-center gap-4 pt-1 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="profile_user.location"
                            class="inline-flex items-center gap-1"
                        >
                            <MapPin class="size-3.5" />
                            {{ profile_user.location }}
                        </span>
                        <a
                            v-if="profile_user.website_url"
                            :href="profile_user.website_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <Globe class="size-3.5" />
                            {{ profile_user.website_url }}
                        </a>
                        <span
                            v-if="profile_user.joined_at"
                            class="inline-flex items-center gap-1"
                        >
                            <Calendar class="size-3.5" />
                            {{ formatDate(profile_user.joined_at, locale) }}
                        </span>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 pt-3 sm:gap-2.5"
                    >
                        <button
                            type="button"
                            data-test="profile-followers-trigger"
                            :aria-label="
                                t('profile.followers', {
                                    count: formatNumber(
                                        liveFollowersCount,
                                        locale,
                                    ),
                                })
                            "
                            class="group inline-flex cursor-pointer items-baseline gap-1.5 rounded-xl border border-border/70 bg-muted/25 px-3.5 py-2 text-start transition-colors hover:border-primary/40 hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            @click="openFollowModal('followers')"
                        >
                            <span
                                class="text-sm font-bold text-foreground tabular-nums group-hover:text-primary"
                            >
                                {{
                                    formatCompactNumber(
                                        liveFollowersCount,
                                        locale,
                                    )
                                }}
                            </span>
                            <span
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ t('socialList.followersTitle') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            data-test="profile-following-trigger"
                            :aria-label="
                                t('profile.followingCount', {
                                    count: formatNumber(
                                        liveFollowingCount,
                                        locale,
                                    ),
                                })
                            "
                            class="group inline-flex cursor-pointer items-baseline gap-1.5 rounded-xl border border-border/70 bg-muted/25 px-3.5 py-2 text-start transition-colors hover:border-primary/40 hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            @click="openFollowModal('following')"
                        >
                            <span
                                class="text-sm font-bold text-foreground tabular-nums group-hover:text-primary"
                            >
                                {{
                                    formatCompactNumber(
                                        liveFollowingCount,
                                        locale,
                                    )
                                }}
                            </span>
                            <span
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ t('socialList.followingTitle') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            data-test="profile-posts-stat"
                            class="inline-flex cursor-pointer items-baseline gap-1.5 rounded-xl border border-border/60 bg-muted/15 px-3.5 py-2 text-start transition-colors hover:border-border hover:bg-surface-hover/70 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            @click="switchTab('posts')"
                        >
                            <span
                                class="text-sm font-bold text-foreground tabular-nums"
                            >
                                {{
                                    formatCompactNumber(
                                        profile_user.posts_count,
                                        locale,
                                    )
                                }}
                            </span>
                            <span
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ t('socialList.postsLabel') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Profile Tabs -->
        <div class="flex items-center gap-2 border-b border-border pb-2">
            <Button
                type="button"
                size="sm"
                :variant="tab === 'posts' ? 'secondary' : 'ghost'"
                class="rounded-lg text-xs"
                @click="switchTab('posts')"
            >
                <Newspaper class="size-4" />
                <span>{{ t('profile.postsTab') }}</span>
            </Button>
            <Button
                v-if="profile_user.is_owner"
                type="button"
                size="sm"
                :variant="tab === 'bookmarks' ? 'secondary' : 'ghost'"
                class="rounded-lg text-xs"
                @click="switchTab('bookmarks')"
            >
                <Bookmark class="size-4" />
                <span>{{ t('profile.bookmarksTab') }}</span>
            </Button>
        </div>

        <!-- Tab Content -->
        <section
            v-if="tab === 'bookmarks' && profile_user.is_owner"
            class="space-y-4"
        >
            <PostCard
                v-for="post in bookmarks"
                :key="post.id"
                :post="post"
                view-source="profile"
            />
            <EmptyState
                v-if="bookmarks.length === 0"
                :icon="Bookmark"
                :title="t('profile.bookmarksTab')"
                :description="t('profile.noBookmarks')"
            />
        </section>

        <section v-else class="space-y-4">
            <PostCard
                v-for="post in posts"
                :key="post.id"
                :post="post"
                view-source="profile"
            />
            <EmptyState
                v-if="posts.length === 0"
                :icon="Newspaper"
                :title="t('profile.postsTab')"
                :description="t('profile.noPosts')"
            />
        </section>

        <FollowListModal
            v-model:open="followModalOpen"
            :profile-user-id="profile_user.id"
            :profile-name="profile_user.name"
            :profile-username="profile_user.username"
            :initial-tab="followModalTab"
            :initial-followers-count="profile_user.followers_count"
            :initial-following-count="profile_user.following_count"
        />
    </div>
</template>
