<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { BarChart2, Image as ImageIcon, Link2, UserRound } from '@lucide/vue';
import { computed, onBeforeUnmount, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import {
    clearComposerContext,
    openCreateComposer,
    registerComposerContext,
    usePostComposer,
    type PostComposerInitialAction,
} from '@/composables/useMobileComposer';
import type { CategoryOption } from '@/types';

const props = withDefaults(
    defineProps<{
        categories?: CategoryOption[];
        defaultCategory?: string;
        communityId?: number | null;
        placeholder?: string;
    }>(),
    {
        categories: () => [],
        defaultCategory: 'general',
        communityId: null,
        placeholder: undefined,
    },
);

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();
const { hasUnsavedCreateDraft } = usePostComposer();

const personalizedPlaceholder = computed<string>(() => {
    if (props.placeholder) {
        return props.placeholder;
    }

    if (user.value?.name) {
        const firstName =
            user.value.name.trim().split(/\s+/)[0] || user.value.name;

        return t('composer.modal.personalizedPlaceholder', { name: firstName });
    }

    return t('home.composer.placeholder');
});

function openModal(initialAction: PostComposerInitialAction = null): void {
    openCreateComposer({
        initialAction,
        categories: props.categories,
        defaultCategory: props.defaultCategory,
        communityId: props.communityId,
    });
}

watchEffect(() => {
    registerComposerContext({
        categories: props.categories,
        defaultCategory: props.defaultCategory,
        communityId: props.communityId,
    });
});

onBeforeUnmount(() => {
    clearComposerContext();
});
</script>

<template>
    <section
        :aria-label="t('accessibility.postComposer')"
        class="rounded-xl border border-border bg-card p-3.5 shadow-xs sm:p-4"
    >
        <!-- Compact Post Composer Trigger (never pushes News Feed down) -->
        <div class="flex items-center gap-3">
            <Avatar v-if="user" class="size-10 shrink-0 rounded-full">
                <AvatarImage
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="user.name"
                />
                <AvatarFallback
                    class="bg-primary-soft text-xs font-semibold text-accent-foreground"
                >
                    {{ getInitials(user.name) }}
                </AvatarFallback>
            </Avatar>
            <div
                v-else
                class="flex size-10 shrink-0 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground"
            >
                <UserRound class="size-5" aria-hidden="true" />
            </div>

            <button
                type="button"
                data-test="composer-trigger"
                class="min-h-11 min-w-0 flex-1 rounded-xl border border-border/70 bg-muted/45 px-4 py-2.5 text-start text-sm text-muted-foreground transition-colors hover:border-primary/35 hover:bg-muted/75 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="openModal()"
            >
                <span class="block truncate">{{
                    hasUnsavedCreateDraft
                        ? t('composer.modal.resumeDraftPrompt')
                        : personalizedPlaceholder
                }}</span>
            </button>
        </div>

        <!-- Compact Action Shortcuts -->
        <div
            class="mt-3 flex flex-wrap items-center justify-between gap-1 border-t border-border/80 pt-2.5"
        >
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-9 gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    @click="openModal('image')"
                >
                    <ImageIcon class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addImage') }}</span>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-9 gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    @click="openModal('poll')"
                >
                    <BarChart2 class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addPoll') }}</span>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-9 gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    @click="openModal('link')"
                >
                    <Link2 class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addLink') }}</span>
                </Button>
            </div>

            <Button
                type="button"
                size="sm"
                class="hidden h-8 rounded-lg px-3.5 text-xs font-semibold sm:inline-flex"
                @click="openModal()"
            >
                {{ t('home.composer.submit') }}
            </Button>
        </div>
    </section>
</template>
