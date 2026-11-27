<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    KeyRound,
    Lock,
    LogOut,
    Plus,
    RefreshCw,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    UserX,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/formatters';
import { index as securityIndex } from '@/routes/admin/security';
import {
    cleanupPending as cleanupPendingRoute,
    emergencyPause as emergencyPauseRoute,
    remediateUnverified as remediateUnverifiedRoute,
    settings as updateAuthProtectionRoute,
} from '@/routes/admin/security/auth-protection';
import {
    destroy as destroyDisposableDomainRoute,
    store as storeDisposableDomainRoute,
} from '@/routes/admin/security/auth-protection/disposable-domains';
import { revoke as revokeSessionRoute } from '@/routes/admin/security/sessions';

interface AuthProtectionPayload {
    metrics: {
        verified_users: number;
        unverified_users: number;
        pending_registrations: number;
        registrations_today: number;
        failed_logins_24h: number;
        otp_requests_24h: number;
        forgot_password_24h: number;
        captcha_failed_24h: number;
        blocked_registrations_24h: number;
        suspicious_registrations_24h: number;
        rate_limited_24h: number;
        security_incidents_24h: number;
    };
    config: {
        feature_registration: boolean;
        mandatory_email_verification: boolean;
        is_production: boolean;
        auth_emergency_registration_pause: boolean;
        auth_turnstile_enabled: boolean;
        auth_turnstile_site_key: string;
        turnstile_has_secret: boolean;
        turnstile_secret_masked: string | null;
        auth_otp_expire_minutes: number;
        auth_otp_resend_cooldown: number;
        auth_otp_max_attempts: number;
        auth_otp_max_per_email_hour: number;
        auth_global_otp_hourly_budget: number;
        auth_register_limit_10min: number;
        auth_register_limit_day: number;
        auth_login_failed_limit_min: number;
        auth_forgot_password_limit_15min: number;
        auth_disposable_email_protection: boolean;
        auth_suspicious_risk_threshold: number;
        auth_pending_retention_hours: number;
    };
    unverified_audit: {
        total_unverified: number;
        with_posts: number;
        with_payments: number;
        with_rewards: number;
        sessions_revoked: number;
        remember_tokens_rotated: number;
    };
    pending_list: Array<{
        id: number;
        name: string;
        username: string;
        email_masked: string;
        ip_address: string | null;
        risk_score: number;
        expires_at: string;
        created_at: string | null;
    }>;
    disposable_domains: Array<{
        id: number;
        domain: string;
        is_active: boolean;
        notes: string | null;
    }>;
    recent_events: Array<{
        id: number;
        event_type: string;
        severity: string;
        email_masked: string | null;
        ip_address: string | null;
        risk_score: number;
        details: Record<string, unknown> | null;
        created_at: string;
    }>;
}

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
    authProtection: AuthProtectionPayload;
}>();

const { t, locale } = useI18n();
const activeTab = ref(props.tab || 'protection');
const searchInput = ref(props.filters.search);
const showPauseConfirm = ref(false);
const showRemediateConfirm = ref(false);
const pauseProcessing = ref(false);
const remediateProcessing = ref(false);
const cleanupProcessing = ref(false);

const tabKeys = [
    'protection',
    'audits',
    'logins',
    'sessions',
    'suspicious',
    'webhooks',
] as const;

const protectionForm = useForm({
    feature_registration: props.authProtection.config.feature_registration,
    auth_emergency_registration_pause:
        props.authProtection.config.auth_emergency_registration_pause,
    auth_turnstile_enabled: props.authProtection.config.auth_turnstile_enabled,
    auth_turnstile_site_key:
        props.authProtection.config.auth_turnstile_site_key,
    auth_turnstile_secret: '',
    auth_otp_expire_minutes:
        props.authProtection.config.auth_otp_expire_minutes,
    auth_otp_resend_cooldown:
        props.authProtection.config.auth_otp_resend_cooldown,
    auth_otp_max_attempts: props.authProtection.config.auth_otp_max_attempts,
    auth_otp_max_per_email_hour:
        props.authProtection.config.auth_otp_max_per_email_hour,
    auth_global_otp_hourly_budget:
        props.authProtection.config.auth_global_otp_hourly_budget,
    auth_register_limit_10min:
        props.authProtection.config.auth_register_limit_10min,
    auth_register_limit_day:
        props.authProtection.config.auth_register_limit_day,
    auth_login_failed_limit_min:
        props.authProtection.config.auth_login_failed_limit_min,
    auth_forgot_password_limit_15min:
        props.authProtection.config.auth_forgot_password_limit_15min,
    auth_disposable_email_protection:
        props.authProtection.config.auth_disposable_email_protection,
    auth_suspicious_risk_threshold:
        props.authProtection.config.auth_suspicious_risk_threshold,
    auth_pending_retention_hours:
        props.authProtection.config.auth_pending_retention_hours,
});

watch(
    () => props.authProtection.config,
    (cfg) => {
        protectionForm.feature_registration = cfg.feature_registration;
        protectionForm.auth_emergency_registration_pause =
            cfg.auth_emergency_registration_pause;
        protectionForm.auth_turnstile_enabled = cfg.auth_turnstile_enabled;
        protectionForm.auth_turnstile_site_key = cfg.auth_turnstile_site_key;
        protectionForm.auth_otp_expire_minutes = cfg.auth_otp_expire_minutes;
        protectionForm.auth_otp_resend_cooldown = cfg.auth_otp_resend_cooldown;
        protectionForm.auth_otp_max_attempts = cfg.auth_otp_max_attempts;
        protectionForm.auth_otp_max_per_email_hour =
            cfg.auth_otp_max_per_email_hour;
        protectionForm.auth_global_otp_hourly_budget =
            cfg.auth_global_otp_hourly_budget;
        protectionForm.auth_register_limit_10min =
            cfg.auth_register_limit_10min;
        protectionForm.auth_register_limit_day = cfg.auth_register_limit_day;
        protectionForm.auth_login_failed_limit_min =
            cfg.auth_login_failed_limit_min;
        protectionForm.auth_forgot_password_limit_15min =
            cfg.auth_forgot_password_limit_15min;
        protectionForm.auth_disposable_email_protection =
            cfg.auth_disposable_email_protection;
        protectionForm.auth_suspicious_risk_threshold =
            cfg.auth_suspicious_risk_threshold;
        protectionForm.auth_pending_retention_hours =
            cfg.auth_pending_retention_hours;
    },
    { deep: true },
);

const domainForm = useForm({
    domain: '',
    notes: '',
});

const metricCards = computed(() => [
    {
        key: 'verified_users',
        label: t('admin.security.protection.metrics.verifiedUsers'),
        value: props.authProtection.metrics.verified_users,
        tone: 'text-success',
    },
    {
        key: 'unverified_users',
        label: t('admin.security.protection.metrics.unverifiedUsers'),
        value: props.authProtection.metrics.unverified_users,
        tone:
            props.authProtection.metrics.unverified_users > 0
                ? 'text-warning'
                : 'text-foreground',
    },
    {
        key: 'pending_registrations',
        label: t('admin.security.protection.metrics.pendingRegistrations'),
        value: props.authProtection.metrics.pending_registrations,
        tone: 'text-primary',
    },
    {
        key: 'registrations_today',
        label: t('admin.security.protection.metrics.registrationsToday'),
        value: props.authProtection.metrics.registrations_today,
        tone: 'text-foreground',
    },
    {
        key: 'failed_logins_24h',
        label: t('admin.security.protection.metrics.failedLogins24h'),
        value: props.authProtection.metrics.failed_logins_24h,
        tone:
            props.authProtection.metrics.failed_logins_24h > 20
                ? 'text-destructive'
                : 'text-foreground',
    },
    {
        key: 'otp_requests_24h',
        label: t('admin.security.protection.metrics.otpRequests24h'),
        value: props.authProtection.metrics.otp_requests_24h,
        tone: 'text-foreground',
    },
    {
        key: 'forgot_password_24h',
        label: t('admin.security.protection.metrics.forgotPassword24h'),
        value: props.authProtection.metrics.forgot_password_24h,
        tone: 'text-foreground',
    },
    {
        key: 'captcha_failed_24h',
        label: t('admin.security.protection.metrics.captchaFailed24h'),
        value: props.authProtection.metrics.captcha_failed_24h,
        tone:
            props.authProtection.metrics.captcha_failed_24h > 0
                ? 'text-warning'
                : 'text-foreground',
    },
    {
        key: 'blocked_registrations_24h',
        label: t('admin.security.protection.metrics.blockedRegistrations24h'),
        value: props.authProtection.metrics.blocked_registrations_24h,
        tone:
            props.authProtection.metrics.blocked_registrations_24h > 0
                ? 'text-destructive'
                : 'text-foreground',
    },
    {
        key: 'suspicious_registrations_24h',
        label: t(
            'admin.security.protection.metrics.suspiciousRegistrations24h',
        ),
        value: props.authProtection.metrics.suspicious_registrations_24h,
        tone:
            props.authProtection.metrics.suspicious_registrations_24h > 0
                ? 'text-warning'
                : 'text-foreground',
    },
    {
        key: 'rate_limited_24h',
        label: t('admin.security.protection.metrics.rateLimited24h'),
        value: props.authProtection.metrics.rate_limited_24h,
        tone: 'text-foreground',
    },
    {
        key: 'security_incidents_24h',
        label: t('admin.security.protection.metrics.securityIncidents24h'),
        value: props.authProtection.metrics.security_incidents_24h,
        tone:
            props.authProtection.metrics.security_incidents_24h > 0
                ? 'text-destructive'
                : 'text-success',
    },
]);

function loginEventLabel(eventType: string): string {
    const map: Record<string, string> = {
        login: 'admin.securityExtra.loginEvent.login',
        success: 'admin.securityExtra.loginEvent.success',
        failed: 'admin.securityExtra.loginEvent.failed',
        logout: 'admin.securityExtra.loginEvent.logout',
        lockout: 'admin.securityExtra.loginEvent.lockout',
    };
    const key = map[eventType.toLowerCase()];

    return key ? t(key) : eventType;
}

function webhookStatusLabel(status: string): string {
    const map: Record<string, string> = {
        processed: 'admin.securityExtra.webhookStatus.processed',
        received: 'admin.securityExtra.webhookStatus.received',
        failed: 'admin.securityExtra.webhookStatus.failed',
        ignored: 'admin.securityExtra.webhookStatus.ignored',
        rejected: 'admin.securityExtra.webhookStatus.rejected',
    };
    const key = map[status.toLowerCase()];

    return key ? t(key) : status;
}

function severityVariant(
    severity: string,
): 'destructive' | 'secondary' | 'outline' {
    if (severity === 'critical' || severity === 'high') {
        return 'destructive';
    }
    if (severity === 'warning' || severity === 'medium') {
        return 'outline';
    }

    return 'secondary';
}

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

function saveAuthProtectionSettings(): void {
    protectionForm.post(updateAuthProtectionRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            protectionForm.auth_turnstile_secret = '';
        },
    });
}

function confirmEmergencyPauseToggle(): void {
    pauseProcessing.value = true;
    const nextPaused =
        !props.authProtection.config.auth_emergency_registration_pause;

    router.post(
        emergencyPauseRoute.url(),
        { paused: nextPaused },
        {
            preserveScroll: true,
            onFinish: () => {
                pauseProcessing.value = false;
                showPauseConfirm.value = false;
            },
        },
    );
}

function confirmRemediateUnverified(): void {
    remediateProcessing.value = true;
    router.post(
        remediateUnverifiedRoute.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                remediateProcessing.value = false;
                showRemediateConfirm.value = false;
            },
        },
    );
}

function runCleanupPending(): void {
    cleanupProcessing.value = true;
    router.post(
        cleanupPendingRoute.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cleanupProcessing.value = false;
            },
        },
    );
}

function addDisposableDomain(): void {
    domainForm.post(storeDisposableDomainRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            domainForm.reset();
        },
    });
}

function removeDisposableDomain(id: number): void {
    router.delete(destroyDisposableDomainRoute.url(id), {
        preserveScroll: true,
    });
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
                    v-for="tk in tabKeys"
                    :key="tk"
                    size="sm"
                    :variant="activeTab === tk ? 'default' : 'ghost'"
                    class="h-8 rounded-lg text-xs"
                    @click="activeTab = tk"
                >
                    {{ t(`admin.security.tabs.${tk}`) }}
                </Button>
            </div>
            <form
                v-if="activeTab !== 'protection'"
                @submit.prevent="applySearch"
                class="flex items-center gap-2"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        :placeholder="t('admin.common.search')"
                        class="h-8 rounded-lg border border-input bg-background ps-8 pe-3 text-xs"
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

        <!-- Authentication Protection & Anti-Bot Tab -->
        <section v-if="activeTab === 'protection'" class="space-y-5">
            <!-- Emergency Registration Pause Banner -->
            <div
                class="flex flex-col justify-between gap-4 rounded-xl border p-4 sm:flex-row sm:items-center"
                :class="
                    authProtection.config.auth_emergency_registration_pause
                        ? 'border-destructive/40 bg-destructive-soft/50'
                        : 'border-border bg-card'
                "
            >
                <div class="flex items-start gap-3">
                    <ShieldAlert
                        class="mt-0.5 size-5 shrink-0"
                        :class="
                            authProtection.config
                                .auth_emergency_registration_pause
                                ? 'text-destructive'
                                : 'text-primary'
                        "
                    />
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-semibold text-foreground">
                                {{
                                    t(
                                        'admin.security.protection.emergencyPauseTitle',
                                    )
                                }}
                            </h2>
                            <Badge
                                :variant="
                                    authProtection.config
                                        .auth_emergency_registration_pause
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                class="text-[10px]"
                            >
                                {{
                                    authProtection.config
                                        .auth_emergency_registration_pause
                                        ? t(
                                              'admin.security.protection.pauseActiveBadge',
                                          )
                                        : t(
                                              'admin.security.protection.pauseInactiveBadge',
                                          )
                                }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'admin.security.protection.emergencyPauseDesc',
                                )
                            }}
                        </p>
                    </div>
                </div>
                <Button
                    size="sm"
                    :variant="
                        authProtection.config.auth_emergency_registration_pause
                            ? 'outline'
                            : 'destructive'
                    "
                    class="shrink-0 rounded-lg text-xs"
                    @click="showPauseConfirm = true"
                >
                    {{
                        authProtection.config.auth_emergency_registration_pause
                            ? t('admin.security.protection.liftPauseBtn')
                            : t('admin.security.protection.activatePauseBtn')
                    }}
                </Button>
            </div>

            <!-- Real-time Telemetry KPIs -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <div
                    v-for="card in metricCards"
                    :key="card.key"
                    class="rounded-xl border border-border bg-card p-3.5"
                >
                    <p class="text-[11px] font-medium text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        :class="card.tone"
                    >
                        {{ card.value.toLocaleString(locale) }}
                    </p>
                </div>
            </div>

            <!-- Settings & Remediation Grid -->
            <div class="grid gap-5 lg:grid-cols-3">
                <!-- Protection Configuration Form -->
                <form
                    class="space-y-5 rounded-xl border border-border bg-card p-5 lg:col-span-2"
                    @submit.prevent="saveAuthProtectionSettings"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <h2
                                class="flex items-center gap-2 text-sm font-semibold text-foreground"
                            >
                                <KeyRound class="size-4 text-primary" />
                                <span>{{
                                    t('admin.security.protection.configTitle')
                                }}</span>
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ t('admin.security.protection.configDesc') }}
                            </p>
                        </div>
                        <Badge
                            variant="secondary"
                            class="gap-1 text-[11px] text-success"
                        >
                            <Lock class="size-3" />
                            <span>{{
                                t(
                                    'admin.security.protection.mandatoryVerificationLocked',
                                )
                            }}</span>
                        </Badge>
                    </div>

                    <!-- Core Toggles -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border p-3 text-xs"
                        >
                            <input
                                v-model="protectionForm.feature_registration"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-input accent-primary"
                            />
                            <span>
                                <span class="block font-medium text-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.allowRegistration',
                                        )
                                    }}
                                </span>
                                <span class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.allowRegistrationHint',
                                        )
                                    }}
                                </span>
                            </span>
                        </label>

                        <label
                            class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border p-3 text-xs"
                        >
                            <input
                                v-model="
                                    protectionForm.auth_disposable_email_protection
                                "
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-input accent-primary"
                            />
                            <span>
                                <span class="block font-medium text-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.blockDisposableEmail',
                                        )
                                    }}
                                </span>
                                <span class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.blockDisposableEmailHint',
                                        )
                                    }}
                                </span>
                            </span>
                        </label>
                    </div>

                    <!-- Cloudflare Turnstile -->
                    <div
                        class="space-y-3 rounded-xl border border-border bg-muted/20 p-4"
                    >
                        <label
                            class="flex cursor-pointer items-center justify-between gap-2 text-xs font-semibold text-foreground"
                        >
                            <span class="flex items-center gap-2">
                                <ShieldCheck class="size-4 text-primary" />
                                <span>{{
                                    t(
                                        'admin.security.protection.turnstileEnabled',
                                    )
                                }}</span>
                            </span>
                            <input
                                v-model="protectionForm.auth_turnstile_enabled"
                                type="checkbox"
                                class="size-4 rounded border-input accent-primary"
                            />
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label
                                    for="turnstile_site_key"
                                    class="text-xs"
                                    >{{
                                        t(
                                            'admin.security.protection.turnstileSiteKey',
                                        )
                                    }}</Label
                                >
                                <Input
                                    id="turnstile_site_key"
                                    v-model="
                                        protectionForm.auth_turnstile_site_key
                                    "
                                    type="text"
                                    class="h-8 font-mono text-xs"
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="
                                        protectionForm.errors
                                            .auth_turnstile_site_key
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="turnstile_secret" class="text-xs">{{
                                    t(
                                        'admin.security.protection.turnstileSecretKey',
                                    )
                                }}</Label>
                                <Input
                                    id="turnstile_secret"
                                    v-model="
                                        protectionForm.auth_turnstile_secret
                                    "
                                    type="password"
                                    class="h-8 font-mono text-xs"
                                    autocomplete="new-password"
                                    :placeholder="
                                        authProtection.config
                                            .turnstile_secret_masked ??
                                        t(
                                            'admin.security.protection.turnstileSecretPlaceholder',
                                        )
                                    "
                                />
                                <p class="text-[11px] text-muted-foreground">
                                    {{
                                        authProtection.config
                                            .turnstile_has_secret
                                            ? t(
                                                  'admin.security.protection.turnstileSecretConfigured',
                                              )
                                            : t(
                                                  'admin.security.protection.turnstileSecretMissing',
                                              )
                                    }}
                                </p>
                                <InputError
                                    :message="
                                        protectionForm.errors
                                            .auth_turnstile_secret
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <!-- OTP & Rate-Limiting Numeric Controls -->
                    <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                        <div class="grid gap-1">
                            <Label for="otp_expire" class="text-xs">{{
                                t('admin.security.protection.otpExpireMinutes')
                            }}</Label>
                            <Input
                                id="otp_expire"
                                v-model.number="
                                    protectionForm.auth_otp_expire_minutes
                                "
                                type="number"
                                min="3"
                                max="15"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="otp_cooldown" class="text-xs">{{
                                t('admin.security.protection.otpResendCooldown')
                            }}</Label>
                            <Input
                                id="otp_cooldown"
                                v-model.number="
                                    protectionForm.auth_otp_resend_cooldown
                                "
                                type="number"
                                min="30"
                                max="300"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="otp_attempts" class="text-xs">{{
                                t('admin.security.protection.otpMaxAttempts')
                            }}</Label>
                            <Input
                                id="otp_attempts"
                                v-model.number="
                                    protectionForm.auth_otp_max_attempts
                                "
                                type="number"
                                min="3"
                                max="10"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="otp_email_hr" class="text-xs">{{
                                t('admin.security.protection.otpMaxEmailHour')
                            }}</Label>
                            <Input
                                id="otp_email_hr"
                                v-model.number="
                                    protectionForm.auth_otp_max_per_email_hour
                                "
                                type="number"
                                min="2"
                                max="15"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="otp_budget" class="text-xs">{{
                                t('admin.security.protection.globalOtpBudget')
                            }}</Label>
                            <Input
                                id="otp_budget"
                                v-model.number="
                                    protectionForm.auth_global_otp_hourly_budget
                                "
                                type="number"
                                min="20"
                                max="5000"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="reg_10min" class="text-xs">{{
                                t('admin.security.protection.registerLimit10m')
                            }}</Label>
                            <Input
                                id="reg_10min"
                                v-model.number="
                                    protectionForm.auth_register_limit_10min
                                "
                                type="number"
                                min="1"
                                max="20"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="reg_day" class="text-xs">{{
                                t('admin.security.protection.registerLimitDay')
                            }}</Label>
                            <Input
                                id="reg_day"
                                v-model.number="
                                    protectionForm.auth_register_limit_day
                                "
                                type="number"
                                min="3"
                                max="50"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="login_min" class="text-xs">{{
                                t('admin.security.protection.loginFailLimitMin')
                            }}</Label>
                            <Input
                                id="login_min"
                                v-model.number="
                                    protectionForm.auth_login_failed_limit_min
                                "
                                type="number"
                                min="3"
                                max="20"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="forgot_15m" class="text-xs">{{
                                t(
                                    'admin.security.protection.forgotPasswordLimit15m',
                                )
                            }}</Label>
                            <Input
                                id="forgot_15m"
                                v-model.number="
                                    protectionForm.auth_forgot_password_limit_15min
                                "
                                type="number"
                                min="1"
                                max="10"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="risk_threshold" class="text-xs">{{
                                t('admin.security.protection.riskThreshold')
                            }}</Label>
                            <Input
                                id="risk_threshold"
                                v-model.number="
                                    protectionForm.auth_suspicious_risk_threshold
                                "
                                type="number"
                                min="20"
                                max="100"
                                class="h-8 text-xs"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="pending_hours" class="text-xs">{{
                                t(
                                    'admin.security.protection.pendingRetentionHours',
                                )
                            }}</Label>
                            <Input
                                id="pending_hours"
                                v-model.number="
                                    protectionForm.auth_pending_retention_hours
                                "
                                type="number"
                                min="6"
                                max="72"
                                class="h-8 text-xs"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            class="rounded-lg text-xs"
                            :disabled="protectionForm.processing"
                        >
                            <Spinner v-if="protectionForm.processing" />
                            <span>{{
                                t('admin.security.protection.saveSettingsBtn')
                            }}</span>
                        </Button>
                    </div>
                </form>

                <!-- Unverified Remediation & Maintenance Column -->
                <div class="space-y-4">
                    <div
                        class="space-y-3 rounded-xl border border-border bg-card p-5"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <UserX class="size-4 text-warning" />
                            <span>{{
                                t('admin.security.protection.remediationTitle')
                            }}</span>
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ t('admin.security.protection.remediationDesc') }}
                        </p>
                        <dl
                            class="space-y-1.5 rounded-lg bg-muted/40 p-3 text-xs"
                        >
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.auditUnverifiedTotal',
                                        )
                                    }}
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{
                                        authProtection.unverified_audit
                                            .total_unverified
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.auditWithPosts',
                                        )
                                    }}
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{
                                        authProtection.unverified_audit
                                            .with_posts
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.auditWithPayments',
                                        )
                                    }}
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{
                                        authProtection.unverified_audit
                                            .with_payments
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    {{
                                        t(
                                            'admin.security.protection.auditWithRewards',
                                        )
                                    }}
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{
                                        authProtection.unverified_audit
                                            .with_rewards
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <div class="flex flex-col gap-2">
                            <Button
                                size="sm"
                                variant="destructive"
                                class="w-full justify-center gap-1.5 rounded-lg text-xs"
                                :disabled="remediateProcessing"
                                @click="showRemediateConfirm = true"
                            >
                                <LogOut class="size-3.5" />
                                <span>{{
                                    t(
                                        'admin.security.protection.revokeUnverifiedBtn',
                                    )
                                }}</span>
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="w-full justify-center gap-1.5 rounded-lg text-xs"
                                :disabled="cleanupProcessing"
                                @click="runCleanupPending"
                            >
                                <RefreshCw
                                    class="size-3.5"
                                    :class="{
                                        'animate-spin': cleanupProcessing,
                                    }"
                                />
                                <span>{{
                                    t(
                                        'admin.security.protection.cleanupPendingBtn',
                                    )
                                }}</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Disposable Email Blocklist -->
                    <div
                        class="space-y-3 rounded-xl border border-border bg-card p-5"
                    >
                        <h2 class="text-sm font-semibold text-foreground">
                            {{
                                t(
                                    'admin.security.protection.disposableDomainsTitle',
                                )
                            }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'admin.security.protection.disposableDomainsDesc',
                                )
                            }}
                        </p>
                        <form
                            class="space-y-2"
                            @submit.prevent="addDisposableDomain"
                        >
                            <Input
                                v-model="domainForm.domain"
                                type="text"
                                class="h-8 font-mono text-xs"
                                :placeholder="
                                    t(
                                        'admin.security.protection.domainPlaceholder',
                                    )
                                "
                                required
                            />
                            <InputError :message="domainForm.errors.domain" />
                            <div class="flex gap-2">
                                <Input
                                    v-model="domainForm.notes"
                                    type="text"
                                    class="h-8 text-xs"
                                    :placeholder="
                                        t(
                                            'admin.security.protection.domainNotesPlaceholder',
                                        )
                                    "
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    class="h-8 shrink-0 gap-1 rounded-lg text-xs"
                                    :disabled="domainForm.processing"
                                >
                                    <Plus class="size-3.5" />
                                    <span>{{
                                        t(
                                            'admin.security.protection.addDomainBtn',
                                        )
                                    }}</span>
                                </Button>
                            </div>
                        </form>

                        <div
                            v-if="authProtection.disposable_domains.length > 0"
                            class="max-h-48 space-y-1.5 overflow-y-auto pt-1 text-xs"
                        >
                            <div
                                v-for="dom in authProtection.disposable_domains"
                                :key="dom.id"
                                class="flex items-center justify-between gap-2 rounded-lg border border-border px-2.5 py-1.5"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="truncate font-mono font-medium text-foreground"
                                    >
                                        {{ dom.domain }}
                                    </p>
                                    <p
                                        v-if="dom.notes"
                                        class="truncate text-[11px] text-muted-foreground"
                                    >
                                        {{ dom.notes }}
                                    </p>
                                </div>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    class="size-7 shrink-0 text-destructive hover:text-destructive"
                                    :aria-label="
                                        t(
                                            'admin.security.protection.removeDomainAria',
                                        )
                                    "
                                    @click="removeDisposableDomain(dom.id)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                        <p v-else class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'admin.security.protection.disposableBuiltInOnly',
                                )
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Active Pending Registrations & Recent Events -->
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold text-foreground">
                        {{ t('admin.security.protection.pendingQueueTitle') }}
                    </h2>
                    <EmptyState
                        v-if="authProtection.pending_list.length === 0"
                        :title="t('admin.common.emptyTitle')"
                        :description="
                            t('admin.security.protection.pendingQueueEmpty')
                        "
                    />
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-start text-xs">
                            <thead
                                class="border-b border-border text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-2 pe-3 text-start">
                                        {{
                                            t(
                                                'admin.security.protection.colAccount',
                                            )
                                        }}
                                    </th>
                                    <th class="py-2 pe-3 text-start">
                                        {{ t('admin.securityExtra.colIp') }}
                                    </th>
                                    <th class="py-2 pe-3 text-start">
                                        {{
                                            t(
                                                'admin.security.protection.colRisk',
                                            )
                                        }}
                                    </th>
                                    <th class="py-2 text-start">
                                        {{
                                            t(
                                                'admin.security.protection.colExpires',
                                            )
                                        }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr
                                    v-for="p in authProtection.pending_list"
                                    :key="p.id"
                                >
                                    <td class="py-2 pe-3">
                                        <p class="font-medium text-foreground">
                                            {{ p.email_masked }}
                                        </p>
                                        <p
                                            class="text-[11px] text-muted-foreground"
                                        >
                                            @{{ p.username }}
                                        </p>
                                    </td>
                                    <td
                                        class="py-2 pe-3 font-mono text-muted-foreground"
                                    >
                                        {{ p.ip_address || '-' }}
                                    </td>
                                    <td class="py-2 pe-3">
                                        <Badge
                                            :variant="
                                                p.risk_score >=
                                                authProtection.config
                                                    .auth_suspicious_risk_threshold
                                                    ? 'destructive'
                                                    : 'secondary'
                                            "
                                            class="text-[10px]"
                                        >
                                            {{ p.risk_score }}
                                        </Badge>
                                    </td>
                                    <td class="py-2 text-muted-foreground">
                                        {{
                                            formatDateTime(p.expires_at, locale)
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold text-foreground">
                        {{ t('admin.security.protection.recentEventsTitle') }}
                    </h2>
                    <EmptyState
                        v-if="authProtection.recent_events.length === 0"
                        :title="t('admin.common.emptyTitle')"
                        :description="
                            t('admin.security.protection.recentEventsEmpty')
                        "
                    />
                    <div
                        v-else
                        class="max-h-96 space-y-2 overflow-y-auto text-xs"
                    >
                        <div
                            v-for="ev in authProtection.recent_events"
                            :key="ev.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2.5"
                        >
                            <div class="min-w-0 space-y-0.5">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <Badge
                                        :variant="severityVariant(ev.severity)"
                                        class="text-[10px]"
                                    >
                                        {{ ev.severity }}
                                    </Badge>
                                    <span
                                        class="font-mono font-semibold text-foreground"
                                    >
                                        {{ ev.event_type }}
                                    </span>
                                    <span
                                        v-if="ev.risk_score > 0"
                                        class="inline-flex items-center gap-0.5 text-[11px] text-warning"
                                    >
                                        <AlertTriangle class="size-3" />
                                        {{ ev.risk_score }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    <span v-if="ev.email_masked" class="me-2">{{
                                        ev.email_masked
                                    }}</span>
                                    <span class="font-mono">{{
                                        ev.ip_address || '-'
                                    }}</span>
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-[11px] text-muted-foreground"
                            >
                                {{ formatDateTime(ev.created_at, locale) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

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
                <table class="w-full text-start text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.securityExtra.colAction') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.securityExtra.colActor') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.securityExtra.colTarget') }}
                            </th>
                            <th class="py-2 pe-3 text-start">
                                {{ t('admin.securityExtra.colIp') }}
                            </th>
                            <th class="py-2 text-start">
                                {{ t('admin.common.createdAt') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="a in audits.data" :key="a.id">
                            <td class="py-2.5 pe-3 font-mono font-semibold">
                                {{ a.action }}
                            </td>
                            <td class="py-2.5 pe-3">
                                {{
                                    a.actor_username
                                        ? `@${a.actor_username}`
                                        : t('admin.securityExtra.systemActor')
                                }}
                            </td>
                            <td class="py-2.5 pe-3 text-muted-foreground">
                                {{
                                    a.auditable_type
                                        ? `${a.auditable_type}#${a.auditable_id}`
                                        : '—'
                                }}
                            </td>
                            <td
                                class="py-2.5 pe-3 font-mono text-muted-foreground"
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
                            >{{ loginEventLabel(h.event_type) }}</Badge
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
                    <Badge variant="destructive">{{
                        t('admin.securityExtra.failedAttempts24h', {
                            count: s.attempts,
                        })
                    }}</Badge>
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
                            {{
                                w.signature_valid
                                    ? t('admin.securityExtra.hmacOk')
                                    : t('admin.securityExtra.invalidSig')
                            }}
                        </Badge>
                        <span class="ms-2 text-muted-foreground">{{
                            webhookStatusLabel(w.status)
                        }}</span>
                    </div>
                    <span class="text-muted-foreground">{{
                        formatDateTime(w.created_at, locale)
                    }}</span>
                </div>
            </div>
        </section>

        <ConfirmActionDialog
            v-model:open="showPauseConfirm"
            :title="
                authProtection.config.auth_emergency_registration_pause
                    ? t('admin.security.protection.liftPauseConfirmTitle')
                    : t('admin.security.protection.activatePauseConfirmTitle')
            "
            :description="
                authProtection.config.auth_emergency_registration_pause
                    ? t('admin.security.protection.liftPauseConfirmDesc')
                    : t('admin.security.protection.activatePauseConfirmDesc')
            "
            :destructive="
                !authProtection.config.auth_emergency_registration_pause
            "
            :processing="pauseProcessing"
            @confirm="confirmEmergencyPauseToggle"
        />

        <ConfirmActionDialog
            v-model:open="showRemediateConfirm"
            :title="t('admin.security.protection.remediateConfirmTitle')"
            :description="t('admin.security.protection.remediateConfirmDesc')"
            :destructive="true"
            :processing="remediateProcessing"
            @confirm="confirmRemediateUnverified"
        />
    </div>
</template>
