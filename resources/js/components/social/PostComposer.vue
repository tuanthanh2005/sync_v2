<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    BarChart2,
    Globe,
    Image as ImageIcon,
    Link2,
    Lock,
    Plus,
    Trash2,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PostController from '@/actions/App/Http/Controllers/Social/PostController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
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

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const expanded = ref(false);
const isMobileFullscreen = ref(false);
const discardDialogOpen = ref(false);
const showPoll = ref(false);
const showLink = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);
const previewUrls = ref<string[]>([]);
const linkPreview = ref<FeedLinkMeta | null>(null);
const { mobileComposerOpen, mobileComposerNonce, closeMobileComposer } =
    useMobileComposer();

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
    poll_question: '',
    poll_options: ['', ''],
    poll_duration_hours: 72,
});

const previewHttp = useHttp<{ url: string }, { preview: FeedLinkMeta | null }>({
    url: '',
});

const hasUnsavedDraft = computed<boolean>(
    () =>
        form.title.trim().length > 0 ||
        form.content.trim().length > 0 ||
        form.images.length > 0 ||
        form.link_url.trim().length > 0 ||
        form.poll_question.trim().length > 0,
);

function persistDraft(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (!hasUnsavedDraft.value) {
            window.sessionStorage.removeItem(draftStorageKey.value);
            return;
        }

        window.sessionStorage.setItem(
            draftStorageKey.value,
            JSON.stringify({
                title: form.title,
                content: form.content,
                category: form.category,
                visibility: form.visibility,
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
    if (hasUnsavedDraft.value && expanded.value && !form.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
}

function requireAuthOrExpand(forceFullscreen = false): boolean {
    if (!user.value) {
        appToast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return false;
    }

    expanded.value = true;

    if (
        forceFullscreen ||
        (typeof window !== 'undefined' && window.innerWidth < 768)
    ) {
        isMobileFullscreen.value = true;
    }

    return true;
}

function requestCloseComposer(): void {
    if (form.processing) {
        return;
    }

    if (hasUnsavedDraft.value) {
        discardDialogOpen.value = true;
        return;
    }

    expanded.value = false;
    isMobileFullscreen.value = false;
    closeMobileComposer();
}

function confirmDiscardDraft(): void {
    discardDialogOpen.value = false;
    clearStoredDraft();
    resetComposer();
}

watch(
    () => [mobileComposerOpen.value, mobileComposerNonce.value],
    ([isOpen]) => {
        if (isOpen) {
            requireAuthOrExpand(true);
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
        persistDraft();
    },
);

watch(isMobileFullscreen, (fullscreen) => {
    if (typeof document !== 'undefined') {
        document.body.style.overflow = fullscreen ? 'hidden' : '';
    }
});

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
                visibility: 'public' | 'private';
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
            if (parsed.visibility) {
                form.visibility = parsed.visibility;
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
        requireAuthOrExpand(true);
    }

    window.addEventListener('beforeunload', handleBeforeUnload);
});

function triggerImagePicker(): void {
    if (!requireAuthOrExpand()) {
        return;
    }

    fileInputRef.value?.click();
}

function handleFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files || input.files.length === 0) {
        return;
    }

    const incoming = Array.from(input.files);
    const merged = [...form.images, ...incoming].slice(0, 4);

    revokeAllPreviews();
    form.images = merged;
    previewUrls.value = merged.map((file) => URL.createObjectURL(file));
    input.value = '';
}

function removeImage(index: number): void {
    const url = previewUrls.value[index];

    if (url) {
        URL.revokeObjectURL(url);
    }

    previewUrls.value.splice(index, 1);
    form.images.splice(index, 1);
}

function revokeAllPreviews(): void {
    for (const url of previewUrls.value) {
        URL.revokeObjectURL(url);
    }

    previewUrls.value = [];
}

function togglePoll(): void {
    if (!requireAuthOrExpand()) {
        return;
    }

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
    if (!requireAuthOrExpand()) {
        return;
    }

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

function resetComposer(): void {
    form.reset();
    form.category = props.defaultCategory;
    form.visibility = 'public';
    form.images = [];
    form.poll_options = ['', ''];
    form.clearErrors();
    revokeAllPreviews();
    clearStoredDraft();
    showPoll.value = false;
    showLink.value = false;
    linkPreview.value = null;
    expanded.value = false;
    isMobileFullscreen.value = false;
    closeMobileComposer();
}

function submitPost(): void {
    if (form.processing || !requireAuthOrExpand()) {
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
        onSuccess: () => {
            resetComposer();
        },
    });
}

onBeforeUnmount(() => {
    revokeAllPreviews();
    if (typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', handleBeforeUnload);
        if (isMobileFullscreen.value) {
            document.body.style.overflow = '';
        }
    }
});
</script>

<template>
    <section
        :aria-label="t('accessibility.postComposer')"
        :class="[
            isMobileFullscreen
                ? 'fixed inset-0 z-50 flex h-[100dvh] flex-col bg-card md:static md:block md:h-auto md:rounded-xl md:border md:border-border md:p-4 md:shadow-xs'
                : 'rounded-xl border border-border bg-card p-3.5 shadow-xs sm:p-4',
        ]"
    >
        <!-- Mobile Full-Screen Native Header Bar -->
        <div
            v-if="isMobileFullscreen"
            class="safe-pt flex shrink-0 items-center justify-between gap-2 border-b border-border bg-card px-3 py-2.5 md:hidden"
        >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="min-h-10 gap-1 rounded-lg px-2.5 text-sm text-muted-foreground"
                :disabled="form.processing"
                @click="requestCloseComposer"
            >
                <X class="size-4" />
                <span>{{ t('post.cancel') }}</span>
            </Button>

            <span class="truncate text-sm font-semibold text-foreground">
                {{ t('nav.createPostShort') }}
            </span>

            <Button
                type="button"
                size="sm"
                class="min-h-10 rounded-lg px-4 text-sm font-semibold"
                :disabled="form.processing || !form.content.trim()"
                @click="submitPost"
            >
                <Spinner v-if="form.processing" />
                <span>{{
                    form.processing
                        ? t('home.composer.publishing')
                        : t('home.composer.submit')
                }}</span>
            </Button>
        </div>

        <form
            @submit.prevent="submitPost"
            :class="[
                isMobileFullscreen
                    ? 'flex min-h-0 flex-1 flex-col md:block md:space-y-3'
                    : 'space-y-3',
            ]"
        >
            <!-- Upload Progress Indicator -->
            <div
                v-if="form.processing && form.progress"
                class="h-1 w-full overflow-hidden bg-muted"
                role="progressbar"
                :aria-valuenow="form.progress.percentage"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full bg-primary transition-all duration-150"
                    :style="{ width: `${form.progress.percentage}%` }"
                />
            </div>

            <div
                :class="[
                    isMobileFullscreen
                        ? 'min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4 md:overflow-visible md:p-0'
                        : 'space-y-3',
                ]"
            >
                <div class="flex items-start gap-3">
                    <Avatar
                        v-if="user"
                        class="mt-0.5 size-10 shrink-0 rounded-full"
                    >
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
                        class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground"
                    >
                        <UserRound class="size-5" aria-hidden="true" />
                    </div>

                    <div class="min-w-0 flex-1 space-y-2.5">
                        <button
                            v-if="!expanded"
                            type="button"
                            data-test="composer-trigger"
                            class="min-h-11 w-full rounded-xl bg-muted/60 px-4 py-2.5 text-left text-sm text-muted-foreground transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="requireAuthOrExpand()"
                        >
                            {{ placeholder || t('home.composer.placeholder') }}
                        </button>

                        <template v-else>
                            <Input
                                v-model="form.title"
                                type="text"
                                maxlength="180"
                                :placeholder="
                                    t('home.composer.titlePlaceholder')
                                "
                                class="rounded-lg bg-muted/30"
                            />
                            <InputError :message="form.errors.title" />

                            <textarea
                                v-model="form.content"
                                :rows="isMobileFullscreen ? 6 : 3"
                                required
                                maxlength="10000"
                                data-test="composer-content"
                                :placeholder="
                                    t('home.composer.contentPlaceholder')
                                "
                                class="w-full resize-y rounded-xl border border-input bg-muted/30 px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                            />
                            <InputError :message="form.errors.content" />

                            <!-- Metadata controls -->
                            <div class="flex flex-wrap items-center gap-2">
                                <select
                                    v-if="categories.length > 0"
                                    v-model="form.category"
                                    :aria-label="
                                        t('home.composer.categoryLabel')
                                    "
                                    class="min-h-10 rounded-lg border border-input bg-card px-2.5 text-xs text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-8"
                                >
                                    <option
                                        v-for="cat in categories"
                                        :key="cat.value"
                                        :value="cat.value"
                                    >
                                        {{ cat.label }}
                                    </option>
                                </select>

                                <button
                                    type="button"
                                    class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-input bg-card px-3 text-xs text-muted-foreground hover:text-foreground sm:min-h-8"
                                    @click="
                                        form.visibility =
                                            form.visibility === 'public'
                                                ? 'private'
                                                : 'public'
                                    "
                                >
                                    <Globe
                                        v-if="form.visibility === 'public'"
                                        class="size-3.5 text-primary"
                                    />
                                    <Lock
                                        v-else
                                        class="size-3.5 text-warning"
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

                                <button
                                    v-if="!isMobileFullscreen"
                                    type="button"
                                    class="ms-auto inline-flex min-h-8 items-center gap-1 rounded-lg px-2 text-xs text-muted-foreground hover:bg-surface-hover hover:text-foreground"
                                    @click="requestCloseComposer"
                                >
                                    <X class="size-3.5" />
                                    <span>{{ t('post.cancel') }}</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Selected image previews -->
                <div
                    v-if="previewUrls.length > 0"
                    class="grid grid-cols-2 gap-2.5 pt-1 sm:grid-cols-4"
                >
                    <div
                        v-for="(src, idx) in previewUrls"
                        :key="src"
                        class="group relative aspect-square overflow-hidden rounded-lg border border-border bg-muted"
                    >
                        <img
                            :src="src"
                            alt="Selected upload"
                            class="size-full object-cover"
                        />
                        <button
                            type="button"
                            class="absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-full bg-black/70 text-white hover:bg-black"
                            @click="removeImage(idx)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                </div>
                <InputError :message="form.errors.images" />

                <!-- Share Link Box -->
                <div
                    v-if="showLink"
                    class="space-y-2.5 rounded-xl border border-border bg-muted/30 p-3"
                >
                    <div class="flex flex-wrap gap-2">
                        <Input
                            v-model="form.link_url"
                            type="url"
                            :placeholder="t('home.composer.linkUrlPlaceholder')"
                            class="min-w-0 flex-1 bg-card"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            class="rounded-lg text-xs"
                            :disabled="
                                previewHttp.processing || !form.link_url.trim()
                            "
                            @click="fetchPreview"
                        >
                            <Spinner v-if="previewHttp.processing" />
                            <span>{{ t('home.composer.fetchPreview') }}</span>
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
                            <p class="text-[11px] font-medium text-primary">
                                {{ linkPreview.domain }}
                            </p>
                            <p class="truncate font-semibold text-foreground">
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
                    class="space-y-2.5 rounded-xl border border-border bg-muted/30 p-3"
                >
                    <Input
                        v-model="form.poll_question"
                        type="text"
                        maxlength="255"
                        :placeholder="
                            t('home.composer.pollQuestionPlaceholder')
                        "
                        class="bg-card"
                    />
                    <InputError :message="form.errors.poll_question" />

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
                                    t('home.composer.pollOptionPlaceholder', {
                                        index: index + 1,
                                    })
                                "
                                class="bg-card"
                            />
                            <Button
                                v-if="form.poll_options.length > 2"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                @click="removePollOption(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                    <InputError :message="form.errors.poll_options" />

                    <div class="flex items-center justify-between pt-1">
                        <Button
                            v-if="form.poll_options.length < 5"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-8 rounded-lg text-xs"
                            @click="addPollOption"
                        >
                            <Plus class="size-3.5" />
                            <span>{{ t('home.composer.addOption') }}</span>
                        </Button>
                        <select
                            v-model.number="form.poll_duration_hours"
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

                <input
                    ref="fileInputRef"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    class="sr-only"
                    @change="handleFilesSelected"
                />
            </div>

            <!-- Action Bar (Sticky above keyboard/safe-area in fullscreen mobile mode) -->
            <div
                :class="[
                    'flex flex-wrap items-center justify-between gap-2 border-t border-border',
                    isMobileFullscreen
                        ? 'safe-pb shrink-0 bg-card px-3 py-2.5 md:px-0 md:pt-3 md:pb-0'
                        : 'pt-3',
                ]"
            >
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="min-h-10 rounded-lg px-2.5 text-xs text-muted-foreground hover:text-foreground sm:min-h-8"
                        @click="triggerImagePicker"
                    >
                        <ImageIcon
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>{{ t('home.composer.photo') }}</span>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :class="[
                            'min-h-10 rounded-lg px-2.5 text-xs sm:min-h-8',
                            showPoll
                                ? 'bg-primary-soft text-accent-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="togglePoll"
                    >
                        <BarChart2
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>{{ t('home.composer.poll') }}</span>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :class="[
                            'min-h-10 rounded-lg px-2.5 text-xs sm:min-h-8',
                            showLink
                                ? 'bg-primary-soft text-accent-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="toggleLink"
                    >
                        <Link2
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>{{ t('home.composer.link') }}</span>
                    </Button>
                </div>

                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing"
                    data-test="composer-submit"
                    :class="[
                        'min-h-10 rounded-lg px-4 text-xs font-medium sm:min-h-8',
                        isMobileFullscreen ? 'hidden md:inline-flex' : '',
                    ]"
                >
                    <Spinner v-if="form.processing" />
                    <span>
                        {{
                            form.processing
                                ? t('home.composer.publishing')
                                : t('home.composer.submit')
                        }}
                    </span>
                </Button>
            </div>
        </form>

        <ConfirmActionDialog
            v-model:open="discardDialogOpen"
            :title="t('home.composer.discardTitle')"
            :description="t('home.composer.discardDescription')"
            :confirm-label="t('home.composer.discardConfirm')"
            destructive
            @confirm="confirmDiscardDraft"
        />
    </section>
</template>
