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
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import PostController from '@/actions/App/Http/Controllers/Social/PostController';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
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
const showPoll = ref(false);
const showLink = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);
const previewUrls = ref<string[]>([]);
const linkPreview = ref<FeedLinkMeta | null>(null);

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

function requireAuthOrExpand(): boolean {
    if (!user.value) {
        toast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return false;
    }

    expanded.value = true;

    return true;
}

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
            toast.error(t('home.composer.fetchPreview'));
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
    showPoll.value = false;
    showLink.value = false;
    linkPreview.value = null;
    expanded.value = false;
}

function submitPost(): void {
    if (!requireAuthOrExpand()) {
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
});
</script>

<template>
    <section
        aria-label="Post composer"
        class="rounded-xl border border-border bg-card p-4 shadow-xs"
    >
        <form @submit.prevent="submitPost" class="space-y-3">
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
                        class="w-full rounded-xl bg-muted/60 px-4 py-2.5 text-left text-sm text-muted-foreground transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="requireAuthOrExpand"
                    >
                        {{ placeholder || t('home.composer.placeholder') }}
                    </button>

                    <template v-else>
                        <Input
                            v-model="form.title"
                            type="text"
                            maxlength="180"
                            :placeholder="t('home.composer.titlePlaceholder')"
                            class="rounded-lg bg-muted/30"
                        />
                        <InputError :message="form.errors.title" />

                        <textarea
                            v-model="form.content"
                            rows="3"
                            required
                            maxlength="10000"
                            data-test="composer-content"
                            :placeholder="t('home.composer.contentPlaceholder')"
                            class="w-full resize-y rounded-xl border border-input bg-muted/30 px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                        />
                        <InputError :message="form.errors.content" />

                        <!-- Metadata controls -->
                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                v-if="categories.length > 0"
                                v-model="form.category"
                                :aria-label="t('home.composer.categoryLabel')"
                                class="h-8 rounded-lg border border-input bg-card px-2.5 text-xs text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
                                class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-input bg-card px-2.5 text-xs text-muted-foreground hover:text-foreground"
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
                                <Lock v-else class="size-3.5 text-warning" />
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
                    :placeholder="t('home.composer.pollQuestionPlaceholder')"
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
                        <option :value="24">24h</option>
                        <option :value="72">3d</option>
                        <option :value="168">7d</option>
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

            <!-- Action Bar -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3"
            >
                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 rounded-lg px-2.5 text-xs text-muted-foreground hover:text-foreground"
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
                            'h-8 rounded-lg px-2.5 text-xs',
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
                            'h-8 rounded-lg px-2.5 text-xs',
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
                    class="h-8 rounded-lg px-4 text-xs font-medium"
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
    </section>
</template>
