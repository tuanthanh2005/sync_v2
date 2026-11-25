<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { LogOut, Search, Shield } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import { index as securityIndex } from '@/routes/admin/security';
import { revoke as revokeSessionRoute } from '@/routes/admin/security/sessions';

const props = defineProps<{
    tab: string;
    filters: { search: string; action: string };
    audits: {
        data: Array<{
            id: number;
            action: string;
            actor_username: string | null;
            auditable_type: string | null;
            auditable_id: number | null;
            ip_address: string | null;
            created_at: string;
        }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    logins: Array<{
        id: number;
        username: string | null;
        email_attempted: string | null;
        event_type: string;
        ip_address: string | null;
        user_agent: string;
        created_at: string;
    }>;
    suspicious: Array<{
        ip_address: string;
        attempts: number;
        last_attempt: string;
    }>;
    sessions: Array<{
        id: string;
        user_id: number;
        username: string;
        ip_address: string;
        user_agent: string;
        last_active_at: string;
    }>;
    webhooks: Array<{
        id: number;
        provider: string;
        event_id: string | null;
        status: string;
        signature_valid: boolean;
        ip_address: string | null;
        error_message: string | null;
        created_at: string;
    }>;
}>();

const { t, locale } = useI18n();
const activeTab = ref(props.tab || 'audits');
const searchInput = ref(props.filters.search);

function applySearch(): void {
    router.get(
        securityIndex.url(),
        { tab: activeTab.value, search: searchInput.value.trim() || undefined },
        { preserveState: true },
    );
}

function revokeSession(sessionId: string): void {
    router.delete(revokeSessionRoute.url(sessionId), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.security.title')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <Shield class="size-5 text-primary" />
                <span>{{ t('admin.security.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.security.subtitle') }}
            </p>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3"
        >
            <div class="flex flex-wrap gap-1.5">
                <Button
                    v-for="tk in [
                        'audits',
                        'logins',
                        'sessions',
                        'suspicious',
                        'webhooks',
                    ]"
                    :key="tk"
                    size="sm"
                    :variant="activeTab === tk ? 'default' : 'ghost'"
                    class="h-8 rounded-lg text-xs"
                    @click="activeTab = tk"
                >
                    {{ t(`admin.security.tabs.${tk}`) }}
                </Button>
            </div>
            <form @submit.prevent="applySearch" class="flex items-center gap-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        :placeholder="t('admin.common.search')"
                        class="h-8 rounded-lg border border-input bg-background pr-3 pl-8 text-xs"
                    />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="h-8 rounded-lg text-xs"
                    >{{ t('admin.common.filter') }}</Button
                >
            </form>
        </div>

        <section
            v-if="activeTab === 'audits'"
            class="rounded-xl border border-border bg-card p-4"
        >
            <EmptyState
                v-if="audits.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Action</th>
                            <th class="py-2 pr-3">Actor</th>
                            <th class="py-2 pr-3">Target</th>
                            <th class="py-2 pr-3">IP</th>
                            <th class="py-2">
                                {{ t('admin.common.createdAt') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="a in audits.data" :key="a.id">
                            <td class="py-2.5 pr-3 font-mono font-semibold">
                                {{ a.action }}
                            </td>
                            <td class="py-2.5 pr-3">
                                @{{ a.actor_username || 'system' }}
                            </td>
                            <td class="py-2.5 pr-3 text-muted-foreground">
                                {{
                                    a.auditable_type
                                        ? `${a.auditable_type}#${a.auditable_id}`
                                        : '—'
                                }}
                            </td>
                            <td
                                class="py-2.5 pr-3 font-mono text-muted-foreground"
                            >
                                {{ a.ip_address || '-' }}
                            </td>
                            <td class="py-2.5 text-muted-foreground">
                                {{ formatDateTime(a.created_at, locale) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminPagination
                :current-page="audits.current_page"
                :last-page="audits.last_page"
                :total="audits.total"
                :base-url="securityIndex.url()"
                :query="{ tab: 'audits', search: filters.search }"
            />
        </section>

        <section
            v-if="activeTab === 'logins'"
            class="rounded-xl border border-border bg-card p-4"
        >
            <EmptyState
                v-if="logins.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-2 text-xs">
                <div
                    v-for="h in logins"
                    :key="h.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3"
                >
                    <div>
                        <Badge
                            :variant="
                                h.event_type === 'failed'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                            class="text-[10px]"
                            >{{ h.event_type }}</Badge
                        >
                        <span class="ms-2 font-semibold">{{
                            h.username
                                ? `@${h.username}`
                                : h.email_attempted || '-'
                        }}</span>
                        <span class="ms-2 font-mono text-muted-foreground">{{
                            h.ip_address || '-'
                        }}</span>
                    </div>
                    <span class="text-muted-foreground">{{
                        formatDateTime(h.created_at, locale)
                    }}</span>
                </div>
            </div>
        </section>

        <section
            v-if="activeTab === 'sessions'"
            class="rounded-xl border border-border bg-card p-4"
        >
            <EmptyState
                v-if="sessions.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-2 text-xs">
                <div
                    v-for="s in sessions"
                    :key="s.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                >
                    <div>
                        <p class="font-semibold">
                            @{{ s.username }} ·
                            <span class="font-mono">{{ s.ip_address }}</span>
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ s.user_agent }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="destructive"
                        class="h-7 gap-1 text-[11px]"
                        @click="revokeSession(s.id)"
                    >
                        <LogOut class="size-3" />
                        <span>{{ t('admin.security.revokeSession') }}</span>
                    </Button>
                </div>
            </div>
        </section>

        <section
            v-if="activeTab === 'suspicious'"
            class="rounded-xl border border-border bg-card p-4"
        >
            <EmptyState
                v-if="suspicious.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-2 text-xs">
                <div
                    v-for="s in suspicious"
                    :key="s.ip_address"
                    class="flex items-center justify-between rounded-lg border border-destructive/30 bg-destructive-soft/40 p-3"
                >
                    <span class="font-mono font-semibold">{{
                        s.ip_address
                    }}</span>
                    <Badge variant="destructive"
                        >{{ s.attempts }} failed attempts (24h)</Badge
                    >
                </div>
            </div>
        </section>

        <section
            v-if="activeTab === 'webhooks'"
            class="rounded-xl border border-border bg-card p-4"
        >
            <EmptyState
                v-if="webhooks.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="space-y-2 text-xs">
                <div
                    v-for="w in webhooks"
                    :key="w.id"
                    class="flex items-center justify-between rounded-lg border border-border p-3"
                >
                    <div>
                        <span class="font-semibold uppercase">{{
                            w.provider
                        }}</span>
                        <Badge
                            :variant="
                                w.signature_valid ? 'secondary' : 'destructive'
                            "
                            class="ms-2 text-[10px]"
                        >
                            {{ w.signature_valid ? 'HMAC OK' : 'INVALID SIG' }}
                        </Badge>
                        <span class="ms-2 text-muted-foreground">{{
                            w.status
                        }}</span>
                    </div>
                    <span class="text-muted-foreground">{{
                        formatDateTime(w.created_at, locale)
                    }}</span>
                </div>
            </div>
        </section>
    </div>
</template>
