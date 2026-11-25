<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import NotificationController from '@/actions/App/Http/Controllers/Notification/NotificationController';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { NotificationItem } from '@/types';

defineProps<{
    notifications: NotificationItem[];
}>();

const { t } = useI18n();

function openNotification(item: NotificationItem): void {
    if (!item.read_at) {
        router.post(
            NotificationController.markRead.url(item.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.visit(item.url);
                },
            },
        );

        return;
    }

    router.visit(item.url);
}

function markAllRead(): void {
    router.post(
        NotificationController.markAllRead.url(),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('notifications.title')" />

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex items-center gap-2.5">
                <Bell class="size-5 text-primary" aria-hidden="true" />
                <h1 class="text-lg font-bold text-foreground">
                    {{ t('notifications.title') }}
                </h1>
            </div>

            <Button
                v-if="notifications.some((n) => !n.read_at)"
                type="button"
                variant="outline"
                size="sm"
                class="rounded-lg text-xs"
                @click="markAllRead"
            >
                <CheckCheck class="size-4" />
                <span>{{ t('notifications.markAllRead') }}</span>
            </Button>
        </div>

        <div
            v-if="notifications.length > 0"
            class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-xs"
        >
            <button
                v-for="item in notifications"
                :key="item.id"
                type="button"
                :class="[
                    'flex w-full items-start justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-muted/60',
                    !item.read_at ? 'bg-accent/35' : '',
                ]"
                @click="openNotification(item)"
            >
                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-medium text-foreground">
                        {{ item.title }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="item.actor_username" class="mr-2">
                            @{{ item.actor_username }}
                        </span>
                        <span v-if="item.created_at">
                            {{ new Date(item.created_at).toLocaleString() }}
                        </span>
                    </p>
                </div>
                <Badge
                    v-if="!item.read_at"
                    class="shrink-0 bg-primary text-[10px] text-primary-foreground"
                >
                    NEW
                </Badge>
            </button>
        </div>

        <EmptyState
            v-else
            :icon="Bell"
            :title="t('notifications.emptyTitle')"
            :description="t('notifications.emptyDescription')"
        />
    </div>
</template>
