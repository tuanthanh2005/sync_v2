<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
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
    ShieldCheck,
    ShoppingBag,
    Smile,
    Trash2,
    Users,
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
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import UserBadges from '@/components/social/UserBadges.vue';
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
import { usePostComposer } from '@/composables/useMobileComposer';
import { appToast } from '@/lib/toast';
import { login } from '@/routes';
import type { CategoryOption, FeedLinkMeta } from '@/types';

type EditableMediaItem = {
    id: number;
    url: string;
    mime_type: string;
    alt_text: string;
};

type EditInitialSnapshot = {
    title: string;
    content: string;
    category: string;
    visibility: 'public' | 'community' | 'private';
    link_url: string;
    media_key: string;
};

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
const isRtl = computed(() => Boolean(page.props.isRtl));
const { getInitials } = useInitials();

const {
    modalOpen,
    modalNonce,
    composerMode,
    editingPost,
    composerCategories,
    composerDefaultCategory,
    composerCommunityId,
    composerInitialAction,
    closeComposerModal,
    setHasUnsavedCreateDraft,
} = usePostComposer();

const isEditMode = computed(
    () => composerMode.value === 'edit' && editingPost.value !== null,
);

const effectiveCategories = computed<readonly CategoryOption[]>(() => {
    if (composerCategories.value.length > 0) {
        return composerCategories.value;
    }

    return page.props.postCategories ?? [];
});

const discardDialogOpen = ref(false);
const hasOpenedDiscardDialog = ref(false);
const showImages = ref(false);
const showPoll = ref(false);
const showLink = ref(false);
const showEmojiPicker = ref(false);
const imageUploaderRef = ref<InstanceType<typeof ImageUploader> | null>(null);
const contentTextareaRef = ref<HTMLTextAreaElement | null>(null);
const bodyScrollRef = ref<{ el: HTMLElement | null } | null>(null);
const linkPreview = ref<FeedLinkMeta | null>(null);
const existingMedia = ref<EditableMediaItem[]>([]);
const editSnapshot = ref<EditInitialSnapshot | null>(null);
const mobileViewportStyle = ref<Record<string, string> | undefined>(undefined);

const maxPostLength = computed<number>(
    () =>
        page.props.auth.user?.posting_limits?.max_post_length ??
        page.props.systemSettings?.limits?.postMaxLength ??
        3000,
);
const maxPostImages = computed<number>(
    () =>
        page.props.auth.user?.posting_limits?.max_images_per_post ??
        page.props.systemSettings?.limits?.postMaxImages ??
        4,
);
const remainingNewImageSlots = computed<number>(() =>
    isEditMode.value
        ? Math.max(0, maxPostImages.value - existingMedia.value.length)
        : maxPostImages.value,
);

function generateIdempotencyKey(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return `post-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

const idempotencyKey = ref<string>(generateIdempotencyKey());
const rateLimitWarning = ref<string | null>(null);
const retryCountdownSeconds = ref<number>(0);
let retryCountdownTimer: ReturnType<typeof setInterval> | null = null;
let stopHttpExceptionListener: (() => void) | null = null;

const draftStorageKey = computed(
    () => `syncviet_post_draft_${composerCommunityId.value ?? 'feed'}`,
);

const form = useForm<{
    title: string;
    content: string;
    category: string;
    visibility: 'public' | 'community' | 'private';
    link_url: string;
    images: File[];
    image_alts: string[];
    poll_question: string;
    poll_options: string[];
    poll_duration_hours: number;
}>({
    title: '',
    content: '',
    category: 'general',
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
    const selected = effectiveCategories.value.find(
        (cat) => cat.value === form.category,
    );
    const key = selected?.icon || form.category;

    return CATEGORY_ICON_MAP[key] ?? LayoutGrid;
});

function serializeMediaKey(items: EditableMediaItem[]): string {
    return JSON.stringify(
        items.map((item) => ({
            id: item.id,
            alt_text: item.alt_text.trim(),
        })),
    );
}

const hasUnsavedCreateDraft = computed<boolean>(
    () =>
        !isEditMode.value &&
        (form.title.trim().length > 0 ||
            form.content.trim().length > 0 ||
            form.images.length > 0 ||
            form.link_url.trim().length > 0 ||
            form.poll_question.trim().length > 0),
);

const isEditDirty = computed<boolean>(() => {
    if (!isEditMode.value || !editSnapshot.value) {
        return false;
    }

    const snap = editSnapshot.value;

    return (
        form.title !== snap.title ||
        form.content !== snap.content ||
        form.category !== snap.category ||
        form.visibility !== snap.visibility ||
        form.link_url.trim() !== snap.link_url.trim() ||
        form.images.length > 0 ||
        serializeMediaKey(existingMedia.value) !== snap.media_key
    );
});

const hasUnsavedChanges = computed<boolean>(() =>
    isEditMode.value ? isEditDirty.value : hasUnsavedCreateDraft.value,
);

const personalizedPlaceholder = computed<string>(() => {
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

const canToggleVisibility = computed<boolean>(() => {
    if (isEditMode.value) {
        return !editingPost.value?.community;
    }

    return composerCommunityId.value === null;
});

let resizeRafId: number | null = null;
let focusRafId: number | null = null;
let viewportRafId: number | null = null;
let draftSaveTimer: ReturnType<typeof setTimeout> | null = null;
let lastTextareaLength = 0;

const supportsFieldSizing =
    typeof CSS !== 'undefined' &&
    typeof CSS.supports === 'function' &&
    CSS.supports('field-sizing', 'content');

function adjustTextareaHeight(forceShrink = false): void {
    if (supportsFieldSizing) {
        return;
    }

    const el = contentTextareaRef.value;
    if (!el) {
        return;
    }

    const len = el.value.length;
    const shouldMeasureShrink = forceShrink || len < lastTextareaLength;
    lastTextareaLength = len;

    if (shouldMeasureShrink) {
        const scrollContainer = bodyScrollRef.value?.el ?? null;
        const prevScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;
        el.style.height = 'auto';
        el.style.height = `${Math.max(152, el.scrollHeight)}px`;
        if (
            scrollContainer &&
            Math.abs(scrollContainer.scrollTop - prevScrollTop) > 1
        ) {
            scrollContainer.scrollTop = prevScrollTop;
        }

        return;
    }

    if (el.scrollHeight > el.clientHeight) {
        el.style.height = `${Math.max(152, el.scrollHeight)}px`;
    }
}

function scheduleTextareaHeight(forceShrink = false): void {
    if (supportsFieldSizing || typeof window === 'undefined') {
        return;
    }

    if (forceShrink) {
        if (resizeRafId !== null) {
            window.cancelAnimationFrame(resizeRafId);
            resizeRafId = null;
        }
        void nextTick(() => adjustTextareaHeight(true));

        return;
    }

    if (resizeRafId !== null) {
        return;
    }

    resizeRafId = window.requestAnimationFrame(() => {
        resizeRafId = null;
        adjustTextareaHeight(false);
    });
}

function updateMobileViewportBounds(): void {
    if (typeof window === 'undefined' || !modalOpen.value) {
        mobileViewportStyle.value = undefined;

        return;
    }

    if (window.innerWidth >= 640 || !window.visualViewport) {
        mobileViewportStyle.value = undefined;

        return;
    }

    const vv = window.visualViewport;
    const keyboardDelta = window.innerHeight - vv.height;

    if (keyboardDelta > 80 || vv.offsetTop > 0) {
        const clampedHeight = Math.max(240, Math.round(vv.height));
        mobileViewportStyle.value = {
            top: `${Math.max(0, Math.round(vv.offsetTop))}px`,
            bottom: 'auto',
            height: `${clampedHeight}px`,
            maxHeight: `${clampedHeight}px`,
        };
    } else {
        mobileViewportStyle.value = undefined;
    }
}

function handleVisualViewportChange(): void {
    if (typeof window === 'undefined' || viewportRafId !== null) {
        return;
    }

    viewportRafId = window.requestAnimationFrame(() => {
        viewportRafId = null;
        updateMobileViewportBounds();
    });
}

let viewportListenersActive = false;

function bindViewportListeners(): void {
    if (
        viewportListenersActive ||
        typeof window === 'undefined' ||
        !window.visualViewport
    ) {
        return;
    }

    viewportListenersActive = true;
    window.visualViewport.addEventListener(
        'resize',
        handleVisualViewportChange,
        { passive: true },
    );
    window.visualViewport.addEventListener(
        'scroll',
        handleVisualViewportChange,
        { passive: true },
    );
    updateMobileViewportBounds();
}

function unbindViewportListeners(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (viewportRafId !== null) {
        window.cancelAnimationFrame(viewportRafId);
        viewportRafId = null;
    }

    if (viewportListenersActive && window.visualViewport) {
        window.visualViewport.removeEventListener(
            'resize',
            handleVisualViewportChange,
        );
        window.visualViewport.removeEventListener(
            'scroll',
            handleVisualViewportChange,
        );
    }

    viewportListenersActive = false;
    mobileViewportStyle.value = undefined;
}

function loadCreateDraft(): void {
    form.reset();
    form.clearErrors();
    form.title = '';
    form.content = '';
    form.category = composerDefaultCategory.value || 'general';
    form.visibility = 'public';
    form.link_url = '';
    form.images = [];
    form.image_alts = [];
    form.poll_question = '';
    form.poll_options = ['', ''];
    form.poll_duration_hours = 72;
    existingMedia.value = [];
    editSnapshot.value = null;
    linkPreview.value = null;
    showImages.value = false;
    showPoll.value = false;
    showLink.value = false;
    showEmojiPicker.value = false;

    if (typeof window === 'undefined') {
        return;
    }

    try {
        const raw = window.sessionStorage.getItem(draftStorageKey.value);
        if (!raw) {
            return;
        }
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
    } catch {
        // Ignore malformed draft
    }
}

function hydrateEditState(): void {
    const post = editingPost.value;
    if (!post) {
        return;
    }

    form.clearErrors();
    form.title = post.title ?? '';
    form.content = post.content ?? '';
    form.category = post.category || 'general';
    form.visibility = post.visibility || 'public';
    form.link_url = post.link_url ?? '';
    form.images = [];
    form.image_alts = [];
    form.poll_question = '';
    form.poll_options = ['', ''];
    form.poll_duration_hours = 72;

    existingMedia.value = (post.media ?? []).map((m) => ({
        id: m.id,
        url: m.url,
        mime_type: m.mime_type,
        alt_text: m.alt_text ?? '',
    }));

    linkPreview.value = post.link_meta ?? null;
    showImages.value = existingMedia.value.length > 0;
    showLink.value = Boolean(post.link_url);
    showPoll.value = false;
    showEmojiPicker.value = false;

    editSnapshot.value = {
        title: form.title,
        content: form.content,
        category: form.category,
        visibility: form.visibility,
        link_url: form.link_url,
        media_key: serializeMediaKey(existingMedia.value),
    };
}

function cancelPendingDraftSave(): void {
    if (draftSaveTimer !== null) {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
    }
}

function persistDraft(): void {
    if (isEditMode.value) {
        return;
    }

    setHasUnsavedCreateDraft(hasUnsavedCreateDraft.value);

    if (typeof window === 'undefined') {
        return;
    }

    try {
        // Never persist private posts in browser storage.
        if (!hasUnsavedCreateDraft.value || form.visibility === 'private') {
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

function flushPendingDraftSave(): void {
    cancelPendingDraftSave();
    persistDraft();
}

function schedulePersistDraft(): void {
    if (isEditMode.value) {
        return;
    }
    setHasUnsavedCreateDraft(hasUnsavedCreateDraft.value);
    cancelPendingDraftSave();
    draftSaveTimer = setTimeout(() => {
        draftSaveTimer = null;
        persistDraft();
    }, 350);
}

function clearStoredDraft(): void {
    cancelPendingDraftSave();
    setHasUnsavedCreateDraft(false);
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
    flushPendingDraftSave();
    if (hasUnsavedChanges.value && modalOpen.value && !form.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
}

function initializeModalSession(): void {
    if (!user.value) {
        closeComposerModal();
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    if (isEditMode.value) {
        hydrateEditState();
    } else {
        loadCreateDraft();
        const initialAction = composerInitialAction.value;
        if (initialAction === 'image') {
            showImages.value = true;
            void nextTick(() => {
                imageUploaderRef.value?.openPicker();
            });
        } else if (initialAction === 'poll') {
            showPoll.value = true;
        } else if (initialAction === 'link') {
            showLink.value = true;
        }
    }

    scheduleTextareaHeight(true);
    if (
        typeof window !== 'undefined' &&
        composerInitialAction.value !== 'image'
    ) {
        if (focusRafId !== null) {
            window.cancelAnimationFrame(focusRafId);
        }
        focusRafId = window.requestAnimationFrame(() => {
            focusRafId = null;
            contentTextareaRef.value?.focus({ preventScroll: true });
        });
    }
}

function handleModalOpenChange(nextOpen: boolean): void {
    if (nextOpen) {
        modalOpen.value = true;
        scheduleTextareaHeight(true);

        return;
    }

    if (form.processing) {
        return;
    }

    if (!isEditMode.value) {
        flushPendingDraftSave();
    }

    if (hasUnsavedChanges.value) {
        hasOpenedDiscardDialog.value = true;
        discardDialogOpen.value = true;

        return;
    }

    closeWithoutSaving();
}

function closeWithoutSaving(): void {
    discardDialogOpen.value = false;
    closeComposerModal();
}

function confirmDiscardChanges(): void {
    discardDialogOpen.value = false;
    if (isEditMode.value) {
        form.reset();
        form.clearErrors();
        existingMedia.value = [];
        editSnapshot.value = null;
        linkPreview.value = null;
    } else {
        resetCreateComposer();
    }
    closeComposerModal();
}

function keepEditingChanges(): void {
    discardDialogOpen.value = false;
}

function insertEmoji(emoji: string): void {
    form.content = `${form.content}${emoji}`;
    showEmojiPicker.value = false;
    void nextTick(() => {
        adjustTextareaHeight(true);
        contentTextareaRef.value?.focus({ preventScroll: true });
    });
}

function insertHashtagPrefix(): void {
    const needsSpace = form.content.length > 0 && !/\s$/.test(form.content);
    form.content = `${form.content}${needsSpace ? ' ' : ''}#`;
    void nextTick(() => {
        adjustTextareaHeight(true);
        contentTextareaRef.value?.focus({ preventScroll: true });
    });
}

function toggleImages(): void {
    if (
        showImages.value &&
        form.images.length === 0 &&
        existingMedia.value.length === 0
    ) {
        showImages.value = false;

        return;
    }

    showImages.value = true;
    if (remainingNewImageSlots.value > 0) {
        void nextTick(() => imageUploaderRef.value?.openPicker());
    } else {
        appToast.info(
            t('composer.modal.imageLimitReached', { max: maxPostImages.value }),
        );
    }
}

function moveExistingMedia(index: number, direction: -1 | 1): void {
    const target = index + direction;
    if (target < 0 || target >= existingMedia.value.length) {
        return;
    }

    const next = [...existingMedia.value];
    const [moved] = next.splice(index, 1);
    next.splice(target, 0, moved);
    existingMedia.value = next;
}

function removeExistingMedia(index: number): void {
    existingMedia.value = existingMedia.value.filter((_, idx) => idx !== index);
}

function togglePoll(): void {
    if (isEditMode.value) {
        return;
    }

    showPoll.value = !showPoll.value;
    if (!showPoll.value) {
        form.poll_question = '';
        form.poll_options = ['', ''];
    }
}

function toggleLink(): void {
    showLink.value = !showLink.value;
    if (!showLink.value) {
        form.link_url = '';
        linkPreview.value = null;
    }
}

function notifyVideoComingSoon(): void {
    appToast.info(t('composer.videoComingSoonTooltip'));
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

function fetchPreview(): void {
    if (!form.link_url.trim()) {
        return;
    }

    previewHttp.url = form.link_url.trim();
    previewHttp.post(PostController.previewLink.url(), {
        onSuccess: (data) => {
            linkPreview.value = data.preview ?? null;
        },
        onError: () => {
            linkPreview.value = null;
            appToast.error(
                previewHttp.errors.url || t('home.composer.fetchPreview'),
            );
        },
    });
}

function resetCreateComposer(): void {
    form.reset();
    form.category = composerDefaultCategory.value || 'general';
    form.visibility = 'public';
    form.poll_options = ['', ''];
    form.images = [];
    form.image_alts = [];
    linkPreview.value = null;
    existingMedia.value = [];
    showImages.value = false;
    showPoll.value = false;
    showLink.value = false;
    showEmojiPicker.value = false;
    idempotencyKey.value = generateIdempotencyKey();
    clearRateLimitBanner();
    clearStoredDraft();
    lastTextareaLength = 0;
    void nextTick(() => {
        if (!supportsFieldSizing && contentTextareaRef.value) {
            contentTextareaRef.value.style.height = '152px';
        }
    });
}

function cancelActiveUpload(): void {
    router.cancelAll();
    form.clearErrors();
    appToast.info(t('upload.uploadCancelled'));
}

function clearRateLimitBanner(): void {
    rateLimitWarning.value = null;
    retryCountdownSeconds.value = 0;
    if (retryCountdownTimer !== null) {
        clearInterval(retryCountdownTimer);
        retryCountdownTimer = null;
    }
}

function startRateLimitCountdown(seconds: number, message: string): void {
    clearRateLimitBanner();
    rateLimitWarning.value = message;
    retryCountdownSeconds.value = Math.max(1, Math.ceil(seconds));

    retryCountdownTimer = setInterval(() => {
        if (retryCountdownSeconds.value <= 1) {
            clearRateLimitBanner();

            return;
        }
        retryCountdownSeconds.value -= 1;
    }, 1000);
}

function submitPost(): void {
    if (!user.value) {
        router.visit(login());

        return;
    }

    if (form.processing) {
        return;
    }

    clearRateLimitBanner();

    if (stopHttpExceptionListener !== null) {
        stopHttpExceptionListener();
        stopHttpExceptionListener = null;
    }

    stopHttpExceptionListener = router.on('httpException', (event) => {
        const detail = (event as CustomEvent).detail as {
            response?: {
                status?: number;
                headers?: Record<string, string>;
                data?: { message?: string; retry_after?: number };
            };
        };
        if (detail?.response?.status === 429) {
            const retryAfter = Number(
                detail.response.data?.retry_after ??
                    detail.response.headers?.['retry-after'] ??
                    15,
            );
            const msg =
                detail.response.data?.message ||
                t('home.composer.rateLimitNotice');
            startRateLimitCountdown(
                Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : 15,
                msg,
            );
            if (!isEditMode.value) {
                idempotencyKey.value = generateIdempotencyKey();
            }
        }
    });

    if (isEditMode.value && editingPost.value) {
        const targetPost = editingPost.value;
        form.transform((data) => ({
            _method: 'patch',
            title: data.title.trim() || null,
            content: data.content,
            category: data.category,
            visibility: targetPost.community
                ? targetPost.visibility
                : data.visibility,
            link_synced: 1,
            link_url:
                showLink.value && data.link_url.trim() !== ''
                    ? data.link_url.trim()
                    : null,
            media_synced: 1,
            existing_media: existingMedia.value.map((m) => ({
                id: m.id,
                alt_text: m.alt_text.trim() || null,
            })),
            images: data.images,
            image_alts: data.images.map((_, idx) => data.image_alts[idx] ?? ''),
            expected_updated_at: targetPost.updated_at ?? null,
        })).post(PostController.update.url(targetPost.id), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                clearRateLimitBanner();
                editSnapshot.value = null;
                closeComposerModal();
            },
            onFinish: () => {
                if (stopHttpExceptionListener !== null) {
                    stopHttpExceptionListener();
                    stopHttpExceptionListener = null;
                }
            },
        });

        return;
    }

    form.transform((data) => {
        const cleanedOptions = showPoll.value
            ? data.poll_options.map((opt) => opt.trim()).filter(Boolean)
            : [];

        return {
            title: data.title.trim() || null,
            content: data.content,
            community_id: composerCommunityId.value ?? null,
            category: data.category,
            visibility: data.visibility,
            idempotency_key: idempotencyKey.value,
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
            resetCreateComposer();
            closeComposerModal();
        },
        onError: () => {
            idempotencyKey.value = generateIdempotencyKey();
        },
        onFinish: () => {
            if (stopHttpExceptionListener !== null) {
                stopHttpExceptionListener();
                stopHttpExceptionListener = null;
            }
        },
    });
}

watch(
    () => [modalOpen.value, modalNonce.value],
    ([isOpen]) => {
        if (isOpen) {
            initializeModalSession();
            bindViewportListeners();
        } else {
            unbindViewportListeners();
        }
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
        schedulePersistDraft();
    },
);

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    // Check existing create draft status on load for inline trigger badge.
    try {
        const raw = window.sessionStorage.getItem(draftStorageKey.value);
        setHasUnsavedCreateDraft(Boolean(raw));
    } catch {
        setHasUnsavedCreateDraft(false);
    }

    if (modalOpen.value) {
        initializeModalSession();
        bindViewportListeners();
    }

    window.addEventListener('beforeunload', handleBeforeUnload);
});

onBeforeUnmount(() => {
    flushPendingDraftSave();
    clearRateLimitBanner();
    unbindViewportListeners();
    if (stopHttpExceptionListener !== null) {
        stopHttpExceptionListener();
        stopHttpExceptionListener = null;
    }
    if (typeof window !== 'undefined') {
        if (resizeRafId !== null) {
            window.cancelAnimationFrame(resizeRafId);
            resizeRafId = null;
        }
        if (focusRafId !== null) {
            window.cancelAnimationFrame(focusRafId);
            focusRafId = null;
        }
        window.removeEventListener('beforeunload', handleBeforeUnload);
    }
});
</script>

<template>
    <div>
        <!-- Unified Post Composer Modal (Create & Edit modes, Desktop centered 640px, Mobile full-screen composer) -->
        <Dialog :open="modalOpen" @update:open="handleModalOpenChange">
            <DialogContent
                structured
                :show-close-button="false"
                :style="mobileViewportStyle"
                class="inset-0 h-screen max-h-screen w-full max-w-none translate-x-0 translate-y-0 rounded-none border-0 bg-card shadow-lg supports-[height:100dvh]:h-[100dvh] supports-[height:100dvh]:max-h-[100dvh] sm:inset-auto sm:top-[50%] sm:left-[50%] sm:h-auto sm:max-h-[min(88dvh,calc(100dvh-3rem))] sm:w-[calc(100%-2rem)] sm:max-w-[640px] sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl sm:border sm:border-border"
            >
                <!-- Modal Header: pinned top row, never pushed out or scrolled -->
                <div
                    class="flex min-h-16 shrink-0 items-center justify-between gap-4 border-b border-border bg-card px-4 pt-[calc(1rem+env(safe-area-inset-top,0px))] pb-4 sm:px-6 sm:py-4"
                >
                    <DialogHeader class="min-w-0 flex-1 gap-0 text-start">
                        <DialogTitle
                            data-test="composer-modal-title"
                            class="truncate text-[20px] leading-7 font-semibold tracking-tight text-foreground"
                        >
                            {{
                                isEditMode
                                    ? t('composer.modal.editTitle')
                                    : t('composer.modal.title')
                            }}
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

                <!-- Form constrains child Body + Footer strictly inside modal bounds -->
                <form
                    id="create-post-modal-form"
                    class="flex min-h-0 flex-1 flex-col overflow-hidden bg-card"
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

                    <!-- Scrollable Modal Body (sole vertical scroll container) -->
                    <AutoHideScrollArea
                        ref="bodyScrollRef"
                        data-test="composer-scroll-body"
                        class="min-h-0 flex-1 bg-card px-4 py-5 sm:px-6"
                    >
                        <div class="min-w-0 space-y-4">
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
                                        <UserBadges
                                            :badges="
                                                user.compact_badges ??
                                                user.badges
                                            "
                                            mode="compact"
                                            size="sm"
                                            :max="2"
                                            :interactive="false"
                                            :fallback-is-premium="
                                                Boolean(user.is_premium)
                                            "
                                            :fallback-is-ai="
                                                Boolean(user.is_ai_agent)
                                            "
                                        />
                                    </div>

                                    <!-- Compact Chips below Author Name -->
                                    <div
                                        class="mt-1.5 flex flex-wrap items-center gap-2"
                                    >
                                        <button
                                            v-if="canToggleVisibility"
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
                                        <span
                                            v-else
                                            class="inline-flex h-7 items-center gap-1.5 rounded-full border border-border/80 bg-muted/55 px-2.5 text-xs font-medium text-muted-foreground"
                                        >
                                            <Users
                                                class="size-3.5 shrink-0 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span class="max-w-44 truncate">
                                                {{
                                                    editingPost?.community
                                                        ?.name ||
                                                    t(
                                                        'composer.modal.visibilityCommunity',
                                                    )
                                                }}
                                            </span>
                                        </span>

                                        <div
                                            v-if="
                                                effectiveCategories.length > 0
                                            "
                                            class="relative inline-flex h-7 items-center rounded-full border border-border/80 bg-muted/55 text-xs font-medium text-foreground transition-colors focus-within:ring-2 focus-within:ring-primary/35 hover:border-border hover:bg-surface-hover"
                                        >
                                            <component
                                                :is="activeCategoryIcon"
                                                class="pointer-events-none ms-2.5 size-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <select
                                                v-model="form.category"
                                                data-test="composer-category"
                                                :aria-label="
                                                    t(
                                                        'home.composer.categoryLabel',
                                                    )
                                                "
                                                class="h-full cursor-pointer appearance-none bg-transparent ps-1.5 pe-7 text-xs font-medium text-foreground outline-none dark:bg-card"
                                            >
                                                <option
                                                    v-for="cat in effectiveCategories"
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
                                    <InputError
                                        :message="form.errors.visibility"
                                    />
                                </div>
                            </div>

                            <!-- Inline Rate Limit Notice -->
                            <div
                                v-if="rateLimitWarning"
                                role="alert"
                                class="flex items-start justify-between gap-2 rounded-xl border border-warning/40 bg-warning/10 px-3.5 py-2.5 text-xs text-foreground"
                            >
                                <div class="space-y-0.5">
                                    <p class="font-semibold">
                                        {{ rateLimitWarning }}
                                    </p>
                                    <p
                                        v-if="retryCountdownSeconds > 0"
                                        class="text-[11px] text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            t('home.composer.retryInSeconds', {
                                                seconds: retryCountdownSeconds,
                                            })
                                        }}
                                    </p>
                                </div>
                            </div>

                            <!-- Optional Post Title Input -->
                            <div class="min-w-0 space-y-1">
                                <input
                                    v-model="form.title"
                                    type="text"
                                    maxlength="180"
                                    data-test="composer-title"
                                    :placeholder="
                                        t('home.composer.titlePlaceholder')
                                    "
                                    class="h-11 w-full max-w-full min-w-0 rounded-[10px] border border-border bg-card px-3.5 text-[15px] font-medium text-foreground transition-colors placeholder:font-normal placeholder:text-muted-foreground/80 hover:border-input focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/15 focus-visible:outline-none"
                                />
                                <InputError :message="form.errors.title" />
                            </div>

                            <!-- Post Content Editor -->
                            <div class="min-w-0 space-y-1.5">
                                <div
                                    class="min-w-0 rounded-xl border border-border/65 bg-card transition-colors focus-within:border-primary/60 focus-within:ring-2 focus-within:ring-primary/12"
                                >
                                    <textarea
                                        ref="contentTextareaRef"
                                        v-model="form.content"
                                        rows="5"
                                        required
                                        data-test="composer-content"
                                        :placeholder="
                                            t(
                                                'home.composer.contentPlaceholder',
                                            )
                                        "
                                        class="block [field-sizing:content] min-h-[152px] w-full max-w-full min-w-0 resize-none overflow-hidden bg-transparent px-3.5 pt-3 pb-2 text-base leading-[1.6] [overflow-wrap:anywhere] break-words text-foreground placeholder:text-muted-foreground/75 focus:outline-none"
                                        @input="scheduleTextareaHeight(false)"
                                    />
                                    <div
                                        class="flex items-center justify-end px-3.5 pb-2.5"
                                    >
                                        <span
                                            data-test="composer-char-counter"
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

                            <!-- Existing Post Media Manager (Edit Mode only; committed only upon Save Changes) -->
                            <div
                                v-if="isEditMode && existingMedia.length > 0"
                                class="space-y-2.5 rounded-xl border border-border/80 bg-muted/20 p-3"
                            >
                                <div
                                    class="flex items-center justify-between text-xs font-medium text-muted-foreground"
                                >
                                    <span>
                                        {{
                                            t('composer.modal.existingImages', {
                                                count: existingMedia.length,
                                            })
                                        }}
                                    </span>
                                    <span class="tabular-nums">
                                        {{ existingMedia.length }} /
                                        {{ maxPostImages }}
                                    </span>
                                </div>

                                <div
                                    class="grid grid-cols-1 gap-2.5 sm:grid-cols-2"
                                >
                                    <div
                                        v-for="(item, idx) in existingMedia"
                                        :key="item.id"
                                        data-test="composer-existing-media-item"
                                        class="overflow-hidden rounded-xl border border-border bg-card"
                                    >
                                        <div
                                            class="relative aspect-16/10 bg-muted"
                                        >
                                            <img
                                                :src="item.url"
                                                :alt="item.alt_text || ''"
                                                class="size-full object-cover"
                                            />
                                            <div
                                                class="absolute end-2 top-2 flex items-center gap-1"
                                            >
                                                <button
                                                    v-if="
                                                        existingMedia.length > 1
                                                    "
                                                    type="button"
                                                    :disabled="idx === 0"
                                                    :aria-label="
                                                        t(
                                                            'composer.modal.moveImageEarlier',
                                                        )
                                                    "
                                                    class="flex size-7 items-center justify-center rounded-lg bg-slate-950/70 text-white transition hover:bg-slate-950/90 disabled:opacity-35"
                                                    @click="
                                                        moveExistingMedia(
                                                            idx,
                                                            -1,
                                                        )
                                                    "
                                                >
                                                    <component
                                                        :is="
                                                            isRtl
                                                                ? ArrowRight
                                                                : ArrowLeft
                                                        "
                                                        class="size-3.5"
                                                    />
                                                </button>
                                                <button
                                                    v-if="
                                                        existingMedia.length > 1
                                                    "
                                                    type="button"
                                                    :disabled="
                                                        idx ===
                                                        existingMedia.length - 1
                                                    "
                                                    :aria-label="
                                                        t(
                                                            'composer.modal.moveImageLater',
                                                        )
                                                    "
                                                    class="flex size-7 items-center justify-center rounded-lg bg-slate-950/70 text-white transition hover:bg-slate-950/90 disabled:opacity-35"
                                                    @click="
                                                        moveExistingMedia(
                                                            idx,
                                                            1,
                                                        )
                                                    "
                                                >
                                                    <component
                                                        :is="
                                                            isRtl
                                                                ? ArrowLeft
                                                                : ArrowRight
                                                        "
                                                        class="size-3.5"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    data-test="remove-existing-media"
                                                    :aria-label="
                                                        t(
                                                            'composer.modal.removeExistingImage',
                                                        )
                                                    "
                                                    class="flex size-7 items-center justify-center rounded-lg bg-slate-950/75 text-white transition hover:bg-destructive"
                                                    @click="
                                                        removeExistingMedia(idx)
                                                    "
                                                >
                                                    <X class="size-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                        <div class="p-2">
                                            <Input
                                                v-model="item.alt_text"
                                                type="text"
                                                maxlength="250"
                                                :placeholder="
                                                    t(
                                                        'upload.altTextPlaceholder',
                                                    )
                                                "
                                                class="h-8 text-xs"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Multi-Image Uploader -->
                            <div
                                v-if="
                                    (showImages || form.images.length > 0) &&
                                    remainingNewImageSlots > 0
                                "
                            >
                                <ImageUploader
                                    ref="imageUploaderRef"
                                    :model-value="form.images"
                                    :alts="form.image_alts"
                                    multiple
                                    :max-files="remainingNewImageSlots"
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
                            <p
                                v-else-if="
                                    isEditMode &&
                                    existingMedia.length >= maxPostImages
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    t('composer.modal.imageLimitReached', {
                                        max: maxPostImages,
                                    })
                                }}
                            </p>

                            <!-- Link Sharing Box -->
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
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-10 shrink-0 rounded-lg text-muted-foreground hover:text-destructive"
                                        :aria-label="
                                            t('composer.modal.removeLink')
                                        "
                                        @click="toggleLink"
                                    >
                                        <Trash2 class="size-4" />
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

                            <!-- Poll Builder (Create Mode) -->
                            <div
                                v-if="!isEditMode && showPoll"
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

                            <!-- Existing Poll Summary (Read-Only in Edit Mode to protect vote integrity) -->
                            <div
                                v-else-if="isEditMode && editingPost?.poll"
                                class="space-y-2 rounded-xl border border-border/80 bg-muted/20 p-3.5 text-xs"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <p class="font-semibold text-foreground">
                                        {{ editingPost.poll.question }}
                                    </p>
                                    <ShieldCheck
                                        class="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </div>
                                <ul class="space-y-1 text-muted-foreground">
                                    <li
                                        v-for="opt in editingPost.poll.options"
                                        :key="opt.id"
                                        class="flex items-center justify-between gap-2 rounded-lg bg-card/70 px-2.5 py-1.5"
                                    >
                                        <span class="truncate">{{
                                            opt.label
                                        }}</span>
                                        <span class="shrink-0 tabular-nums">{{
                                            opt.votes_count
                                        }}</span>
                                    </li>
                                </ul>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ t('composer.modal.pollLockedNotice') }}
                                </p>
                            </div>

                            <!-- Attachment Toolbar -->
                            <div
                                data-test="composer-attachment-toolbar"
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
                                        data-test="composer-tool-image"
                                        :title="t('home.composer.addImage')"
                                        :aria-label="
                                            t('home.composer.addImage')
                                        "
                                        :aria-pressed="
                                            showImages ||
                                            form.images.length > 0 ||
                                            existingMedia.length > 0
                                        "
                                        :class="[
                                            'inline-flex size-9 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                                            showImages ||
                                            form.images.length > 0 ||
                                            existingMedia.length > 0
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
                                        v-if="!isEditMode"
                                        type="button"
                                        data-test="composer-tool-poll"
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
                                        data-test="composer-tool-link"
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
                                        data-test="composer-tool-emoji"
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
                                        data-test="composer-tool-hashtag"
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

                    <!-- Stable Modal Footer (strictly in Flex flow below Body) -->
                    <DialogFooter
                        class="shrink-0 flex-col-reverse gap-2.5 border-t border-border bg-card px-4 pt-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom,0px))] sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-6 sm:py-4"
                    >
                        <div
                            class="hidden min-w-0 text-xs text-muted-foreground sm:block"
                        >
                            <span v-if="form.processing && form.progress">
                                {{ t('upload.uploading') }}
                                {{ form.progress.percentage }}%
                            </span>
                            <span
                                v-else-if="
                                    existingMedia.length + form.images.length >
                                    0
                                "
                            >
                                {{
                                    t('upload.fileCountProgress', {
                                        current:
                                            existingMedia.length +
                                            form.images.length,
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
                                data-test="composer-cancel"
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
                                <span v-if="isEditMode">{{
                                    form.processing
                                        ? t('composer.modal.savingChanges')
                                        : t('post.saveChanges')
                                }}</span>
                                <span v-else>{{
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

        <!-- Unsaved Draft / Changes Confirmation Dialog -->
        <ConfirmActionDialog
            v-if="hasOpenedDiscardDialog"
            :open="discardDialogOpen"
            :title="
                isEditMode
                    ? t('composer.modal.discardEditTitle')
                    : t('home.composer.discardDraftTitle')
            "
            :description="
                isEditMode
                    ? t('composer.modal.discardEditDesc')
                    : t('home.composer.discardDraftDesc')
            "
            :confirm-label="
                isEditMode
                    ? t('composer.modal.discardEditConfirm')
                    : t('home.composer.discardDraftConfirm')
            "
            :cancel-label="t('composer.modal.continueEditing')"
            destructive
            @update:open="
                (val) => {
                    if (!val) keepEditingChanges();
                }
            "
            @confirm="confirmDiscardChanges"
        />
    </div>
</template>
