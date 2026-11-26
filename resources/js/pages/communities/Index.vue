<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { BadgeCheck, Globe, Lock, Plus, Search, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import CommunityController from '@/actions/App/Http/Controllers/Community/CommunityController';
import EmptyState from '@/components/common/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
import type { CategoryOption, CommunitySummary } from '@/types';

const props = defineProps<{
    communities: CommunitySummary[];
    filter: string;
    search: string;
    categories: CategoryOption[];
}>();

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const createOpen = ref(false);
const keyword = ref(props.search);
const rulesText = ref('');

const createForm = useForm<{
    name: string;
    category: string;
    description: string;
    visibility: 'public' | 'private';
    rules: string[];
    avatar: File | null;
    cover: File | null;
}>({
    name: '',
    category: props.categories[0]?.value ?? 'general',
    description: '',
    visibility: 'public',
    rules: [],
    avatar: null,
    cover: null,
});

function applyFilter(nextFilter: string): void {
    router.get(
        CommunityController.index.url({
            query: {
                filter: nextFilter,
                q: keyword.value.trim() || undefined,
            },
        }),
        {},
        { preserveState: true },
    );
}

function submitSearch(): void {
    applyFilter(props.filter);
}

function toggleJoin(community: CommunitySummary): void {
    if (!user.value) {
        toast.info(t('home.composer.signInPrompt'));
        router.visit(login());

        return;
    }

    router.post(
        CommunityController.toggleMembership.url(community.slug),
        {},
        { preserveScroll: true },
    );
}

function setFile(field: 'avatar' | 'cover', event: Event): void {
    const input = event.target as HTMLInputElement;
    createForm[field] = input.files?.[0] ?? null;
}

function submitCreate(): void {
    createForm
        .transform((data) => ({
            ...data,
            rules: rulesText.value
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean)
                .slice(0, 10),
        }))
        .post(CommunityController.store.url(), {
            forceFormData: true,
            onSuccess: () => {
                createOpen.value = false;
                createForm.reset();
                rulesText.value = '';
            },
        });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('communities.title')" />

        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div>
                <h1 class="text-xl font-bold text-foreground">
                    {{ t('communities.title') }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('communities.subtitle') }}
                </p>
            </div>

            <Dialog v-if="user" v-model:open="createOpen">
                <DialogTrigger as-child>
                    <Button size="sm" class="rounded-lg">
                        <Plus class="size-4" />
                        <span>{{ t('communities.create') }}</span>
                    </Button>
                </DialogTrigger>
                <DialogContent class="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ t('communities.create') }}</DialogTitle>
                    </DialogHeader>
                    <form @submit.prevent="submitCreate" class="space-y-4">
                        <div class="grid gap-1.5">
                            <Label for="community-name">{{
                                t('communities.name')
                            }}</Label>
                            <Input
                                id="community-name"
                                v-model="createForm.name"
                                required
                                maxlength="100"
                            />
                            <InputError :message="createForm.errors.name" />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="community-category">{{
                                    t('communities.category')
                                }}</Label>
                                <select
                                    id="community-category"
                                    v-model="createForm.category"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option
                                        v-for="cat in categories"
                                        :key="cat.value"
                                        :value="cat.value"
                                    >
                                        {{ cat.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="community-vis">{{
                                    t('communities.extra.visibility')
                                }}</Label>
                                <select
                                    id="community-vis"
                                    v-model="createForm.visibility"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="public">
                                        {{ t('communities.visibilityPublic') }}
                                    </option>
                                    <option value="private">
                                        {{ t('communities.visibilityPrivate') }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="community-desc">{{
                                t('communities.description')
                            }}</Label>
                            <textarea
                                id="community-desc"
                                v-model="createForm.description"
                                rows="3"
                                maxlength="1000"
                                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                            />
                            <InputError
                                :message="createForm.errors.description"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="community-rules">{{
                                t('communities.rulesInput')
                            }}</Label>
                            <textarea
                                id="community-rules"
                                v-model="rulesText"
                                rows="3"
                                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1">
                                <Label for="comm-avatar">{{
                                    t('communities.extra.avatar')
                                }}</Label>
                                <input
                                    id="comm-avatar"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="text-xs"
                                    @change="setFile('avatar', $event)"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label for="comm-cover">{{
                                    t('communities.extra.cover')
                                }}</Label>
                                <input
                                    id="comm-cover"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="text-xs"
                                    @change="setFile('cover', $event)"
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
                                :disabled="createForm.processing"
                                class="rounded-lg"
                            >
                                <Spinner v-if="createForm.processing" />
                                <span>{{ t('communities.create') }}</span>
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>

        <!-- Search & Filter bar -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    :variant="filter === 'all' ? 'secondary' : 'outline'"
                    class="rounded-full text-xs"
                    @click="applyFilter('all')"
                >
                    {{ t('communities.all') }}
                </Button>
                <Button
                    v-if="user"
                    type="button"
                    size="sm"
                    :variant="filter === 'joined' ? 'secondary' : 'outline'"
                    class="rounded-full text-xs"
                    @click="applyFilter('joined')"
                >
                    {{ t('communities.joined') }}
                </Button>
            </div>

            <form
                @submit.prevent="submitSearch"
                class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1"
            >
                <Search class="size-4 text-muted-foreground" />
                <input
                    v-model="keyword"
                    type="search"
                    :placeholder="t('communities.searchPlaceholder')"
                    class="bg-transparent text-xs outline-none"
                />
            </form>
        </div>

        <!-- Community Cards -->
        <section
            v-if="communities.length > 0"
            class="grid gap-4 sm:grid-cols-2"
        >
            <article
                v-for="community in communities"
                :key="community.id"
                class="flex flex-col justify-between overflow-hidden rounded-xl border border-border bg-card"
            >
                <div>
                    <div class="h-20 w-full border-b border-border bg-muted/70">
                        <img
                            v-if="community.cover"
                            :src="community.cover"
                            :alt="community.name"
                            class="size-full object-cover"
                        />
                    </div>
                    <div class="p-4">
                        <div class="-mt-9 flex items-end justify-between gap-3">
                            <Avatar
                                class="size-14 rounded-xl border-2 border-card shadow-2xs"
                            >
                                <AvatarImage
                                    v-if="community.avatar"
                                    :src="community.avatar"
                                    :alt="community.name"
                                />
                                <AvatarFallback
                                    class="bg-primary-soft text-sm font-bold text-accent-foreground"
                                >
                                    {{ getInitials(community.name) }}
                                </AvatarFallback>
                            </Avatar>

                            <Button
                                type="button"
                                size="sm"
                                :variant="
                                    community.is_joined ? 'outline' : 'default'
                                "
                                class="rounded-lg text-xs"
                                @click="toggleJoin(community)"
                            >
                                {{
                                    community.is_joined
                                        ? t('communities.leave')
                                        : t('communities.join')
                                }}
                            </Button>
                        </div>

                        <div class="mt-3">
                            <div class="flex items-center gap-1.5">
                                <Link
                                    :href="
                                        CommunityController.show.url(
                                            community.slug,
                                        )
                                    "
                                    class="text-base font-bold text-foreground hover:text-primary"
                                >
                                    {{ community.name }}
                                </Link>
                                <BadgeCheck
                                    v-if="community.is_verified"
                                    class="size-4 text-primary"
                                />
                            </div>
                            <div
                                class="mt-1 flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <Badge variant="secondary" class="text-[10px]">
                                    {{ community.category_label }}
                                </Badge>
                                <span class="inline-flex items-center gap-1">
                                    <Globe class="size-3" />
                                    {{
                                        t('communities.membersCount', {
                                            count: community.members_count,
                                        })
                                    }}
                                </span>
                            </div>
                            <p
                                v-if="community.description"
                                class="mt-2 line-clamp-2 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ community.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <EmptyState
            v-else
            :icon="Users"
            :title="t('communities.emptyTitle')"
            :description="t('communities.emptyDescription')"
        />
    </div>
</template>
