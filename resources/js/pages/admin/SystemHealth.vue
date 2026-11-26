<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    Clock,
    Database,
    HardDrive,
    HeartPulse,
    Layers,
    Server,
    ShieldAlert,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AdminStatCard from '@/components/admin/AdminStatCard.vue';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatDateTime, formatNumber } from '@/lib/formatters';

defineProps<{
    health: {
        app_status?: string;
        db_ok: boolean;
        cache_ok?: boolean;
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
        trusted_proxies_configured?: boolean;
        pending_jobs: number;
        failed_jobs: number;
        active_sessions_30m: number;
        media_files_count: number;
        media_storage_bytes: number;
        disk_free_bytes?: number | null;
        disk_total_bytes?: number | null;
        backups_count: number;
        last_backup_at: string | null;
        last_backup_type?: string | null;
        last_backup_status?: string;
        cron_last_heartbeat: string | null;
        integrations?: {
            payment_methods_enabled: string[];
            webhook_secret_configured: boolean;
            gsc_connected: boolean;
            ai_healthy_keys: number;
            ai_emergency_stopped: boolean;
        };
        security_alerts?: {
            failed_logins_24h: number;
            pending_fraud_reviews: number;
        };
        host_telemetry?: {
            cpu_usage: number | null;
            ram_usage: number | null;
            php_workers: number | null;
            note: string;
        };
    };
}>();

const { t, locale } = useI18n();

function backupStatusLabel(status?: string): string {
    const map: Record<string, string> = {
        completed: 'admin.healthExtra.backupStatusCompleted',
        failed: 'admin.healthExtra.backupStatusFailed',
        running: 'admin.healthExtra.backupStatusRunning',
        none: 'admin.healthExtra.backupStatusNone',
    };
    const key = status ? map[status] : map.none;

    return key ? t(key) : status || t('admin.healthExtra.backupStatusNone');
}
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
                :value="
                    health.db_ok
                        ? t('admin.healthExtra.latencyMs', {
                              ms: health.db_latency_ms,
                          })
                        : t('admin.healthExtra.errorCode')
                "
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
                :hint="
                    t('admin.healthExtra.storageHint', {
                        count: formatNumber(health.media_files_count, locale),
                        disk: health.media_disk,
                    })
                "
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
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.runtimeStack') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            t('admin.healthExtra.runtimeVersions', {
                                php: health.php_version,
                                laravel: health.laravel_version,
                            })
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.health.environment') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            t('admin.healthExtra.envWithDebug', {
                                env: health.app_env,
                                status: health.app_debug
                                    ? t('admin.healthExtra.debugOn')
                                    : t('admin.healthExtra.debugOff'),
                            })
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.hashing') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.hashing_driver }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.sessionCache') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.session_driver }} / {{ health.cache_store }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.queueMediaDisk') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ health.queue_connection }} / {{ health.media_disk }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.activeSessions30m') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ formatNumber(health.active_sessions_30m, locale) }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.cacheProbeProxies') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            t('admin.healthExtra.cacheProxiesValue', {
                                cache: health.cache_ok
                                    ? t('admin.healthExtra.statusOk')
                                    : t('admin.healthExtra.statusDegraded'),
                                proxies: health.trusted_proxies_configured
                                    ? t('admin.healthExtra.configured')
                                    : t('admin.healthExtra.directDefault'),
                            })
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.lastBackup') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            t('admin.healthExtra.lastBackupValue', {
                                time: health.last_backup_at
                                    ? formatDateTime(
                                          health.last_backup_at,
                                          locale,
                                      )
                                    : t('admin.healthExtra.noneYet'),
                                status: backupStatusLabel(
                                    health.last_backup_status,
                                ),
                            })
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        {{ t('admin.healthExtra.diskFreeSpace') }}
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            health.disk_free_bytes != null
                                ? formatBytes(health.disk_free_bytes, locale)
                                : t('admin.healthExtra.unavailable')
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-border bg-card p-5">
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Activity class="size-4 text-primary" aria-hidden="true" />
                    <span>{{
                        t('admin.healthExtra.integrationsAndAlerts')
                    }}</span>
                </h2>
                <div class="mt-4 space-y-2.5 text-xs">
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.paymentMethodsActive')
                        }}</span>
                        <span class="font-mono font-semibold">
                            {{
                                health.integrations?.payment_methods_enabled?.join(
                                    ', ',
                                ) || t('admin.healthExtra.none')
                            }}
                        </span>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.webhookHmacSecret')
                        }}</span>
                        <Badge
                            :variant="
                                health.integrations?.webhook_secret_configured
                                    ? 'secondary'
                                    : 'destructive'
                            "
                        >
                            {{
                                health.integrations?.webhook_secret_configured
                                    ? t('admin.healthExtra.configured')
                                    : t('admin.healthExtra.missing')
                            }}
                        </Badge>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.gscApi')
                        }}</span>
                        <Badge
                            :variant="
                                health.integrations?.gsc_connected
                                    ? 'secondary'
                                    : 'outline'
                            "
                        >
                            {{
                                health.integrations?.gsc_connected
                                    ? t('admin.healthExtra.connected')
                                    : t('admin.healthExtra.notConfigured')
                            }}
                        </Badge>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.aiProvidersEmergencyStop')
                        }}</span>
                        <span class="font-mono font-semibold">
                            {{
                                t('admin.healthExtra.aiProvidersValue', {
                                    count:
                                        health.integrations?.ai_healthy_keys ??
                                        0,
                                    status: health.integrations
                                        ?.ai_emergency_stopped
                                        ? t('admin.healthExtra.statusStopped')
                                        : t('admin.healthExtra.statusReady'),
                                })
                            }}
                        </span>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 p-3"
                    >
                        <span
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <ShieldAlert class="size-3.5 text-amber-500" />
                            {{ t('admin.healthExtra.securityAlertsLabel') }}
                        </span>
                        <span class="font-mono font-semibold">
                            {{ health.security_alerts?.failed_logins_24h ?? 0 }}
                            /
                            {{
                                health.security_alerts?.pending_fraud_reviews ??
                                0
                            }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Server class="size-4 text-primary" aria-hidden="true" />
                    <span>{{ t('admin.healthExtra.hostTelemetryTitle') }}</span>
                </h2>
                <div class="mt-4 space-y-2.5 text-xs">
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 bg-muted/30 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.cpuUsage')
                        }}</span>
                        <Badge variant="outline">{{
                            t('admin.healthExtra.unavailable')
                        }}</Badge>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 bg-muted/30 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.ramUsage')
                        }}</span>
                        <Badge variant="outline">{{
                            t('admin.healthExtra.unavailable')
                        }}</Badge>
                    </div>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/80 bg-muted/30 p-3"
                    >
                        <span class="text-muted-foreground">{{
                            t('admin.healthExtra.phpWorkers')
                        }}</span>
                        <Badge variant="outline">{{
                            t('admin.healthExtra.unavailable')
                        }}</Badge>
                    </div>
                    <p
                        class="rounded-lg border border-border/60 bg-muted/20 p-3 text-muted-foreground"
                    >
                        {{ health.host_telemetry?.note }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
