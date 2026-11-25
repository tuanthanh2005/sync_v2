<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BadgeCheck, Check, X } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import {
    index as verificationsIndex,
    review as verificationReview,
} from '@/routes/admin/verifications';

type VerificationRow = {
    id: number;
    status: string;
    category: string;
    reason: string;
    evidence_note: string | null;
    review_note: string | null;
    user: { id: number; name: string; username: string; email: string } | null;
    reviewer_username: string | null;
    reviewed_at: string | null;
    created_at: string | null;
};

defineProps<{
    requests: {
        data: VerificationRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    status: string;
}>();

const { t, locale } = useI18n();
const dialogOpen = ref(false);
const activeRequest = ref<VerificationRow | null>(null);
const activeDecision = ref<'approved' | 'rejected'>('approved');

function switchStatus(next: string): void {
    router.get(
        verificationsIndex.url({ query: { status: next } }),
        {},
        { preserveScroll: true },
    );
}

function openReview(
    req: VerificationRow,
    decision: 'approved' | 'rejected',
): void {
    activeRequest.value = req;
    activeDecision.value = decision;
    dialogOpen.value = true;
}

function submitReview(note: string): void {
    if (!activeRequest.value) {
        return;
    }

    router.post(
        verificationReview.url(activeRequest.value.id),
        { decision: activeDecision.value, note },
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
        <Head :title="t('admin.verifications.title')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <BadgeCheck class="size-5 text-primary" />
                <span>{{ t('admin.verifications.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.verifications.subtitle') }}
            </p>
        </div>

        <div class="flex gap-1.5 rounded-xl border border-border bg-card p-3">
            <Button
                v-for="st in ['pending', 'approved', 'rejected', 'all']"
                :key="st"
                size="sm"
                :variant="status === st ? 'default' : 'ghost'"
                class="h-8 rounded-lg text-xs capitalize"
                @click="switchStatus(st)"
            >
                {{ st }}
            </Button>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="requests.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />

            <div v-else class="space-y-3">
                <div
                    v-for="req in requests.data"
                    :key="req.id"
                    class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border p-4 text-xs"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">{{
                                req.user?.name || 'User'
                            }}</span>
                            <span class="text-muted-foreground"
                                >@{{ req.user?.username }}</span
                            >
                            <Badge variant="secondary" class="text-[10px]">{{
                                req.category
                            }}</Badge>
                            <Badge variant="outline" class="text-[10px]">{{
                                req.status
                            }}</Badge>
                        </div>
                        <p class="text-foreground">{{ req.reason }}</p>
                        <p
                            v-if="req.evidence_note"
                            class="text-muted-foreground"
                        >
                            {{ req.evidence_note }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatDateTime(req.created_at, locale) }}
                        </p>
                    </div>
                    <div
                        v-if="req.status === 'pending'"
                        class="flex items-center gap-2"
                    >
                        <Button
                            size="sm"
                            class="h-8 gap-1 rounded-lg text-xs"
                            @click="openReview(req, 'approved')"
                        >
                            <Check class="size-3.5" />
                            <span>{{ t('admin.common.approve') }}</span>
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            class="h-8 gap-1 rounded-lg text-xs"
                            @click="openReview(req, 'rejected')"
                        >
                            <X class="size-3.5" />
                            <span>{{ t('admin.common.reject') }}</span>
                        </Button>
                    </div>
                </div>
            </div>

            <AdminPagination
                :current-page="requests.current_page"
                :last-page="requests.last_page"
                :total="requests.total"
                :from="requests.from"
                :to="requests.to"
                :base-url="verificationsIndex.url()"
                :query="{ status }"
            />
        </div>

        <ReasonDialog
            v-model:open="dialogOpen"
            :title="
                activeDecision === 'approved'
                    ? t('admin.common.approve')
                    : t('admin.common.reject')
            "
            :description="
                activeRequest?.user ? `@${activeRequest.user.username}` : ''
            "
            :destructive="activeDecision === 'rejected'"
            :required="activeDecision === 'rejected'"
            @submit="submitReview"
        />
    </div>
</template>
