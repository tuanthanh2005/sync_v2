<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    BarChart2,
    BriefcaseBusiness,
    ChevronDown,
    Cpu,
    Globe,
    Hash,
    Image as ImageIcon,
    LayoutGrid,
    Link2,
    Lock,
    MessageCircleQuestion,
    Plus,
    ShoppingBag,
    Smile,
    Trash2,
    UserRound,
    Video,
    Wallet,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import PostController from '@/actions/App/Http/Controllers/Social/PostController';
import AiBadge from '@/components/ai/AiBadge.vue';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import PremiumBadge from '@/components/monetization/PremiumBadge.vue';
import ImageUploader from '@/components/upload/ImageUploader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { useMobileComposer } from '@/composables/useMobileComposer';
import { appToast } from '@/lib/toast';
import { login } from '@/routes';
import type { CategoryOption, FeedLinkMeta } from '@/types';

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

const QUICK_EMOJIS = [
    '😀',
    '🎉',
    '🔥',
    '💡',
    '🚀',
    '👏',
    '❤️',
    '🙌',
    '👍',
    '🤔',
    '📈',
    '💻',
    '🌍',
    '✅',
    '⭐',
    '📌',
];

const CATEGORY_ICON_MAP: Record<string, Component> = {
    wallet: Wallet,
    cpu: Cpu,
    'briefcase-business': BriefcaseBusiness,
    'message-circle-question': MessageCircleQuestion,
    'shopping-bag': ShoppingBag,
    'layout-grid': LayoutGrid,
    finance: Wallet,
    technology: Cpu,
    business: BriefcaseBusiness,
    qa: MessageCircleQuestion,
    deals: ShoppingBag,
    general: LayoutGrid,
};

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const modalOpen = ref(false);
const discardDialogOpen = ref(false);
const showImages = ref(false);
const showPoll = ref(false);
const showLink = ref(false);
const showEmojiPicker = ref(false);
const imageUploaderRef = ref<InstanceType<typeof ImageUploader> | null>(null);
const contentTextareaRef = ref<HTMLTextAreaElement | null>(null);
const linkPreview = ref<FeedLinkMeta | null>(null);

const { mobileComposerOpen, mobileComposerNonce, closeMobileComposer } =
    useMobileComposer();

const maxPostLength = computed<number>(
    () => page.props.systemSettings?.limits?.postMaxLength ?? 3000,
);
const maxPostImages = computed<number>(
    () => page.props.systemSettings?.limits?.postMaxImages ?? 4,
);

const draftStorageKey = computed(
    () => `syncviet_post_draft_${props.communityId ?? 'feed'}`,
);

const form = useForm<{
    title: string;
    content: string;
    category: string;
    visibility: 'public' | 'private';
    link_url: string;
    images: File[];
    image_alts: string[];
    poll_question: string;
    poll_options: string[];
    poll_duration_hours: number;
}>({
    title: '',
    content: '',
    category: props.defaultCategory,
    visibility: 'public',
    link_url: '',
    images: [],
    image_alts: [],
    poll_question: '',
    poll_options: ['', ''],
    poll_duration_hours: 72,
});

const previewHttp = useHttp<{ url: string }, { preview: FeedLinkMeta | null }>({
    url: '',
});

const activeCategoryIcon = computed<Component>(() => {
    const selected = props.categories.find(
        (cat) => cat.value === form.category,
    );
    const key = selected?.icon || form.category;

    return CATEGORY_ICON_MAP[key] ?? LayoutGrid;
});

const hasUnsavedDraft = computed<boolean>(
    () =>
        form.title.trim().length > 0 ||
        form.content.trim().length > 0 ||
        form.images.length > 0 ||
        form.link_url.trim().length > 0 ||
        form.poll_question.trim().length > 0,
);

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

const isOverCharLimit = computed<boolean>(
    () => form.content.length > maxPostLength.value,
);
const isNearCharLimit = computed<boolean>(
    () =>
        !isOverCharLimit.value &&
        form.content.length >= Math.floor(maxPostLength.value * 0.9),
);

const canSubmit = computed<boolean>(
    () =>
        !form.processing &&
        form.content.trim().length > 0 &&
        !isOverCharLimit.value,
);

function adjustTextareaHeight(): void {
    const el = contentTextareaRef.value;
    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.max(152, el.scrollHeight)}px`;
}

function persistDraft(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        // Never persist private posts in browser storage.
        if (!hasUnsavedDraft.value || form.visibility === 'private') {
            window.sessionStorage.removeItem(draftStorageKey.value);
            return;
        }

        window.sessionStorage.setItem(
            draftStorageKey.value,
            JSON.stringify({
                title: form.title,
                content: form.content,
                category: form.category,
                link_url: form.link_url,
                poll_question: form.poll_question,
                poll_options: form.poll_options,
            }),
        );
    } catch {
        // Ignore storage quota errors
    }
}

function clearStoredDraft(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(draftStorageKey.value);
    } catch {
        // Ignore storage errors
    }
}

function handleBeforeUnload(event: BeforeUnloadEvent): void {
    if (hasUnsavedDraft.value && modalOpen.value && !form.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
}

function openComposer(initialAction?: 'image' | 'poll' | 'link'): boolean {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return false;
    }

    modalOpen.value = true;

    if (initialAction === 'image') {
        showImages.value = true;
        void nextTick(() => {
            adjustTextareaHeight();
            imageUploaderRef.value?.openPicker();
        });
    } else if (initialAction === 'poll') {
        showPoll.value = true;
        void nextTick(() => {
            adjustTextareaHeight();
        });
    } else if (initialAction === 'link') {
        showLink.value = true;
        void nextTick(() => {
            adjustTextareaHeight();
        });
    } else {
        void nextTick(() => {
            adjustTextareaHeight();
            contentTextareaRef.value?.focus({ preventScroll: true });
        });
    }

    return true;
}

function handleModalOpenChange(nextOpen: boolean): void {
    if (nextOpen) {
        modalOpen.value = true;
        void nextTick(() => adjustTextareaHeight());
        return;
    }

    if (form.processing) {
        return;
    }

    if (hasUnsavedDraft.value) {
        discardDialogOpen.value = true;
        return;
    }

    modalOpen.value = false;
    showEmojiPicker.value = false;
    closeMobileComposer();
}

function confirmDiscardDraft(): void {
    discardDialogOpen.value = false;
    clearStoredDraft();
    resetComposer();
}

function keepEditingDraft(): void {
    discardDialogOpen.value = false;
    modalOpen.value = true;
}

function insertEmoji(emoji: string): void {
    form.content = `${form.content}${emoji}`;
    showEmojiPicker.value = false;
    void nextTick(() => {
        adjustTextareaHeight();
        contentTextareaRef.value?.focus({ preventScroll: true });
    });
}

function insertHashtagPrefix(): void {
    const needsSpace = form.content.length > 0 && !/\s$/.test(form.content);
    form.content = `${form.content}${needsSpace ? ' ' : ''}#`;
    void nextTick(() => {
        adjustTextareaHeight();
        contentTextareaRef.value?.focus({ preventScroll: true });
    });
}

function notifyVideoComingSoon(): void {
    appToast.info(t('composer.videoComingSoonTooltip'));
}

function toggleImages(): void {
    showImages.value = !showImages.value;
    if (showImages.value && form.images.length === 0) {
        void nextTick(() => {
            imageUploaderRef.value?.openPicker();
        });
    }
}

function togglePoll(): void {
    showPoll.value = !showPoll.value;

    if (!showPoll.value) {
        form.poll_question = '';
        form.poll_options = ['', ''];
    }
}

function addPollOption(): void {
    if (form.poll_options.length < 5) {
        form.poll_options.push('');
    }
}

function removePollOption(index: number): void {
    if (form.poll_options.length > 2) {
        form.poll_options.splice(index, 1);
    }
}

function toggleLink(): void {
    showLink.value = !showLink.value;

    if (!showLink.value) {
        form.link_url = '';
        linkPreview.value = null;
    }
}

function fetchPreview(): void {
    const targetUrl = form.link_url.trim();

    if (!targetUrl) {
        return;
    }

    previewHttp.url = targetUrl;
    previewHttp.post(PostController.previewLink.url(), {
        onSuccess: (response) => {
            linkPreview.value = response.preview;
        },
        onError: () => {
            linkPreview.value = null;
            appToast.error(t('home.composer.fetchPreview'));
        },
    });
}

function cancelActiveUpload(): void {
    form.cancel();
    appToast.info(t('upload.uploadCancelled'));
}

function resetComposer(): void {
    form.reset();
    form.category = props.defaultCategory;
    form.visibility = 'public';
    form.images = [];
    form.image_alts = [];
    form.poll_options = ['', ''];
    form.clearErrors();
    clearStoredDraft();
    showImages.value = false;
    showPoll.value = false;
    showLink.value = false;
    showEmojiPicker.value = false;
    linkPreview.value = null;
    modalOpen.value = false;
    closeMobileComposer();
}

function submitPost(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => {
        const cleanedOptions = showPoll.value
            ? data.poll_options.map((opt) => opt.trim()).filter(Boolean)
            : [];

        return {
            title: data.title.trim() || null,
            content: data.content,
            community_id: props.communityId ?? null,
            category: data.category,
            visibility: data.visibility,
            link_url:
                showLink.value && data.link_url.trim() !== ''
                    ? data.link_url.trim()
                    : null,
            images: data.images,
            image_alts: data.images.map((_, idx) => data.image_alts[idx] ?? ''),
            poll_question:
                showPoll.value && data.poll_question.trim() !== ''
                    ? data.poll_question.trim()
                    : null,
            poll_options: cleanedOptions.length >= 2 ? cleanedOptions : null,
            poll_duration_hours: showPoll.value
                ? data.poll_duration_hours
                : null,
        };
    }).post(PostController.store.url(), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            resetComposer();
        },
    });
}

watch(
    () => [mobileComposerOpen.value, mobileComposerNonce.value],
    ([isOpen]) => {
        if (isOpen) {
            openComposer();
        }
    },
);

watch(
    () => form.content,
    () => {
        void nextTick(() => adjustTextareaHeight());
    },
);

watch(
    () => [
        form.title,
        form.content,
        form.category,
        form.visibility,
        form.link_url,
        form.poll_question,
    ],
    () => {
        persistDraft();
    },
);

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const raw = window.sessionStorage.getItem(draftStorageKey.value);
        if (raw) {
            const parsed = JSON.parse(raw) as Partial<{
                title: string;
                content: string;
                category: string;
                link_url: string;
                poll_question: string;
                poll_options: string[];
            }>;

            if (parsed.title) {
                form.title = parsed.title;
            }
            if (parsed.content) {
                form.content = parsed.content;
            }
            if (parsed.category) {
                form.category = parsed.category;
            }
            if (parsed.link_url) {
                form.link_url = parsed.link_url;
                showLink.value = true;
            }
            if (parsed.poll_question) {
                form.poll_question = parsed.poll_question;
                showPoll.value = true;
            }
            if (
                Array.isArray(parsed.poll_options) &&
                parsed.poll_options.length >= 2
            ) {
                form.poll_options = parsed.poll_options;
            }
        }
    } catch {
        // Ignore malformed draft
    }

    if (mobileComposerOpen.value) {
        openComposer();
    }

    window.addEventListener('beforeunload', handleBeforeUnload);
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', handleBeforeUnload);
    }
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
                @click="openComposer()"
            >
                <span class="block truncate">{{
                    hasUnsavedDraft
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
                    @click="openComposer('image')"
                >
                    <ImageIcon class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addImage') }}</span>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-9 gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    @click="openComposer('poll')"
                >
                    <BarChart2 class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addPoll') }}</span>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-9 gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                    @click="openComposer('link')"
                >
                    <Link2 class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('home.composer.addLink') }}</span>
                </Button>
            </div>

            <Button
                type="button"
                size="sm"
                class="hidden h-8 rounded-lg px-3.5 text-xs font-semibold sm:inline-flex"
                @click="openComposer()"
            >
                {{ t('home.composer.submit') }}
            </Button>
        </div>

        <!-- Create Post Modal (Desktop centered 640px, Mobile full-screen composer) -->
        <Dialog :open="modalOpen" @update:open="handleModalOpenChange">
            <DialogContent
                structured
                :show-close-button="false"
                class="h-[100dvh] max-h-[100dvh] w-full max-w-none rounded-none border-0 bg-card shadow-xl sm:h-auto sm:max-h-[88dvh] sm:w-[calc(100%-2rem)] sm:max-w-[640px] sm:rounded-2xl sm:border sm:border-border"
            >
                <!-- Modal Header: Flexbox single row, title & close button vertically aligned -->
                <div
                    class="flex min-h-16 shrink-0 items-center justify-between gap-4 border-b border-border bg-card px-4 pt-[calc(1rem+env(safe-area-inset-top,0px))] pb-4 sm:px-6 sm:py-4"
                >
                    <DialogHeader class="min-w-0 flex-1 gap-0 text-start">
                        <DialogTitle
                            class="truncate text-[20px] leading-7 font-semibold tracking-tight text-foreground"
                        >
                            {{ t('composer.modal.title') }}
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            {{ personalizedPlaceholder }}
                        </DialogDescription>
                    </DialogHeader>

                    <DialogClose as-child>
                        <button
                            type="button"
                            data-test="composer-close"
                            :disabled="form.processing"
                            :aria-label="t('common.close')"
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                        >
                            <X class="size-5" aria-hidden="true" />
                        </button>
                    </DialogClose>
                </div>

                <!-- Form wraps Body + Footer inside flex-col container -->
                <form
                    id="create-post-modal-form"
                    class="flex min-h-0 flex-1 flex-col bg-card"
                    @submit.prevent="submitPost"
                >
                    <!-- Upload Progress Bar -->
                    <div
                        v-if="form.processing && form.progress"
                        class="h-1 w-full shrink-0 overflow-hidden bg-muted"
                        role="progressbar"
                        :aria-valuenow="form.progress.percentage ?? 0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full bg-primary transition-[width] duration-150"
                            :style="{ width: `${form.progress.percentage}%` }"
                        />
                    </div>

                    <!-- Scrollable Modal Body (only this region scrolls, 4px auto-hide scrollbar) -->
                    <AutoHideScrollArea
                        class="min-h-0 flex-1 bg-card px-4 py-5 sm:px-6"
                    >
                        <div class="space-y-4">
                            <!-- Author Info + Compact Visibility & Category Chips -->
                            <div v-if="user" class="flex items-start gap-3">
                                <Avatar
                                    class="size-11 shrink-0 rounded-full ring-1 ring-border/70"
                                >
                                    <AvatarImage
                                        v-if="user.avatar"
                                        :src="user.avatar"
                                        :alt="user.name"
                                    />
                                    <AvatarFallback
                                        class="bg-primary-soft text-sm font-semibold text-accent-foreground"
                                    >
                                        {{ getInitials(user.name) }}
                                    </AvatarFallback>
                                </Avatar>

                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5 text-[15px] leading-snug font-semibold text-foreground"
                                    >
                                        <span class="truncate">{{
                                            user.name
                                        }}</span>
                                        <PremiumBadge
                                            v-if="
                                                user.is_premium &&
                                                !user.is_ai_agent
                                            "
                                            size="sm"
                                        />
                                        <AiBadge
                                            v-if="user.is_ai_agent"
                                            size="sm"
                                        />
                                    </div>

                                    <!-- Compact Chips below Author Name -->
                                    <div
                                        class="mt-1.5 flex flex-wrap items-center gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="inline-flex h-7 items-center gap-1.5 rounded-full border border-border/80 bg-muted/55 px-2.5 text-xs font-medium text-foreground transition-colors hover:border-border hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-primary/35 focus-visible:outline-none"
                                            @click="
                                                form.visibility =
                                                    form.visibility === 'public'
                                                        ? 'private'
                                                        : 'public'
                                            "
                                        >
                                            <Globe
                                                v-if="
                                                    form.visibility === 'public'
                                                "
                                                class="size-3.5 shrink-0 text-primary"
                                                aria-hidden="true"
                                            />
                                            <Lock
                                                v-else
                                                class="size-3.5 shrink-0 text-warning"
                                                aria-hidden="true"
                                            />
                                            <span>
                                                {{
                                                    form.visibility === 'public'
                                                        ? t(
                                                              'home.composer.visibilityPublic',
                                                          )
                                                        : t(
                                                              'home.composer.visibilityPrivate',
                                                          )
                                                }}
                                            </span>
                                        </button>

                                        <div
                                            v-if="categories.length > 0"
                                            class="relative inline-flex h-7 items-center rounded-full border border-border/80 bg-muted/55 text-xs font-medium text-foreground transition-colors focus-within:ring-2 focus-within:ring-primary/35 hover:border-border hover:bg-surface-hover"
                                        >
                                            <component
                                                :is="activeCategoryIcon"
                                                class="pointer-events-none ms-2.5 size-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <select
                                                v-model="form.category"
                                                :aria-label="
                                                    t(
                                                        'home.composer.categoryLabel',
                                                    )
                                                "
                                                class="h-full cursor-pointer appearance-none bg-transparent ps-1.5 pe-7 text-xs font-medium text-foreground outline-none dark:bg-card"
                                            >
                                                <option
                                                    v-for="cat in categories"
                                                    :key="cat.value"
                                                    :value="cat.value"
                                                >
                                                    {{ cat.label }}
                                                </option>
                                            </select>
                                            <ChevronDown
                                                class="pointer-events-none absolute end-2 size-3.5 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Optional Post Title Input (44px height, 10px radius, subtle focus) -->
                            <div class="space-y-1">
                                <input
                                    v-model="form.title"
                                    type="text"
                                    maxlength="180"
                                    :placeholder="
                                        t('home.composer.titlePlaceholder')
                                    "
                                    class="h-11 w-full min-w-0 rounded-[10px] border border-border bg-card px-3.5 text-[15px] font-medium text-foreground transition-colors placeholder:font-normal placeholder:text-muted-foreground/80 hover:border-input focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/15 focus-visible:outline-none"
                                />
                                <InputError :message="form.errors.title" />
                            </div>

                            <!-- Clean Social Content Editor (auto-resizing, unified surface, subtle focus) -->
                            <div class="space-y-1.5">
                                <div
                                    class="rounded-xl border border-border/65 bg-card transition-colors focus-within:border-primary/60 focus-within:ring-2 focus-within:ring-primary/12"
                                >
                                    <textarea
                                        ref="contentTextareaRef"
                                        v-model="form.content"
                                        rows="5"
                                        required
                                        :maxlength="maxPostLength"
                                        data-test="composer-content"
                                        :placeholder="
                                            t(
                                                'home.composer.contentPlaceholder',
                                            )
                                        "
                                        class="block min-h-[152px] w-full resize-none overflow-hidden bg-transparent px-3.5 pt-3 pb-2 text-base leading-[1.6] text-foreground placeholder:text-muted-foreground/75 focus:outline-none"
                                        @input="adjustTextareaHeight"
                                    />
                                    <div
                                        class="flex items-center justify-end px-3.5 pb-2.5"
                                    >
                                        <span
                                            class="text-xs leading-none tabular-nums transition-colors"
                                            :class="
                                                isOverCharLimit
                                                    ? 'font-semibold text-destructive'
                                                    : isNearCharLimit
                                                      ? 'font-medium text-warning'
                                                      : 'text-muted-foreground'
                                            "
                                        >
                                            {{ form.content.length }} /
                                            {{ maxPostLength }}
                                        </span>
                                    </div>
                                </div>
                                <InputError :message="form.errors.content" />
                            </div>

                            <!-- Emoji Picker Strip -->
                            <div
                                v-if="showEmojiPicker"
                                class="flex flex-wrap items-center gap-1 rounded-xl border border-border/80 bg-muted/35 p-2"
                            >
                                <button
                                    v-for="emoji in QUICK_EMOJIS"
                                    :key="emoji"
                                    type="button"
                                    class="flex size-8 items-center justify-center rounded-lg text-base transition-transform hover:scale-110 hover:bg-card focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                                    @click="insertEmoji(emoji)"
                                >
                                    {{ emoji }}
                                </button>
                            </div>

                            <!-- Shared Multi-Image Uploader (Device Only, < 40 MB) -->
                            <div v-if="showImages || form.images.length > 0">
                                <ImageUploader
                                    ref="imageUploaderRef"
                                    :model-value="form.images"
                                    :alts="form.image_alts"
                                    multiple
                                    :max-files="maxPostImages"
                                    context="post"
                                    allow-alt-text
                                    allow-reorder
                                    :uploading="form.processing"
                                    :progress="
                                        form.progress?.percentage ?? null
                                    "
                                    :external-error="
                                        form.errors.images ||
                                        (form.errors as Record<string, string>)[
                                            'images.0'
                                        ] ||
                                        null
                                    "
                                    @update:model-value="
                                        (val) =>
                                            (form.images = Array.isArray(val)
                                                ? val
                                                : [])
                                    "
                                    @update:alts="
                                        (val) => (form.image_alts = val)
                                    "
                                    @cancel="cancelActiveUpload"
                                    @retry="submitPost"
                                />
                            </div>

                            <!-- Share Link Box (Normal Web Link Sharing — kept intact) -->
                            <div
                                v-if="showLink"
                                class="space-y-2.5 rounded-xl border border-border/80 bg-muted/25 p-3.5"
                            >
                                <div class="flex flex-wrap gap-2">
                                    <Input
                                        v-model="form.link_url"
                                        type="url"
                                        :placeholder="
                                            t(
                                                'home.composer.linkUrlPlaceholder',
                                            )
                                        "
                                        class="h-10 min-w-0 flex-1 rounded-lg bg-card"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        class="h-10 rounded-lg px-3.5 text-xs"
                                        :disabled="
                                            previewHttp.processing ||
                                            !form.link_url.trim()
                                        "
                                        @click="fetchPreview"
                                    >
                                        <Spinner
                                            v-if="previewHttp.processing"
                                        />
                                        <span>{{
                                            t('home.composer.fetchPreview')
                                        }}</span>
                                    </Button>
                                </div>
                                <InputError :message="form.errors.link_url" />

                                <div
                                    v-if="linkPreview"
                                    class="flex items-start gap-3 rounded-lg border border-border bg-card p-3 text-xs"
                                >
                                    <img
                                        v-if="linkPreview.image"
                                        :src="linkPreview.image"
                                        alt=""
                                        class="size-14 shrink-0 rounded-md object-cover"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="text-[11px] font-medium text-primary"
                                        >
                                            {{ linkPreview.domain }}
                                        </p>
                                        <p
                                            class="truncate font-semibold text-foreground"
                                        >
                                            {{ linkPreview.title }}
                                        </p>
                                        <p
                                            v-if="linkPreview.description"
                                            class="line-clamp-2 text-muted-foreground"
                                        >
                                            {{ linkPreview.description }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Poll Builder -->
                            <div
                                v-if="showPoll"
                                class="space-y-2.5 rounded-xl border border-border/80 bg-muted/25 p-3.5"
                            >
                                <Input
                                    v-model="form.poll_question"
                                    type="text"
                                    maxlength="255"
                                    :placeholder="
                                        t(
                                            'home.composer.pollQuestionPlaceholder',
                                        )
                                    "
                                    class="h-10 rounded-lg bg-card"
                                />
                                <InputError
                                    :message="form.errors.poll_question"
                                />

                                <div class="space-y-2">
                                    <div
                                        v-for="(_, index) in form.poll_options"
                                        :key="index"
                                        class="flex items-center gap-2"
                                    >
                                        <Input
                                            v-model="form.poll_options[index]"
                                            type="text"
                                            maxlength="120"
                                            :placeholder="
                                                t(
                                                    'home.composer.pollOptionPlaceholder',
                                                    { index: index + 1 },
                                                )
                                            "
                                            class="h-10 rounded-lg bg-card"
                                        />
                                        <Button
                                            v-if="form.poll_options.length > 2"
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-9 shrink-0 rounded-lg text-muted-foreground hover:text-destructive"
                                            :aria-label="
                                                t('upload.removeImage')
                                            "
                                            @click="removePollOption(index)"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </div>
                                <InputError
                                    :message="form.errors.poll_options"
                                />

                                <div
                                    class="flex items-center justify-between pt-1"
                                >
                                    <Button
                                        v-if="form.poll_options.length < 5"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="h-8 rounded-lg text-xs"
                                        @click="addPollOption"
                                    >
                                        <Plus class="size-3.5" />
                                        <span>{{
                                            t('home.composer.addOption')
                                        }}</span>
                                    </Button>
                                    <select
                                        v-model.number="
                                            form.poll_duration_hours
                                        "
                                        :aria-label="t('home.composer.addPoll')"
                                        class="h-8 rounded-lg border border-input bg-card px-2.5 text-xs text-foreground"
                                    >
                                        <option :value="24">
                                            {{ t('post.extra.duration24h') }}
                                        </option>
                                        <option :value="72">
                                            {{ t('post.extra.duration3d') }}
                                        </option>
                                        <option :value="168">
                                            {{ t('post.extra.duration7d') }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Balanced Attachment Toolbar (Consistent 18-20px icons, zero nested border clutter) -->
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/80 bg-card px-3.5 py-2.5"
                            >
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    {{ t('composer.modal.attachmentsLabel') }}
                                </span>

                                <div class="flex flex-wrap items-center gap-1">
                                    <button
                                        type="button"
                                        :title="t('home.composer.addImage')"
                                        :aria-label="
                                            t('home.composer.addImage')
                                        "
                                        :aria-pressed="
                                            showImages || form.images.length > 0
                                        "
                                        :class="[
                                            'inline-flex size-9 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                                            showImages || form.images.length > 0
                                                ? 'bg-primary-soft text-primary'
                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-primary',
                                        ]"
                                        @click="toggleImages"
                                    >
                                        <ImageIcon
                                            class="size-[19px]"
                                            aria-hidden="true"
                                        />
                                    </button>

                                    <button
                                        type="button"
                                        :title="t('home.composer.addPoll')"
                                        :aria-label="t('home.composer.addPoll')"
                                        :aria-pressed="showPoll"
                                        :class="[
                                            'inline-flex size-9 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                                            showPoll
                                                ? 'bg-primary-soft text-primary'
                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-primary',
                                        ]"
                                        @click="togglePoll"
                                    >
                                        <BarChart2
                                            class="size-[19px]"
                                            aria-hidden="true"
                                        />
                                    </button>

                                    <button
                                        type="button"
                                        :title="t('home.composer.addLink')"
                                        :aria-label="t('home.composer.addLink')"
                                        :aria-pressed="showLink"
                                        :class="[
                                            'inline-flex size-9 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                                            showLink
                                                ? 'bg-primary-soft text-primary'
                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-primary',
                                        ]"
                                        @click="toggleLink"
                                    >
                                        <Link2
                                            class="size-[19px]"
                                            aria-hidden="true"
                                        />
                                    </button>

                                    <button
                                        type="button"
                                        :title="t('composer.modal.insertEmoji')"
                                        :aria-label="
                                            t('composer.modal.insertEmoji')
                                        "
                                        :aria-pressed="showEmojiPicker"
                                        :class="[
                                            'inline-flex size-9 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                                            showEmojiPicker
                                                ? 'bg-primary-soft text-primary'
                                                : 'text-muted-foreground hover:bg-surface-hover hover:text-foreground',
                                        ]"
                                        @click="
                                            showEmojiPicker = !showEmojiPicker
                                        "
                                    >
                                        <Smile
                                            class="size-[19px]"
                                            aria-hidden="true"
                                        />
                                    </button>

                                    <button
                                        type="button"
                                        :title="
                                            t('composer.modal.insertHashtag')
                                        "
                                        :aria-label="
                                            t('composer.modal.insertHashtag')
                                        "
                                        class="inline-flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                                        @click="insertHashtagPrefix"
                                    >
                                        <Hash
                                            class="size-[19px]"
                                            aria-hidden="true"
                                        />
                                    </button>

                                    <span
                                        class="mx-0.5 h-4 w-px bg-border"
                                        aria-hidden="true"
                                    />

                                    <!-- Video Coming Soon (Compact UI indicator only — never opens video picker) -->
                                    <button
                                        type="button"
                                        aria-disabled="true"
                                        data-test="video-coming-soon"
                                        :title="
                                            t('composer.videoComingSoonTooltip')
                                        "
                                        :aria-label="
                                            t('composer.videoComingSoonTooltip')
                                        "
                                        class="inline-flex h-9 cursor-not-allowed items-center gap-1.5 rounded-lg px-2 text-xs text-muted-foreground/75 transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-primary/35 focus-visible:outline-none"
                                        @click="notifyVideoComingSoon"
                                    >
                                        <Video
                                            class="size-[19px] shrink-0 opacity-75"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="hidden rounded-md bg-muted px-1.5 py-0.5 text-[10px] leading-none font-medium text-muted-foreground min-[380px]:inline"
                                        >
                                            {{ t('composer.comingSoon') }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </AutoHideScrollArea>

                    <!-- Stable Modal Footer (never shrinks, generous padding + safe area) -->
                    <DialogFooter
                        class="shrink-0 flex-col-reverse gap-2.5 border-t border-border bg-card px-4 pt-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-6 sm:py-4"
                    >
                        <div
                            class="hidden min-w-0 text-xs text-muted-foreground sm:block"
                        >
                            <span v-if="form.processing && form.progress">
                                {{ t('upload.uploading') }}
                                {{ form.progress.percentage }}%
                            </span>
                            <span v-else-if="form.images.length > 0">
                                {{
                                    t('upload.fileCountProgress', {
                                        current: form.images.length,
                                        max: maxPostImages,
                                    })
                                }}
                            </span>
                        </div>

                        <div
                            class="flex w-full items-center gap-3 sm:w-auto sm:justify-end"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                class="h-11 shrink-0 rounded-xl px-5 text-sm font-medium sm:min-w-[88px]"
                                :disabled="form.processing"
                                @click="handleModalOpenChange(false)"
                            >
                                {{ t('post.cancel') }}
                            </Button>
                            <Button
                                type="submit"
                                data-test="composer-submit"
                                class="h-11 min-w-[128px] flex-1 rounded-xl px-6 text-sm font-semibold sm:flex-none"
                                :disabled="!canSubmit"
                            >
                                <Spinner
                                    v-if="form.processing"
                                    class="size-4"
                                />
                                <span>{{
                                    form.processing
                                        ? t('home.composer.publishing')
                                        : t('home.composer.submit')
                                }}</span>
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Unsaved Draft Confirmation Dialog -->
        <ConfirmActionDialog
            :open="discardDialogOpen"
            :title="t('home.composer.discardDraftTitle')"
            :description="t('home.composer.discardDraftDesc')"
            :confirm-label="t('home.composer.discardDraftConfirm')"
            :cancel-label="t('composer.modal.continueEditing')"
            destructive
            @update:open="
                (val) => {
                    if (!val) keepEditingDraft();
                }
            "
            @confirm="confirmDiscardDraft"
        />
    </section>
</template>
