<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Hash, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PostCard from '@/components/social/PostCard.vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { index as topicsIndex } from '@/routes/topics';
import type { PublicFeedPost } from '@/types';

defineProps<{
    topic: {
        id: number;
        name: string;
        slug: string;
        posts_count: number;
        is_featured: boolean;
    };
    locale: string;
    posts: PublicFeedPost[];
    relatedTopics: Array<{
        name: string;
        slug: string;
        path: string;
        posts_count: number;
    }>;
    relatedCommunities: Array<{
        name: string;
        slug: string;
        path: string;
        members_count: number;
    }>;
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
}>();

const { t } = useI18n();
const page = usePage();
const isGuest = computed(() => !page.props.auth.user);
</script>

<template>
    <div class="space-y-5">
        <Head :title="`#${topic.name}`" />

        <div class="flex items-center justify-between gap-2">
            <Button variant="ghost" size="sm" class="rounded-lg" as-child>
                <Link :href="topicsIndex()">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    <span>{{ t('seo.topics.allTopics') }}</span>
                </Link>
            </Button>
        </div>

        <section
            class="rounded-xl border border-border bg-card p-5 shadow-xs sm:p-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Hash class="size-6" aria-hidden="true" />
                    </div>
                    <div>
                        <h1
                            class="text-xl font-bold text-foreground sm:text-2xl"
                        >
                            #{{ topic.name }}
                        </h1>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('seo.topics.postsCount', {
                                    count: topic.posts_count,
                                })
                            }}
                        </p>
                    </div>
                </div>
                <Button v-if="isGuest" size="sm" as-child>
                    <Link :href="register()">
                        {{ t('seo.topics.joinDiscussion') }}
                    </Link>
                </Button>
            </div>
        </section>

        <div v-if="posts.length > 0" class="space-y-4">
            <PostCard
                v-for="post in posts"
                :key="post.id"
                :post="post"
                :truncate="true"
                view-source="topic"
            />
        </div>

        <section
            v-else
            class="rounded-xl border border-dashed border-border bg-card p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('seo.topics.noPostsInTopic') }}
            </p>
        </section>

        <nav
            v-if="pagination.last_page > 1"
            :aria-label="t('accessibility.pagination')"
            class="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-4 py-3"
        >
            <Button
                v-if="pagination.prev_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="pagination.prev_url" rel="prev">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    <span>{{ t('seo.topics.previous') }}</span>
                </Link>
            </Button>
            <span v-else />

            <span class="text-xs text-muted-foreground">
                {{ pagination.current_page }} / {{ pagination.last_page }}
            </span>

            <Button
                v-if="pagination.next_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="pagination.next_url" rel="next">
                    <span>{{ t('seo.topics.next') }}</span>
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
            <span v-else />
        </nav>

        <section
            v-if="relatedTopics.length > 0 || relatedCommunities.length > 0"
            class="grid gap-4 md:grid-cols-2"
        >
            <div
                v-if="relatedTopics.length > 0"
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('seo.topics.relatedTopics') }}
                </h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Link
                        v-for="rt in relatedTopics"
                        :key="rt.slug"
                        :href="rt.path"
                        class="rounded-full border border-border bg-muted/40 px-3 py-1 text-xs font-medium text-foreground transition-colors hover:border-primary/40 hover:text-primary"
                    >
                        #{{ rt.name }}
                    </Link>
                </div>
            </div>

            <div
                v-if="relatedCommunities.length > 0"
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('seo.topics.relatedCommunities') }}
                </h2>
                <div class="mt-3 space-y-2">
                    <Link
                        v-for="comm in relatedCommunities"
                        :key="comm.slug"
                        :href="comm.path"
                        class="flex items-center justify-between rounded-lg px-2.5 py-2 text-xs font-medium text-foreground transition-colors hover:bg-surface-hover hover:text-primary"
                    >
                        <span class="flex items-center gap-2 truncate">
                            <Users class="size-3.5 text-muted-foreground" />
                            <span class="truncate">{{ comm.name }}</span>
                        </span>
                        <span class="shrink-0 text-muted-foreground">
                            {{ comm.members_count }}
                        </span>
                    </Link>
                </div>
            </div>
        </section>
    </div>
</template>
