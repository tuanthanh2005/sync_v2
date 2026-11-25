<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calendar, FileText } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import type { PolicyPublicView } from '@/types';

defineProps<{
    policy: PolicyPublicView;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="space-y-6">
        <Head :title="policy.title" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button variant="ghost" size="sm" class="rounded-lg" as-child>
                <Link :href="home()">
                    <ArrowLeft class="size-4" />
                    <span>{{ t('policy.backHome') }}</span>
                </Link>
            </Button>

            <Badge
                :variant="
                    policy.status === 'published' ? 'secondary' : 'warning'
                "
            >
                {{
                    policy.status === 'draft'
                        ? t('policy.draftBadge')
                        : t('policy.publishedBadge')
                }}
            </Badge>
        </div>

        <article
            class="rounded-xl border border-border bg-card p-6 shadow-xs sm:p-8"
        >
            <header class="border-b border-border pb-5">
                <h1
                    class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                >
                    {{ policy.title }}
                </h1>
                <p
                    v-if="policy.summary"
                    class="mt-2 text-sm leading-relaxed text-muted-foreground"
                >
                    {{ policy.summary }}
                </p>
                <div
                    v-if="policy.updated_at"
                    class="mt-3 inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Calendar class="size-3.5" aria-hidden="true" />
                    <span>
                        {{ t('policy.updatedAt') }}:
                        {{ new Date(policy.updated_at).toLocaleDateString() }}
                    </span>
                </div>
            </header>

            <div
                v-if="policy.content"
                class="mt-6 space-y-4 text-sm leading-7 text-foreground [&_a]:text-primary [&_a]:underline [&_h2]:mt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:text-base [&_h3]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_p]:text-foreground/90"
            >
                <!-- Content is strictly allowlist-sanitized by App\Services\Policy\PolicySanitizer on backend -->
                <div v-html="policy.content" />
            </div>

            <div v-else class="mt-6">
                <EmptyState
                    :icon="FileText"
                    :title="policy.title"
                    :description="t('policy.emptyContent')"
                />
            </div>
        </article>
    </div>
</template>
