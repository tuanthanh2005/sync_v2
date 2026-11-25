<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EyeOff, HardDrive, Image as ImageIcon, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ReasonDialog from '@/components/common/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBytes, formatNumber } from '@/lib/formatters';
import { media as mediaRoute } from '@/routes/admin/content';
import { moderate as moderateMediaRoute } from '@/routes/admin/content/media';

type MediaRow = {
    id: number;
    post_id: number;
    url: string;
    mime_type: string;
    width: number | null;
    height: number | null;
    size_bytes: number;
    moderation_status: string;
    owner_username: string | null;
};

defineProps<{
    media: {
        data: MediaRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    storageSummary: {
        total_files: number;
        total_bytes: number;
        hidden_files: number;
    };
    status: string;
}>();

const { t, locale } = useI18n();
const dialogOpen = ref(false);
const activeMedia = ref<MediaRow | null>(null);
const activeAction = ref('hide');

function openAction(item: MediaRow, action: string): void {
    activeMedia.value = item;
    activeAction.value = action;
    dialogOpen.value = true;
}

function submitAction(reason: string): void {
    if (!activeMedia.value) {
        return;
    }
    router.post(
        moderateMediaRoute.url(activeMedia.value.id),
        { action: activeAction.value, reason },
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
        <Head :title="t('admin.content.mediaTitle')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <ImageIcon class="size-5 text-primary" />
                <span>{{ t('admin.content.mediaTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.mediaSubtitle') }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <AdminStatCard
                :label="t('admin.nav.media')"
                :value="formatNumber(storageSummary.total_files, locale)"
                :icon="ImageIcon"
                tone="primary"
            />
            <AdminStatCard
                :label="t('admin.dashboard.mediaStorage')"
                :value="formatBytes(storageSummary.total_bytes, locale)"
                :icon="HardDrive"
                tone="neutral"
            />
            <AdminStatCard
                :label="t('admin.common.hide')"
                :value="formatNumber(storageSummary.hidden_files, locale)"
                :icon="EyeOff"
                tone="warning"
            />
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="media.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div
                v-else
                class="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <div
                    v-for="item in media.data"
                    :key="item.id"
                    class="overflow-hidden rounded-xl border border-border bg-muted/20"
                >
                    <img
                        :src="item.url"
                        alt=""
                        class="h-36 w-full object-cover"
                    />
                    <div class="space-y-1.5 p-3 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-muted-foreground"
                                >#{{ item.id }}</span
                            >
                            <Badge variant="secondary" class="text-[10px]">{{
                                item.moderation_status
                            }}</Badge>
                        </div>
                        <p class="truncate text-[11px] text-muted-foreground">
                            {{ item.mime_type }} ·
                            {{ formatBytes(item.size_bytes, locale) }}
                        </p>
                        <div class="flex gap-1.5 pt-1">
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 flex-1 text-[11px]"
                                @click="
                                    openAction(
                                        item,
                                        item.moderation_status === 'hidden'
                                            ? 'approve'
                                            : 'hide',
                                    )
                                "
                            >
                                {{
                                    item.moderation_status === 'hidden'
                                        ? t('admin.common.unhide')
                                        : t('admin.common.hide')
                                }}
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-7 px-2"
                                @click="openAction(item, 'delete')"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <AdminPagination
                :current-page="media.current_page"
                :last-page="media.last_page"
                :total="media.total"
                :from="media.from"
                :to="media.to"
                :base-url="mediaRoute.url()"
            />
        </div>

        <ReasonDialog
            v-model:open="dialogOpen"
            :title="`${t('admin.content.mediaTitle')} · ${activeAction}`"
            :description="`Media #${activeMedia?.id}`"
            @submit="submitAction"
        />
    </div>
</template>
