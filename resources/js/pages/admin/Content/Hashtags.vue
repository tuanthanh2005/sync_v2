<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Hash } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/formatters';
import { hashtags as hashtagsRoute } from '@/routes/admin/content';
import { update as updateHashtagRoute } from '@/routes/admin/content/hashtags';

type HashtagRow = {
    id: number;
    name: string;
    slug: string;
    posts_count: number;
    is_featured: boolean;
    is_restricted: boolean;
    is_trending_blocked: boolean;
    is_spam: boolean;
};

defineProps<{
    hashtags: {
        data: HashtagRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    search: string;
}>();

const { t, locale } = useI18n();

function toggleFlag(
    tag: HashtagRow,
    field: 'is_featured' | 'is_restricted' | 'is_trending_blocked' | 'is_spam',
): void {
    router.patch(
        updateHashtagRoute.url(tag.id),
        {
            is_featured:
                field === 'is_featured' ? !tag.is_featured : tag.is_featured,
            is_restricted:
                field === 'is_restricted'
                    ? !tag.is_restricted
                    : tag.is_restricted,
            is_trending_blocked:
                field === 'is_trending_blocked'
                    ? !tag.is_trending_blocked
                    : tag.is_trending_blocked,
            is_spam: field === 'is_spam' ? !tag.is_spam : tag.is_spam,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.content.hashtagsTitle')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Hash class="size-5 text-primary" />
                <span>{{ t('admin.content.hashtagsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.hashtagsSubtitle') }}
            </p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="hashtags.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Hashtag</th>
                            <th class="py-2 pr-3">
                                {{ t('admin.users.posts') }}
                            </th>
                            <th class="py-2 text-right">
                                {{ t('admin.common.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="tag in hashtags.data" :key="tag.id">
                            <td
                                class="py-2.5 pr-3 font-semibold text-foreground"
                            >
                                #{{ tag.name }}
                            </td>
                            <td
                                class="py-2.5 pr-3 font-mono text-muted-foreground"
                            >
                                {{ formatNumber(tag.posts_count, locale) }}
                            </td>
                            <td class="py-2.5 text-right">
                                <div class="inline-flex flex-wrap gap-1.5">
                                    <Button
                                        size="sm"
                                        :variant="
                                            tag.is_featured
                                                ? 'default'
                                                : 'outline'
                                        "
                                        class="h-7 px-2 text-[11px]"
                                        @click="toggleFlag(tag, 'is_featured')"
                                    >
                                        {{ t('admin.content.feature') }}
                                    </Button>
                                    <Button
                                        size="sm"
                                        :variant="
                                            tag.is_trending_blocked
                                                ? 'destructive'
                                                : 'outline'
                                        "
                                        class="h-7 px-2 text-[11px]"
                                        @click="
                                            toggleFlag(
                                                tag,
                                                'is_trending_blocked',
                                            )
                                        "
                                    >
                                        No Trend
                                    </Button>
                                    <Button
                                        size="sm"
                                        :variant="
                                            tag.is_restricted
                                                ? 'destructive'
                                                : 'outline'
                                        "
                                        class="h-7 px-2 text-[11px]"
                                        @click="
                                            toggleFlag(tag, 'is_restricted')
                                        "
                                    >
                                        Restrict
                                    </Button>
                                    <Button
                                        size="sm"
                                        :variant="
                                            tag.is_spam
                                                ? 'destructive'
                                                : 'outline'
                                        "
                                        class="h-7 px-2 text-[11px]"
                                        @click="toggleFlag(tag, 'is_spam')"
                                    >
                                        Spam
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminPagination
                :current-page="hashtags.current_page"
                :last-page="hashtags.last_page"
                :total="hashtags.total"
                :from="hashtags.from"
                :to="hashtags.to"
                :base-url="hashtagsRoute.url()"
            />
        </div>
    </div>
</template>
