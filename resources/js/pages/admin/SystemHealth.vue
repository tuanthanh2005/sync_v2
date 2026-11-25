<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Clock,
    Database,
    HardDrive,
    HeartPulse,
    Layers,
    Server,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import { formatBytes, formatDateTime, formatNumber } from '@/lib/formatters';

defineProps<{
    health: {
        db_ok: boolean;
        db_connection: string;
        db_latency_ms: number;
        php_version: string;
        laravel_version: string;
        app_env: string;
        app_debug: boolean;
        session_driver: string;
        cache_store: string;
        queue_connection: string;
        media_disk: string;
        hashing_driver: string;
        pending_jobs: number;
        failed_jobs: number;
        active_sessions_30m: number;
        media_files_count: number;
        media_storage_bytes: number;
        backups_count: number;
        last_backup_at: string | null;
        cron_last_heartbeat: string | null;
    };
}>();

const { t, locale } = useI18n();
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.health.title')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <HeartPulse class="size-5 text-primary" aria-hidden="true" />
                <span>{{ t('admin.health.title') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.health.subtitle') }}
            </p>
        </div>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AdminStatCard
                :label="t('admin.health.database')"
                :value="health.db_ok ? `${health.db_latency_ms} ms` : 'ERR'"
                :hint="health.db_connection"
                :icon="Database"
                tone="success"
            />
            <AdminStatCard
                :label="t('admin.health.pendingJobs')"
                :value="formatNumber(health.pending_jobs, locale)"
                :hint="`${t('admin.health.failedJobs')}: ${formatNumber(health.failed_jobs, locale)}`"
                :icon="Layers"
                :tone="health.failed_jobs > 0 ? 'destructive' : 'primary'"
            />
            <AdminStatCard
                :label="t('admin.health.storage')"
                :value="formatBytes(health.media_storage_bytes, locale)"
                :hint="`${formatNumber(health.media_files_count, locale)} files · disk: ${health.media_disk}`"
                :icon="HardDrive"
                tone="neutral"
            />
            <AdminStatCard
                :label="t('admin.health.cron')"
                :value="
                    health.cron_last_heartbeat
                        ? formatDateTime(health.cron_last_heartbeat, locale)
                        : '—'
                "
                :hint="`${t('admin.nav.backups')}: ${health.backups_count}`"
                :icon="Clock"
                tone="primary"
            />
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Server class="size-4 text-primary" aria-hidden="true" />
                <span>{{ t('admin.health.drivers') }}</span>
            </h2>
            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">PHP / Laravel</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        PHP {{ health.php_version }} · Laravel
                        {{ health.laravel_version }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.health.environment') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.app_env }} (debug:
                        {{ health.app_debug ? 'on' : 'off' }})
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Hashing</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.hashing_driver }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Session / Cache</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.session_driver }} / {{ health.cache_store }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Queue / Media Disk</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.queue_connection }} / {{ health.media_disk }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Active Sessions (30m)</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ formatNumber(health.active_sessions_30m, locale) }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
