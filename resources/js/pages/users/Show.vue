<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Bookmark,
    Calendar,
    Flag,
    Globe,
    MapPin,
    MessageSquare,
    Newspaper,
    Pencil,
    Settings,
    ShieldBan,
    UserMinus,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import MessageController from '@/actions/App/Http/Controllers/Messaging/MessageController';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import EmptyState from '@/components/common/EmptyState.vue';
import ReportDialog from '@/components/common/ReportDialog.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import InputError from '@/components/InputError.vue';
import PostCard from '@/components/social/PostCard.vue';
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
import { useInitials } from '@/composables/useInitials';
import { login } from '@/routes';
import { edit as editProfileSettings } from '@/routes/profile';
import type { ProfileViewUser, PublicFeedPost } from '@/types';

const props = defineProps<{
    profile_user: ProfileViewUser;
    posts: PublicFeedPost[];
    bookmarks: PublicFeedPost[];
    tab: 'posts' | 'bookmarks';
}>();

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const editOpen = ref(false);
const reportOpen = ref(false);

const profileForm = useForm<{
    headline: string;
    bio: string;
    location: string;
    website_url: string;
    avatar: File | null;
    cover: File | null;
}>({
    headline: props.profile_user.headline ?? '',
    bio: props.profile_user.bio ?? '',
    location: props.profile_user.location ?? '',
    website_url: props.profile_user.website_url ?? '',
    avatar: null,
    cover: null,
});

function switchTab(nextTab: 'posts' | 'bookmarks'): void {
    router.get(
        ProfileViewController.show.url(props.profile_user.username, {
            query: { tab: nextTab },
        }),
        {},
        { preserveScroll: true },
    );
}

function handleFollow(): void {
    if (!user.value) {
        toast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    router.post(
        ProfileViewController.toggleFollow.url(props.profile_user.username),
        {},
        { preserveScroll: true },
    );
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
        toast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    router.post(MessageController.start.url(props.profile_user.username));
}

function submitProfileDetails(): void {
    profileForm
        .transform((data) => ({
            ...data,
            _method: 'patch',
        }))
        .post(ProfileViewController.updateDetails.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                editOpen.value = false;
                profileForm.avatar = null;
                profileForm.cover = null;
            },
        });
}

function setFile(field: 'avatar' | 'cover', event: Event): void {
    const input = event.target as HTMLInputElement;
    profileForm[field] = input.files?.[0] ?? null;
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="`${profile_user.name} (@${profile_user.username})`" />

        <!-- Profile Header Card -->
        <section
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div class="h-36 w-full border-b border-border bg-muted/70 sm:h-44">
                <img
                    v-if="profile_user.cover"
                    :src="profile_user.cover"
                    alt="Cover banner"
                    class="size-full object-cover"
                />
            </div>

            <div class="px-5 pb-6 sm:px-6">
                <div
                    class="-mt-12 flex flex-wrap items-end justify-between gap-4"
                >
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

                    <div class="flex flex-wrap items-center gap-2 pt-2">
                        <template v-if="profile_user.is_owner">
                            <Dialog v-model:open="editOpen">
                                <DialogTrigger as-child>
                                    <Button size="sm" class="rounded-lg">
                                        <Pencil class="size-4" />
                                        <span>{{
                                            t('profile.editProfile')
                                        }}</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent class="max-w-lg">
                                    <DialogHeader>
                                        <DialogTitle>
                                            {{ t('profile.editProfile') }}
                                        </DialogTitle>
                                    </DialogHeader>
                                    <form
                                        @submit.prevent="submitProfileDetails"
                                        class="space-y-4"
                                    >
                                        <div class="grid gap-1.5">
                                            <Label for="headline">{{
                                                t('profile.headline')
                                            }}</Label>
                                            <Input
                                                id="headline"
                                                v-model="profileForm.headline"
                                                maxlength="120"
                                            />
                                            <InputError
                                                :message="
                                                    profileForm.errors.headline
                                                "
                                            />
                                        </div>

                                        <div class="grid gap-1.5">
                                            <Label for="bio">{{
                                                t('profile.bio')
                                            }}</Label>
                                            <textarea
                                                id="bio"
                                                v-model="profileForm.bio"
                                                rows="3"
                                                maxlength="1000"
                                                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                                            />
                                            <InputError
                                                :message="
                                                    profileForm.errors.bio
                                                "
                                            />
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div class="grid gap-1.5">
                                                <Label for="location">{{
                                                    t('profile.location')
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
                                                <Label for="website_url">{{
                                                    t('profile.website')
                                                }}</Label>
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
                                                        profileForm.errors
                                                            .website_url
                                                    "
                                                />
                                            </div>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div class="grid gap-1.5">
                                                <Label for="avatar">{{
                                                    t('profile.avatar')
                                                }}</Label>
                                                <input
                                                    id="avatar"
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    class="text-xs"
                                                    @change="
                                                        setFile(
                                                            'avatar',
                                                            $event,
                                                        )
                                                    "
                                                />
                                                <InputError
                                                    :message="
                                                        profileForm.errors
                                                            .avatar
                                                    "
                                                />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <Label for="cover">{{
                                                    t('profile.cover')
                                                }}</Label>
                                                <input
                                                    id="cover"
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    class="text-xs"
                                                    @change="
                                                        setFile('cover', $event)
                                                    "
                                                />
                                                <InputError
                                                    :message="
                                                        profileForm.errors.cover
                                                    "
                                                />
                                            </div>
                                        </div>

                                        <DialogFooter class="gap-2">
                                            <DialogClose as-child>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    class="rounded-lg"
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
                                    profile_user.is_following
                                        ? 'outline'
                                        : 'default'
                                "
                                class="rounded-lg"
                                @click="handleFollow"
                            >
                                <UserMinus
                                    v-if="profile_user.is_following"
                                    class="size-4"
                                />
                                <UserPlus v-else class="size-4" />
                                <span>
                                    {{
                                        profile_user.is_following
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
                        <PremiumBadge
                            v-if="profile_user.is_premium"
                            size="md"
                        />
                        <BadgeCheck
                            v-if="profile_user.is_verified"
                            class="size-5 text-primary"
                            aria-hidden="true"
                        />
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
                            {{
                                new Date(
                                    profile_user.joined_at,
                                ).toLocaleDateString()
                            }}
                        </span>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-5 pt-2 text-xs font-medium text-foreground"
                    >
                        <span>
                            {{
                                t('profile.followers', {
                                    count: profile_user.followers_count,
                                })
                            }}
                        </span>
                        <span>
                            {{
                                t('profile.followingCount', {
                                    count: profile_user.following_count,
                                })
                            }}
                        </span>
                        <span>
                            {{
                                t('profile.postsCount', {
                                    count: profile_user.posts_count,
                                })
                            }}
                        </span>
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
            <PostCard v-for="post in bookmarks" :key="post.id" :post="post" />
            <EmptyState
                v-if="bookmarks.length === 0"
                :icon="Bookmark"
                :title="t('profile.bookmarksTab')"
                :description="t('profile.noBookmarks')"
            />
        </section>

        <section v-else class="space-y-4">
            <PostCard v-for="post in posts" :key="post.id" :post="post" />
            <EmptyState
                v-if="posts.length === 0"
                :icon="Newspaper"
                :title="t('profile.postsTab')"
                :description="t('profile.noPosts')"
            />
        </section>
    </div>
</template>
