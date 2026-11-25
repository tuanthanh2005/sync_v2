<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ShieldAlert, ShieldCheck } from '@lucide/vue';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import ModerationController from '@/actions/App/Http/Controllers/Admin/ModerationController';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/formatters';
import type { ModerationLogItem, ReportQueueItem } from '@/types';

defineProps<{
    reports: ReportQueueItem[];
    logs: ModerationLogItem[];
    moderators?: Array<{ id: number; name: string; username: string }>;
}>();

const { t, locale } = useI18n();
const notes = reactive<Record<number, string>>({});

function resolveReport(
    reportId: number,
    decision: 'dismiss' | 'hide_content' | 'suspend_user',
): void {
    router.post(
        ModerationController.resolve.url(reportId),
        {
            decision,
            note: notes[reportId] ?? '',
        },
        { preserveScroll: true },
    );
}

function triageReport(
    reportId: number,
    patch: { priority?: string; assigned_to?: number | null; status?: string },
): void {
    router.patch(ModerationController.triage.url(reportId), patch, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.moderation.title')" />

        <section class="rounded-xl border border-border bg-card p-5">
            <div class="flex items-center gap-2.5">
                <ShieldAlert class="size-5 text-primary" aria-hidden="true" />
                <h1 class="text-xl font-bold text-foreground">
                    {{ t('admin.moderation.title') }}
                </h1>
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.moderation.subtitle') }}
            </p>
        </section>

        <section v-if="reports.length > 0" class="space-y-3">
            <article
                v-for="report in reports"
                :key="report.id"
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="outline" class="text-[11px]">
                                {{ report.target_type }} #{{ report.target_id }}
                            </Badge>
                            <Badge
                                :variant="
                                    report.status === 'pending' ||
                                    report.status === 'under_review'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{ report.status_label }}
                            </Badge>
                            <Badge
                                variant="outline"
                                class="text-[10px] uppercase"
                            >
                                {{ report.priority || 'normal' }}
                            </Badge>
                            <span class="text-xs font-medium text-foreground">
                                {{ report.reason_label }}
                            </span>
                        </div>
                        <p class="text-sm text-foreground">
                            {{ report.target_excerpt }}
                        </p>
                        <p
                            v-if="report.details"
                            class="text-xs text-muted-foreground"
                        >
                            {{ report.details }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            Reporter: @{{ report.reporter_username || 'user' }}
                            <span v-if="report.reviewer_username">
                                · Reviewed by @{{
                                    report.reviewer_username
                                }}</span
                            >
                            <span>
                                ·
                                {{
                                    formatDateTime(report.created_at, locale)
                                }}</span
                            >
                        </p>
                    </div>

                    <!-- Priority & Moderator Assignment Triage -->
                    <div
                        v-if="
                            report.status === 'pending' ||
                            report.status === 'under_review'
                        "
                        class="flex flex-wrap items-center gap-2 text-xs"
                    >
                        <select
                            :value="report.priority || 'normal'"
                            class="h-8 rounded-lg border border-input bg-background px-2 text-xs"
                            @change="
                                (e) =>
                                    triageReport(report.id, {
                                        priority: (
                                            e.target as HTMLSelectElement
                                        ).value,
                                    })
                            "
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                        <select
                            v-if="moderators && moderators.length > 0"
                            :value="report.assigned_to ?? ''"
                            class="h-8 rounded-lg border border-input bg-background px-2 text-xs"
                            @change="
                                (e) => {
                                    const val = (e.target as HTMLSelectElement)
                                        .value;
                                    triageReport(report.id, {
                                        assigned_to: val ? Number(val) : null,
                                        status: val
                                            ? 'under_review'
                                            : 'pending',
                                    });
                                }
                            "
                        >
                            <option value="">
                                {{ t('admin.moderation.assignee') }}: —
                            </option>
                            <option
                                v-for="mod in moderators"
                                :key="mod.id"
                                :value="mod.id"
                            >
                                @{{ mod.username }}
                            </option>
                        </select>
                    </div>
                </div>

                <div
                    v-if="
                        report.status === 'pending' ||
                        report.status === 'under_review'
                    "
                    class="flex flex-wrap items-center gap-2 border-t border-border pt-3"
                >
                    <Input
                        v-model="notes[report.id]"
                        type="text"
                        maxlength="500"
                        :placeholder="t('moderation.notePlaceholder')"
                        class="h-8 min-w-48 flex-1 text-xs"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 rounded-lg text-xs"
                        @click="resolveReport(report.id, 'dismiss')"
                    >
                        {{ t('moderation.dismiss') }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="h-8 rounded-lg text-xs"
                        @click="resolveReport(report.id, 'hide_content')"
                    >
                        {{ t('moderation.hideContent') }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        class="h-8 rounded-lg text-xs"
                        @click="resolveReport(report.id, 'suspend_user')"
                    >
                        {{ t('moderation.suspendUser') }}
                    </Button>
                </div>
            </article>
        </section>

        <EmptyState
            v-else
            :icon="ShieldCheck"
            :title="t('moderation.emptyTitle')"
            :description="t('admin.moderation.subtitle')"
        />

        <section
            v-if="logs.length > 0"
            class="rounded-xl border border-border bg-card p-5"
        >
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('moderation.logsTitle') }}
            </h2>
            <div class="mt-3 divide-y divide-border text-xs">
                <div
                    v-for="log in logs"
                    :key="log.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <div>
                        <span class="font-mono font-medium text-foreground">{{
                            log.action
                        }}</span>
                        <span class="ms-2 text-muted-foreground">
                            {{ log.target_type }} #{{ log.target_id }}
                            <template v-if="log.reason">
                                · {{ log.reason }}</template
                            >
                        </span>
                    </div>
                    <span class="text-muted-foreground">
                        @{{ log.actor_username || 'system' }} ·
                        {{ formatDateTime(log.created_at, locale) }}
                    </span>
                </div>
            </div>
        </section>
    </div>
</template>
