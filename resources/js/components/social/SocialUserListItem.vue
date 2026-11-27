<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Check,
    Heart,
    Lightbulb,
    MapPin,
    ThumbsUp,
    UserMinus,
    UserPlus,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import AiBadge from '@/components/ai/AiBadge.vue';
import MemberBadges from '@/components/growth/MemberBadges.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    isUserFollowed,
    isUserFollowPending,
    toggleUserFollow,
} from '@/composables/useFollowState';
import { useInitials } from '@/composables/useInitials';
import { appToast } from '@/lib/toast';
import { login } from '@/routes';
import type { SocialDirectoryUserItem } from '@/types';

const REACTION_BADGE_MAP: Record<
    'like' | 'love' | 'insightful',
    { icon: Component; bgClass: string; labelKey: string }
> = {
    like: {
        icon: ThumbsUp,
        bgClass: 'bg-primary text-primary-foreground',
        labelKey: 'post.like',
    },
    love: {
        icon: Heart,
        bgClass: 'bg-destructive text-destructive-foreground',
        labelKey: 'post.love',
    },
    insightful: {
        icon: Lightbulb,
        bgClass: 'bg-warning text-warning-foreground',
        labelKey: 'post.insightful',
    },
};

const props = defineProps<{
    item: SocialDirectoryUserItem;
    showReactionBadge?: boolean;
}>();

const emit = defineEmits<{
    navigate: [];
    followChanged: [payload: { userId: number; following: boolean }];
}>();

const { t } = useI18n();
const page = usePage();
const viewer = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const hoveringFollow = ref(false);

const profileHref = computed(() =>
    ProfileViewController.show.url(props.item.username),
);

const isFollowing = computed(() =>
    isUserFollowed(props.item.id, props.item.is_following),
);

const isPending = computed(() => isUserFollowPending(props.item.id));

const reactionBadge = computed(() => {
    if (!props.showReactionBadge || !props.item.reaction_type) {
        return null;
    }

    return REACTION_BADGE_MAP[props.item.reaction_type] ?? null;
});

const subtitleText = computed(() => {
    const headline = props.item.headline?.trim();
    if (headline) {
        return headline;
    }
    const bio = props.item.bio?.trim();
    if (bio) {
        return bio;
    }

    return null;
});

async function handleToggleFollow(): Promise<void> {
    if (!viewer.value) {
        appToast.info(t('home.composer.signInPrompt'));
        emit('navigate');
        router.visit(login());

        return;
    }

    const result = await toggleUserFollow({
        targetUserId: props.item.id,
        targetUsername: props.item.username,
        currentlyFollowing: isFollowing.value,
        viewerUserId: viewer.value.id,
    });

    if (result.ok) {
        emit('followChanged', {
            userId: props.item.id,
            following: result.following,
        });
        if (result.message) {
            appToast.success(result.message);
        }
    } else {
        appToast.error(result.message || t('socialList.followError'));
    }
}
</script>

<template>
    <div
        data-test="social-user-row"
        class="group flex items-center justify-between gap-3 rounded-xl px-2.5 py-2.5 transition-colors hover:bg-surface-hover/80"
    >
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <Link
                :href="profileHref"
                class="relative shrink-0 rounded-full focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                @click="emit('navigate')"
            >
                <Avatar class="size-11 rounded-full border border-border/70">
                    <AvatarImage
                        v-if="item.avatar"
                        :src="item.avatar"
                        :alt="item.name"
                    />
                    <AvatarFallback
                        class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                    >
                        {{ getInitials(item.name) }}
                    </AvatarFallback>
                </Avatar>

                <span
                    v-if="reactionBadge"
                    :title="t(reactionBadge.labelKey)"
                    :aria-label="t(reactionBadge.labelKey)"
                    :class="[
                        'absolute -end-0.5 -bottom-0.5 flex size-5 items-center justify-center rounded-full ring-2 ring-card',
                        reactionBadge.bgClass,
                    ]"
                >
                    <component
                        :is="reactionBadge.icon"
                        class="size-2.5"
                        aria-hidden="true"
                    />
                </span>
            </Link>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <Link
                        :href="profileHref"
                        class="truncate text-sm leading-snug font-semibold text-foreground transition-colors hover:text-primary focus-visible:underline focus-visible:outline-none"
                        @click="emit('navigate')"
                    >
                        {{ item.name }}
                    </Link>

                    <PremiumBadge
                        v-if="item.is_premium && !item.is_ai_agent"
                        size="sm"
                    />
                    <AiBadge
                        v-if="item.is_ai_agent"
                        :disclosure="item.ai_disclosure"
                        size="sm"
                    />
                    <MemberBadges
                        v-if="item.badges?.length"
                        :badges="item.badges"
                        size="sm"
                        :max="1"
                    />
                    <BadgeCheck
                        v-if="item.is_verified"
                        class="size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span
                        v-if="
                            item.role === 'admin' || item.role === 'moderator'
                        "
                        class="inline-flex shrink-0 items-center rounded-full border border-border bg-muted/60 px-1.5 py-0.5 text-[10px] leading-none font-medium text-muted-foreground"
                    >
                        {{ item.role_label }}
                    </span>
                </div>

                <div
                    class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span class="shrink-0 truncate">@{{ item.username }}</span>
                    <template v-if="subtitleText">
                        <span aria-hidden="true">·</span>
                        <span class="truncate">{{ subtitleText }}</span>
                    </template>
                    <template v-else-if="item.location">
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex min-w-0 items-center gap-0.5">
                            <MapPin
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ item.location }}</span>
                        </span>
                    </template>
                </div>
            </div>
        </div>

        <div class="shrink-0">
            <span
                v-if="item.is_self"
                data-test="social-user-self-badge"
                class="inline-flex min-h-8 items-center rounded-lg border border-border bg-muted/50 px-3 text-xs font-medium text-muted-foreground"
            >
                {{ t('socialList.you') }}
            </span>

            <Button
                v-else
                type="button"
                size="sm"
                data-test="social-user-follow-btn"
                :variant="isFollowing ? 'outline' : 'default'"
                :disabled="isPending"
                :aria-label="
                    isFollowing
                        ? t('socialList.unfollowAria', { name: item.name })
                        : t('socialList.followAria', { name: item.name })
                "
                :class="[
                    'min-h-8.5 min-w-[92px] gap-1.5 rounded-lg px-3 text-xs font-medium transition-colors',
                    isFollowing && hoveringFollow
                        ? 'border-destructive/45 bg-destructive-soft/50 text-destructive hover:bg-destructive-soft/70 hover:text-destructive'
                        : '',
                ]"
                @mouseenter="hoveringFollow = true"
                @mouseleave="hoveringFollow = false"
                @focus="hoveringFollow = true"
                @blur="hoveringFollow = false"
                @click="handleToggleFollow"
            >
                <Spinner v-if="isPending" class="size-3.5" />
                <template v-else-if="isFollowing">
                    <UserMinus
                        v-if="hoveringFollow"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <Check
                        v-else
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{
                        hoveringFollow
                            ? t('socialList.unfollow')
                            : t('profile.following')
                    }}</span>
                </template>
                <template v-else>
                    <UserPlus class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ t('profile.follow') }}</span>
                </template>
            </Button>
        </div>
    </div>
</template>
