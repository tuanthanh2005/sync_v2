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

function targetTypeLabel(type: string): string {
    const map: Record<string, string> = {
        post: 'admin.moderationExtra.targetType.post',
        comment: 'admin.moderationExtra.targetType.comment',
        user: 'admin.moderationExtra.targetType.user',
        community: 'admin.moderationExtra.targetType.community',
        poll: 'admin.moderationExtra.targetType.poll',
        message: 'admin.moderationExtra.targetType.message',
    };
    const key = map[type.toLowerCase()];

    return key ? t(key) : type;
}

function priorityLabel(priority: string): string {
    const map: Record<string, string> = {
        low: 'admin.moderationExtra.priority.low',
        normal: 'admin.moderationExtra.priority.normal',
        high: 'admin.moderationExtra.priority.high',
        critical: 'admin.moderationExtra.priority.critical',
    };
    const key = map[priority.toLowerCase()];

    return key ? t(key) : priority;
}

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
                                {{
                                    t('admin.moderationExtra.targetRef', {
                                        type: targetTypeLabel(
                                            report.target_type,
                                        ),
                                        id: report.target_id,
                                    })
                                }}
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
                                {{ priorityLabel(report.priority || 'normal') }}
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
                            {{
                                t('admin.moderationExtra.reporterBy', {
                                    username:
                                        report.reporter_username ||
                                        t('admin.moderationExtra.unknownUser'),
                                })
                            }}
                            <span v-if="report.reviewer_username">
                                ·
                                {{
                                    t('admin.moderationExtra.reviewedBy', {
                                        username: report.reviewer_username,
                                    })
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
                            :aria-label="
                                t('admin.moderationExtra.priorityAria')
                            "
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
                            <option value="low">
                                {{ t('admin.moderationExtra.priority.low') }}
                            </option>
                            <option value="normal">
                                {{ t('admin.moderationExtra.priority.normal') }}
                            </option>
                            <option value="high">
                                {{ t('admin.moderationExtra.priority.high') }}
                            </option>
                            <option value="critical">
                                {{
                                    t('admin.moderationExtra.priority.critical')
                                }}
                            </option>
                        </select>
                        <select
                            v-if="moderators && moderators.length > 0"
                            :value="report.assigned_to ?? ''"
                            :aria-label="t('admin.moderation.assignee')"
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
                                {{
                                    t(
                                        'admin.moderationExtra.assigneeUnassigned',
                                    )
                                }}
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
                            {{
                                t('admin.moderationExtra.targetRef', {
                                    type: targetTypeLabel(log.target_type),
                                    id: log.target_id,
                                })
                            }}
                            <template v-if="log.reason">
                                · {{ log.reason }}</template
                            >
                        </span>
                    </div>
                    <span class="text-muted-foreground">
                        {{
                            log.actor_username
                                ? `@${log.actor_username}`
                                : t('admin.moderationExtra.systemActor')
                        }}
                        ·
                        {{ formatDateTime(log.created_at, locale) }}
                    </span>
                </div>
            </div>
        </section>
    </div>
</template>
