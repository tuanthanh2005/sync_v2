<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { DatabaseBackup, Download, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatBytes, formatDateTime } from '@/lib/formatters';
import {
    download as downloadBackupRoute,
    store as storeBackupRoute,
} from '@/routes/admin/backups';

defineProps<{
    backups: Array<{
        id: number;
        type: string;
        disk: string;
        path: string;
        size_bytes: number;
        checksum: string;
        status: string;
        notes: string | null;
        creator_username: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();
const form = useForm({ notes: '' });

function createBackup(): void {
    form.post(storeBackupRoute.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.backups.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <DatabaseBackup class="size-5 text-primary" />
                <span>{{ t('admin.backups.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.backups.subtitle') }}
            </p>
        </div>

        <form
            @submit.prevent="createBackup"
            class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-4"
        >
            <Input
                v-model="form.notes"
                placeholder="Optional backup label (e.g. Pre-upgrade snapshot)"
                class="h-9 min-w-[240px] flex-1 text-xs"
            />
            <Button
                type="submit"
                size="sm"
                class="h-9 gap-1.5 rounded-lg text-xs"
                :disabled="form.processing"
            >
                <Plus class="size-3.5" />
                <span>{{ t('admin.backups.createSnapshot') }}</span>
            </Button>
        </form>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="backups.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-2.5 text-xs">
                <div
                    v-for="b in backups"
                    :key="b.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border p-3.5"
                >
                    <div class="space-y-1">
                        <p class="font-mono font-semibold text-foreground">
                            {{ b.path }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatBytes(b.size_bytes, locale) }} ·
                            {{ t('admin.backups.checksum') }}:
                            <span class="font-mono"
                                >{{ b.checksum.slice(0, 16) }}…</span
                            >
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            @{{ b.creator_username || 'system' }} ·
                            {{ formatDateTime(b.created_at, locale) }}
                            <span v-if="b.notes"> · {{ b.notes }}</span>
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 gap-1.5 rounded-lg text-xs"
                        as-child
                    >
                        <a :href="downloadBackupRoute.url(b.id)">
                            <Download class="size-3.5" />
                            <span>{{ t('admin.backups.download') }}</span>
                        </a>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
