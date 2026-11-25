<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Bell, Send, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';
import {
    cancel as cancelNotificationRoute,
    store as storeNotificationRoute,
} from '@/routes/admin/notifications';

defineProps<{
    broadcasts: Array<{
        id: number;
        audience: string;
        target_username: string | null;
        category: string;
        title: string;
        body: string;
        status: string;
        recipients_count: number;
        sender_username: string | null;
        scheduled_at: string | null;
        sent_at: string | null;
        created_at: string | null;
    }>;
    templates: Array<{
        id: number;
        key: string;
        name: string;
        category: string;
        subject_template: string;
        body_template: string;
        is_active: boolean;
    }>;
}>();

const { t, locale } = useI18n();
const form = useForm({
    audience: 'all',
    target_username: '',
    category: 'system',
    title: '',
    body: '',
    action_url: '',
    scheduled_at: '',
});

function submitBroadcast(): void {
    form.post(storeNotificationRoute.url(), {
        preserveScroll: true,
        onSuccess: () =>
            form.reset(
                'title',
                'body',
                'action_url',
                'scheduled_at',
                'target_username',
            ),
    });
}

function cancelBroadcast(id: number): void {
    router.post(cancelNotificationRoute.url(id), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.notifications.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Bell class="size-5 text-primary" />
                <span>{{ t('admin.notifications.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.notifications.subtitle') }}
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <form
                @submit.prevent="submitBroadcast"
                class="space-y-3 rounded-xl border border-border bg-card p-5 text-xs"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t('admin.notifications.compose') }}
                </h2>
                <div class="space-y-1">
                    <Label>{{ t('admin.notifications.audience') }}</Label>
                    <select
                        v-model="form.audience"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option value="all">All Active Users</option>
                        <option value="verified">Verified Users</option>
                        <option value="staff">Staff Only</option>
                        <option value="user">Specific User (@username)</option>
                    </select>
                </div>
                <div v-if="form.audience === 'user'" class="space-y-1">
                    <Label>Username</Label>
                    <Input
                        v-model="form.target_username"
                        placeholder="username"
                        class="h-9 text-xs"
                        required
                    />
                </div>
                <div class="space-y-1">
                    <Label>{{ t('admin.notifications.category') }}</Label>
                    <select
                        v-model="form.category"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option value="system">System Announcement</option>
                        <option value="maintenance">Maintenance Notice</option>
                        <option value="policy">Policy Update</option>
                        <option value="violation">Violation Warning</option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label>Title</Label>
                    <Input v-model="form.title" required class="h-9 text-xs" />
                </div>
                <div class="space-y-1">
                    <Label>Message</Label>
                    <textarea
                        v-model="form.body"
                        rows="3"
                        required
                        class="w-full rounded-lg border border-input bg-background p-2.5 text-xs"
                    />
                </div>
                <div class="space-y-1">
                    <Label>{{ t('admin.notifications.schedule') }}</Label>
                    <Input
                        v-model="form.scheduled_at"
                        type="datetime-local"
                        class="h-9 text-xs"
                    />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="w-full gap-1.5 rounded-lg text-xs"
                    :disabled="form.processing"
                >
                    <Send class="size-3.5" />
                    <span>{{ t('admin.notifications.sendNow') }}</span>
                </Button>
            </form>

            <div
                class="rounded-xl border border-border bg-card p-5 lg:col-span-2"
            >
                <h2 class="mb-3 text-sm font-semibold text-foreground">
                    {{ t('admin.notifications.history') }}
                </h2>
                <EmptyState
                    v-if="broadcasts.length === 0"
                    :title="t('admin.common.emptyTitle')"
                    :description="t('admin.common.emptyDescription')"
                />
                <div v-else class="space-y-2.5 text-xs">
                    <div
                        v-for="item in broadcasts"
                        :key="item.id"
                        class="flex items-start justify-between gap-3 rounded-xl border border-border p-3.5"
                    >
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-foreground">{{
                                    item.title
                                }}</span>
                                <Badge
                                    variant="secondary"
                                    class="text-[10px]"
                                    >{{ item.audience }}</Badge
                                >
                                <Badge variant="outline" class="text-[10px]">{{
                                    item.status
                                }}</Badge>
                                <span class="text-[11px] text-muted-foreground"
                                    >({{ item.recipients_count }})</span
                                >
                            </div>
                            <p class="text-muted-foreground">{{ item.body }}</p>
                            <p class="text-[11px] text-muted-foreground">
                                @{{ item.sender_username || 'system' }} ·
                                {{
                                    formatDateTime(
                                        item.sent_at ||
                                            item.scheduled_at ||
                                            item.created_at,
                                        locale,
                                    )
                                }}
                            </p>
                        </div>
                        <Button
                            v-if="item.status === 'scheduled'"
                            size="sm"
                            variant="destructive"
                            class="h-7 gap-1 px-2 text-[11px]"
                            @click="cancelBroadcast(item.id)"
                        >
                            <X class="size-3.5" />
                            <span>{{
                                t('admin.notifications.cancelScheduled')
                            }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
