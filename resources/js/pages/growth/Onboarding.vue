<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Compass, Globe, Sparkles, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import ReferralController from '@/actions/App/Http/Controllers/Growth/ReferralController';
import UserBadges from '@/components/social/UserBadges.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { UnifiedBadgeItem } from '@/types';

const props = defineProps<{
    enabled: boolean;
    progress: {
        current_step: string;
        completed_steps: string[];
        selected_interests: string[];
        selected_locale: string;
        return_to_url: string | null;
        completed_at: string | null;
    };
    emailVerified: boolean;
    categories: Array<{ value: string; label: string }>;
    trendingTopics: Array<{
        value: string;
        label: string;
        posts_count: number;
    }>;
    recommendedCommunities: Array<{
        id: number;
        name: string;
        slug: string;
        category_label: string;
        description: string | null;
        members_count: number;
        is_joined: boolean;
    }>;
    recommendedAccounts: Array<{
        id: number;
        name: string;
        username: string;
        headline: string | null;
        is_ai_agent: boolean;
        is_premium?: boolean;
        ai_disclosure: string | null;
        compact_badges?: UnifiedBadgeItem[];
    }>;
    languages: Array<{ code: string; native_name: string }>;
}>();

const { t } = useI18n();

const form = useForm({
    selected_locale: props.progress.selected_locale || 'en',
    selected_interests: [...props.progress.selected_interests],
    complete: false,
    skip: false,
});

function toggleInterest(val: string): void {
    const idx = form.selected_interests.indexOf(val);
    if (idx >= 0) {
        form.selected_interests.splice(idx, 1);
    } else if (form.selected_interests.length < 12) {
        form.selected_interests.push(val);
    }
}

function submitOnboarding(mode: 'complete' | 'skip'): void {
    form.complete = mode === 'complete';
    form.skip = mode === 'skip';
    form.post(ReferralController.updateOnboarding.url());
}

function joinCommunity(slug: string): void {
    router.post(
        CommunityController.toggleMembership.url(slug),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Head :title="t('growth.onboarding.pageTitle')" />

        <section class="rounded-2xl border border-border bg-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-xl font-bold">
                        <Compass class="size-5 text-primary" />
                        {{ t('growth.onboarding.heading') }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('growth.onboarding.subtitle') }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="form.processing"
                    @click="submitOnboarding('skip')"
                >
                    {{ t('growth.onboarding.skip') }}
                </Button>
            </div>

            <!-- Step 1: Preferred Language -->
            <div class="mt-6 space-y-2">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Globe class="size-4 text-primary" />
                    {{ t('growth.onboarding.languageStep') }}
                </h2>
                <select
                    v-model="form.selected_locale"
                    :aria-label="t('growth.onboarding.languageStep')"
                    class="h-10 w-full max-w-xs rounded-lg border border-input bg-background px-3 text-sm"
                >
                    <option
                        v-for="l in languages"
                        :key="l.code"
                        :value="l.code"
                    >
                        {{ l.native_name }} ({{ l.code }})
                    </option>
                </select>
            </div>

            <!-- Step 2: Select Topics from Real Platform Categories & Trending Hashtags -->
            <div class="mt-6 space-y-2.5">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Sparkles class="size-4 text-primary" />
                    {{ t('growth.onboarding.interestsStep') }}
                </h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="cat in categories"
                        :key="cat.value"
                        type="button"
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                            form.selected_interests.includes(cat.value)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-foreground hover:bg-muted',
                        ]"
                        @click="toggleInterest(cat.value)"
                    >
                        <Check
                            v-if="form.selected_interests.includes(cat.value)"
                            class="size-3.5"
                        />
                        <span>{{ cat.label }}</span>
                    </button>
                    <button
                        v-for="tag in trendingTopics"
                        :key="tag.value"
                        type="button"
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                            form.selected_interests.includes(tag.value)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-foreground hover:bg-muted',
                        ]"
                        @click="toggleInterest(tag.value)"
                    >
                        <span>{{ tag.label }}</span>
                    </button>
                </div>
            </div>

            <!-- Step 3: Recommended Public Communities (Explicit Opt-In Join Only!) -->
            <div
                v-if="recommendedCommunities.length > 0"
                class="mt-6 space-y-2.5"
            >
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Users class="size-4 text-primary" />
                    {{ t('growth.onboarding.communitiesStep') }}
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="c in recommendedCommunities"
                        :key="c.id"
                        class="flex items-center justify-between gap-2 rounded-xl border border-border p-3 text-xs"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-foreground">
                                {{ c.name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ c.category_label }} ·
                                {{
                                    t('communities.membersCount', {
                                        count: c.members_count,
                                    })
                                }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :variant="c.is_joined ? 'outline' : 'default'"
                            @click="joinCommunity(c.slug)"
                        >
                            {{
                                c.is_joined
                                    ? t('growth.onboarding.joined')
                                    : t('growth.onboarding.join')
                            }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Recommended Helpful Accounts -->
            <div v-if="recommendedAccounts.length > 0" class="mt-6 space-y-2">
                <h2 class="text-sm font-semibold">
                    {{ t('growth.onboarding.accountsStep') }}
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="u in recommendedAccounts"
                        :key="u.id"
                        :href="`/u/${u.username}`"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5 text-xs hover:bg-muted"
                    >
                        <span class="font-medium">{{ u.name }}</span>
                        <UserBadges
                            :badges="u.compact_badges"
                            mode="compact"
                            size="xs"
                            :max="2"
                            :fallback-is-premium="Boolean(u.is_premium)"
                            :fallback-is-ai="Boolean(u.is_ai_agent)"
                            :fallback-ai-disclosure="u.ai_disclosure"
                        />
                        <Badge v-if="!u.is_ai_agent" variant="outline"
                            >@{{ u.username }}</Badge
                        >
                    </Link>
                </div>
            </div>

            <div
                class="mt-6 flex justify-end gap-2 border-t border-border pt-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="submitOnboarding('skip')"
                >
                    {{ t('growth.onboarding.skip') }}
                </Button>
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="submitOnboarding('complete')"
                >
                    {{ t('growth.onboarding.finish') }}
                </Button>
            </div>
        </section>
    </div>
</template>
