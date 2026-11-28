import { readonly, ref } from 'vue';
import type { CategoryOption, PublicFeedPost } from '@/types';

export type PostComposerMode = 'create' | 'edit';
export type PostComposerInitialAction = 'image' | 'poll' | 'link' | null;

const modalOpen = ref(false);
const modalNonce = ref(0);
const composerMode = ref<PostComposerMode>('create');
const editingPost = ref<PublicFeedPost | null>(null);
const composerCategories = ref<CategoryOption[]>([]);
const composerDefaultCategory = ref<string>('general');
const composerCommunityId = ref<number | null>(null);
const composerInitialAction = ref<PostComposerInitialAction>(null);
const hasUnsavedCreateDraft = ref(false);

const registeredContext = ref<{
    categories: CategoryOption[];
    defaultCategory: string;
    communityId: number | null;
}>({
    categories: [],
    defaultCategory: 'general',
    communityId: null,
});

export function registerComposerContext(ctx: {
    categories?: CategoryOption[];
    defaultCategory?: string;
    communityId?: number | null;
}): void {
    registeredContext.value = {
        categories: ctx.categories ?? [],
        defaultCategory: ctx.defaultCategory ?? 'general',
        communityId: ctx.communityId ?? null,
    };
}

export function clearComposerContext(): void {
    registeredContext.value = {
        categories: [],
        defaultCategory: 'general',
        communityId: null,
    };
}

export function openCreateComposer(options?: {
    initialAction?: PostComposerInitialAction;
    categories?: CategoryOption[];
    defaultCategory?: string;
    communityId?: number | null;
}): void {
    composerMode.value = 'create';
    editingPost.value = null;
    composerCategories.value =
        options?.categories && options.categories.length > 0
            ? options.categories
            : registeredContext.value.categories;
    composerDefaultCategory.value =
        options?.defaultCategory ?? registeredContext.value.defaultCategory;
    composerCommunityId.value =
        options?.communityId !== undefined
            ? options.communityId
            : registeredContext.value.communityId;
    composerInitialAction.value = options?.initialAction ?? null;
    modalOpen.value = true;
    modalNonce.value += 1;
}

export function openEditComposer(
    post: PublicFeedPost,
    categories?: CategoryOption[],
): void {
    composerMode.value = 'edit';
    editingPost.value = post;
    composerCategories.value =
        categories && categories.length > 0
            ? categories
            : registeredContext.value.categories;
    composerDefaultCategory.value = post.category || 'general';
    composerCommunityId.value = post.community?.id ?? null;
    composerInitialAction.value = null;
    modalOpen.value = true;
    modalNonce.value += 1;
}

export function closeComposerModal(): void {
    modalOpen.value = false;
    composerInitialAction.value = null;
}

export function setHasUnsavedCreateDraft(hasDraft: boolean): void {
    hasUnsavedCreateDraft.value = hasDraft;
}

export function usePostComposer() {
    return {
        modalOpen,
        modalNonce: readonly(modalNonce),
        composerMode: readonly(composerMode),
        editingPost: readonly(editingPost),
        composerCategories: readonly(composerCategories),
        composerDefaultCategory: readonly(composerDefaultCategory),
        composerCommunityId: readonly(composerCommunityId),
        composerInitialAction: readonly(composerInitialAction),
        hasUnsavedCreateDraft: readonly(hasUnsavedCreateDraft),
        openCreateComposer,
        openEditComposer,
        closeComposerModal,
        registerComposerContext,
        clearComposerContext,
        setHasUnsavedCreateDraft,
    };
}

export function useMobileComposer() {
    return {
        mobileComposerOpen: modalOpen,
        mobileComposerNonce: modalNonce,
        openMobileComposer: () => openCreateComposer(),
        closeMobileComposer: closeComposerModal,
    };
}
