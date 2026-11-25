<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Scale, X } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import {
    index as appealsIndex,
    resolve as appealResolve,
} from '@/routes/admin/appeals';

type AppealRow = {
    id: number;
    status: string;
    reason: string;
    resolution_note: string | null;
    user: { id: number; name: string; username: string; status: string } | null;
    sanction: { id: number; type_label: string; reason: string } | null;
    reviewer_username: string | null;
    created_at: string | null;
};

defineProps<{
    appeals: {
        data: AppealRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    status: string;
}>();

const { t, locale } = useI18n();
const dialogOpen = ref(false);
const activeAppeal = ref<AppealRow | null>(null);
const activeDecision = ref<'approved' | 'rejected'>('approved');

function openResolve(
    appeal: AppealRow,
    decision: 'approved' | 'rejected',
): void {
    activeAppeal.value = appeal;
    activeDecision.value = decision;
    dialogOpen.value = true;
}

function submitResolve(note: string): void {
    if (!activeAppeal.value) {
        return;
    }
    router.post(
        appealResolve.url(activeAppeal.value.id),
        { decision: activeDecision.value, resolution_note: note },
        {
            preserveScroll: true,
            onFinish: () => {
                dialogOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.moderation.appealsTitle')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Scale class="size-5 text-primary" />
                <span>{{ t('admin.moderation.appealsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.moderation.appealsSubtitle') }}
            </p>
        </div>

        <div class="flex gap-1.5 rounded-xl border border-border bg-card p-3">
            <Button
                v-for="st in ['pending', 'approved', 'rejected', 'all']"
                :key="st"
                size="sm"
                :variant="status === st ? 'default' : 'ghost'"
                class="h-8 rounded-lg text-xs capitalize"
                @click="router.get(appealsIndex.url({ query: { status: st } }))"
            >
                {{ st }}
            </Button>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="appeals.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-3">
                <div
                    v-for="appeal in appeals.data"
                    :key="appeal.id"
                    class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground"
                                >@{{ appeal.user?.username || 'unknown' }}</span
                            >
                            <Badge variant="outline" class="text-[10px]">{{
                                appeal.status
                            }}</Badge>
                            <Badge
                                v-if="appeal.sanction"
                                variant="secondary"
                                class="text-[10px]"
                                >{{ appeal.sanction.type_label }}</Badge
                            >
                        </div>
                        <p class="text-foreground">{{ appeal.reason }}</p>
                        <p
                            v-if="appeal.resolution_note"
                            class="text-muted-foreground"
                        >
                            {{ appeal.resolution_note }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatDateTime(appeal.created_at, locale) }}
                        </p>
                    </div>
                    <div v-if="appeal.status === 'pending'" class="flex gap-2">
                        <Button
                            size="sm"
                            class="h-8 gap-1 rounded-lg text-xs"
                            @click="openResolve(appeal, 'approved')"
                        >
                            <Check class="size-3.5" />
                            <span>{{ t('admin.common.approve') }}</span>
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            class="h-8 gap-1 rounded-lg text-xs"
                            @click="openResolve(appeal, 'rejected')"
                        >
                            <X class="size-3.5" />
                            <span>{{ t('admin.common.reject') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <ReasonDialog
            v-model:open="dialogOpen"
            :title="
                activeDecision === 'approved'
                    ? t('admin.common.approve')
                    : t('admin.common.reject')
            "
            :description="`Appeal #${activeAppeal?.id}`"
            :destructive="activeDecision === 'rejected'"
            @submit="submitResolve"
        />
    </div>
</template>
