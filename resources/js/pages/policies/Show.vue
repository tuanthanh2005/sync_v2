<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calendar, FileText, Globe } from '@lucide/vue';
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
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    <span>{{ t('policy.backHome') }}</span>
                </Link>
            </Button>

            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="policy.status !== 'published'" variant="warning">
                    {{ policy.status_label }}
                </Badge>
            </div>
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
                    class="mt-2.5 text-sm leading-relaxed text-muted-foreground"
                >
                    {{ policy.summary }}
                </p>

                <div
                    class="mt-3.5 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
                >
                    <span
                        v-if="policy.effective_at"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Calendar class="size-3.5" aria-hidden="true" />
                        <span>
                            {{ t('policy.effectiveAt') }}:
                            {{
                                new Date(
                                    policy.effective_at,
                                ).toLocaleDateString()
                            }}
                        </span>
                    </span>
                    <span
                        v-if="policy.updated_at"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Calendar class="size-3.5" aria-hidden="true" />
                        <span>
                            {{ t('policy.updatedAt') }}:
                            {{
                                new Date(policy.updated_at).toLocaleDateString()
                            }}
                        </span>
                    </span>
                </div>

                <!-- Genuine published translations switcher -->
                <div
                    v-if="
                        policy.available_locales &&
                        policy.available_locales.length > 1
                    "
                    class="mt-4 flex flex-wrap items-center gap-1.5"
                >
                    <Globe
                        class="me-1 size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Link
                        v-for="loc in policy.available_locales"
                        :key="loc.code"
                        :href="loc.href"
                        :class="[
                            'rounded-full border px-2.5 py-0.5 text-[11px] font-medium transition-colors',
                            policy.active_locale === loc.code
                                ? 'border-primary/40 bg-primary-soft text-accent-foreground'
                                : 'border-border bg-muted/40 text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        {{ loc.native_name }}
                    </Link>
                </div>
            </header>

            <div
                v-if="policy.content"
                class="mt-6 space-y-4 text-sm leading-7 text-foreground [&_a]:text-primary [&_a]:underline [&_blockquote]:border-s-2 [&_blockquote]:border-primary/40 [&_blockquote]:ps-4 [&_blockquote]:text-muted-foreground [&_h2]:mt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:text-base [&_h3]:font-semibold [&_hr]:my-6 [&_hr]:border-border [&_img]:my-4 [&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-xl [&_img]:border [&_img]:border-border [&_li]:ms-5 [&_li]:list-disc [&_ol_li]:list-decimal [&_p]:text-foreground/90 [&_table]:my-4 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-border [&_td]:p-2 [&_th]:border [&_th]:border-border [&_th]:bg-muted/50 [&_th]:p-2 [&_th]:text-start"
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
