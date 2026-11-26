<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Hash, Sparkles } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type TopicItem = {
    id: number;
    name: string;
    slug: string;
    posts_count: number;
    is_featured: boolean;
    path: string;
};

defineProps<{
    topics: TopicItem[];
    locale: string;
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
}>();

const { t } = useI18n();
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('seo.topics.title')" />

        <section
            class="rounded-xl border border-border bg-card p-5 shadow-xs sm:p-6"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-medium text-primary"
                    >
                        <Sparkles class="size-3.5" aria-hidden="true" />
                        <span>{{ t('seo.topics.badge') }}</span>
                    </div>
                    <h1
                        class="mt-2 text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        {{ t('seo.topics.title') }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('seo.topics.subtitle') }}
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="topics.length > 0"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Link
                v-for="topic in topics"
                :key="topic.id"
                :href="topic.path"
                class="group flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-primary/40 hover:bg-surface-hover"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                    >
                        <Hash class="size-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p
                            class="truncate text-sm font-semibold text-foreground group-hover:text-primary"
                        >
                            #{{ topic.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('seo.topics.postsCount', {
                                    count: topic.posts_count,
                                })
                            }}
                        </p>
                    </div>
                </div>
                <Badge v-if="topic.is_featured" variant="secondary">
                    {{ t('seo.topics.featured') }}
                </Badge>
            </Link>
        </section>

        <section
            v-else
            class="rounded-xl border border-dashed border-border bg-card p-10 text-center"
        >
            <p class="text-sm font-medium text-foreground">
                {{ t('seo.topics.emptyTitle') }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('seo.topics.emptyDesc') }}
            </p>
        </section>

        <nav
            v-if="pagination.last_page > 1"
            aria-label="Pagination"
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
            <span v-else class="text-xs text-muted-foreground" />

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
            <span v-else class="text-xs text-muted-foreground" />
        </nav>
    </div>
</template>
