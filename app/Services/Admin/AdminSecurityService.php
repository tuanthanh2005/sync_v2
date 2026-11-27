<?php

namespace App\Services\Admin;

use App\Enums\AccountType;
use App\Models\AuditLog;
use App\Models\AuthSecurityEvent;
use App\Models\BackupRecord;
use App\Models\DisposableEmailDomain;
use App\Models\FooterLink;
use App\Models\Language;
use App\Models\LoginHistory;
use App\Models\PaymentWebhookLog;
use App\Models\PendingRegistration;
use App\Models\PolicyPage;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Auth\DisposableEmailService;
use App\Services\Auth\PendingRegistrationService;
use App\Services\Auth\TurnstileService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSecurityService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SystemSettingService $settings,
        private readonly TurnstileService $turnstile,
        private readonly PendingRegistrationService $pendingRegistrations,
        private readonly AuthSecurityEventService $authEvents,
    ) {}

    /**
     * @param  array{tab?: string, search?: string, action?: string}  $filters
     * @return array<string, mixed>
     */
    public function securityPayload(array $filters): array
    {
        $tab = (string) ($filters['tab'] ?? 'audits');
        $search = trim((string) ($filters['search'] ?? ''));
        $actionFilter = trim((string) ($filters['action'] ?? ''));

        $auditsQuery = AuditLog::query()->with(['actor:id,username']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $auditsQuery->where(static function (Builder $q) use ($pattern): void {
                $q->where('action', 'like', $pattern)
                    ->orWhere('ip_address', 'like', $pattern)
                    ->orWhereHas('actor', static fn (Builder $aq) => $aq->where('username', 'like', $pattern));
            });
        }

        if ($actionFilter !== '') {
            $auditsQuery->where('action', 'like', addcslashes($actionFilter, '\\%_').'%');
        }

        $audits = $auditsQuery->latest('id')->paginate(30)->withQueryString();

        $logins = LoginHistory::query()
            ->with(['user:id,username'])
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(static fn (LoginHistory $h): array => [
                'id' => $h->id,
                'username' => $h->user?->username,
                'email_attempted' => $h->email_attempted,
                'event_type' => $h->event_type,
                'ip_address' => $h->ip_address,
                'user_agent' => Str::limit((string) $h->user_agent, 120),
                'created_at' => $h->created_at->toIso8601String(),
            ])->values()->all();

        $suspicious = DB::table('login_histories')
            ->where('event_type', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('attempts')
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'ip_address' => (string) ($row->ip_address ?? '-'),
                'attempts' => (int) $row->attempts,
                'last_attempt' => (string) $row->last_attempt,
            ])->values()->all();

        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')
                ->leftJoin('users', 'users.id', '=', 'sessions.user_id')
                ->whereNotNull('sessions.user_id')
                ->orderByDesc('sessions.last_activity')
                ->limit(40)
                ->get(['sessions.id', 'sessions.user_id', 'users.username', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'user_id' => (int) $row->user_id,
                    'username' => (string) ($row->username ?? '-'),
                    'ip_address' => (string) ($row->ip_address ?? '-'),
                    'user_agent' => Str::limit((string) ($row->user_agent ?? ''), 100),
                    'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                ])->values()->all()
            : [];

        $webhooks = Schema::hasTable('payment_webhook_logs')
            ? PaymentWebhookLog::query()->latest('id')->limit(40)->get()->map(static fn (PaymentWebhookLog $w): array => [
                'id' => $w->id,
                'provider' => $w->provider,
                'event_id' => $w->event_id,
                'status' => $w->status,
                'signature_valid' => $w->signature_valid,
                'ip_address' => $w->ip_address,
                'error_message' => $w->error_message,
                'created_at' => $w->created_at->toIso8601String(),
            ])->values()->all()
            : [];

        return [
            'tab' => $tab,
            'filters' => ['search' => $search, 'action' => $actionFilter],
            'audits' => [
                'data' => array_values(collect($audits->items())->map(static fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor_username' => $log->actor?->username,
                    'auditable_type' => $log->auditable_type !== null ? class_basename($log->auditable_type) : null,
                    'auditable_id' => $log->auditable_id,
                    'ip_address' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at->toIso8601String(),
                ])->all()),
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'total' => $audits->total(),
            ],
            'logins' => $logins,
            'suspicious' => $suspicious,
            'sessions' => $sessions,
            'webhooks' => $webhooks,
            'authProtection' => $this->authProtectionPayload(),
        ];
    }

    /**
     * Real-time Authentication Protection telemetry, configuration, and pending/unverified review queues.
     * Never exposes OTPs, password hashes, session tokens, or Turnstile secret keys.
     *
     * @return array<string, mixed>
     */
    public function authProtectionPayload(): array
    {
        $since24h = now()->subHours(24);
        $todayStart = now()->startOfDay();

        $humanUsers = static fn () => User::query()->where('account_type', AccountType::Human->value);
        $verifiedUsers = $humanUsers()->whereNotNull('email_verified_at')->count();
        $unverifiedUsers = $humanUsers()->whereNull('email_verified_at')->count();

        $pendingActive = Schema::hasTable('pending_registrations')
            ? PendingRegistration::query()
                ->where('status', PendingRegistration::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->count()
            : 0;

        $registrationsToday = $humanUsers()
            ->whereNotNull('email_verified_at')
            ->where('created_at', '>=', $todayStart)
            ->count();

        $failedLogins24h = LoginHistory::query()
            ->where('event_type', 'failed')
            ->where('created_at', '>=', $since24h)
            ->count();

        $eventCounts = [];
        $recentEvents = [];
        if (Schema::hasTable('auth_security_events')) {
            $eventCounts = AuthSecurityEvent::query()
                ->where('created_at', '>=', $since24h)
                ->selectRaw('event_type, COUNT(*) as total')
                ->groupBy('event_type')
                ->pluck('total', 'event_type')
                ->map(static fn ($v): int => (int) $v)
                ->all();

            $recentEvents = AuthSecurityEvent::query()
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(static fn (AuthSecurityEvent $e): array => [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'severity' => $e->severity,
                    'email_masked' => $e->email_masked,
                    'ip_address' => $e->ip_address,
                    'risk_score' => $e->risk_score,
                    'details' => $e->details,
                    'created_at' => $e->created_at->toIso8601String(),
                ])->values()->all();
        }

        $securityIncidents24h = Schema::hasTable('auth_security_events')
            ? AuthSecurityEvent::query()
                ->where('created_at', '>=', $since24h)
                ->whereIn('severity', ['high', 'critical'])
                ->count()
            : 0;

        $pendingList = Schema::hasTable('pending_registrations')
            ? PendingRegistration::query()
                ->where('status', PendingRegistration::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (PendingRegistration $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'username' => $p->username,
                    'email_masked' => $this->authEvents->maskEmail($p->normalized_email),
                    'ip_address' => $p->ip_address,
                    'risk_score' => $p->risk_score,
                    'expires_at' => $p->expires_at->toIso8601String(),
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->values()->all()
            : [];

        $disposableDomains = Schema::hasTable('disposable_email_domains')
            ? DisposableEmailDomain::query()
                ->orderBy('domain')
                ->limit(100)
                ->get(['id', 'domain', 'is_active', 'notes'])
                ->map(static fn (DisposableEmailDomain $d): array => [
                    'id' => $d->id,
                    'domain' => $d->domain,
                    'is_active' => (bool) $d->is_active,
                    'notes' => $d->notes,
                ])->values()->all()
            : [];

        $unverifiedAudit = $this->pendingRegistrations->auditAndRemediateUnverifiedUsers(revokeSessions: false);

        return [
            'metrics' => [
                'verified_users' => $verifiedUsers,
                'unverified_users' => $unverifiedUsers,
                'pending_registrations' => $pendingActive,
                'registrations_today' => $registrationsToday,
                'failed_logins_24h' => $failedLogins24h,
                'otp_requests_24h' => $eventCounts['otp_sent'] ?? 0,
                'forgot_password_24h' => $eventCounts['forgot_password_requested'] ?? 0,
                'captcha_failed_24h' => $eventCounts['captcha_failed'] ?? 0,
                'blocked_registrations_24h' => $eventCounts['registration_blocked'] ?? 0,
                'suspicious_registrations_24h' => $eventCounts['registration_suspicious'] ?? 0,
                'rate_limited_24h' => $eventCounts['rate_limited'] ?? 0,
                'security_incidents_24h' => $securityIncidents24h,
            ],
            'config' => [
                'feature_registration' => $this->settings->featureEnabled('feature_registration'),
                'mandatory_email_verification' => true,
                'is_production' => app()->isProduction(),
                'auth_emergency_registration_pause' => (bool) $this->settings->get('auth_emergency_registration_pause', false),
                'auth_turnstile_enabled' => (bool) $this->settings->get('auth_turnstile_enabled', false),
                'auth_turnstile_site_key' => $this->turnstile->siteKey(),
                'turnstile_has_secret' => $this->turnstile->hasSecretKey(),
                'turnstile_secret_masked' => $this->turnstile->maskedSecretKey(),
                'auth_otp_expire_minutes' => (int) $this->settings->get('auth_otp_expire_minutes', 5),
                'auth_otp_resend_cooldown' => (int) $this->settings->get('auth_otp_resend_cooldown', 60),
                'auth_otp_max_attempts' => (int) $this->settings->get('auth_otp_max_attempts', 5),
                'auth_otp_max_per_email_hour' => (int) $this->settings->get('auth_otp_max_per_email_hour', 5),
                'auth_global_otp_hourly_budget' => (int) $this->settings->get('auth_global_otp_hourly_budget', 300),
                'auth_register_limit_10min' => (int) $this->settings->get('auth_register_limit_10min', 3),
                'auth_register_limit_day' => (int) $this->settings->get('auth_register_limit_day', 10),
                'auth_login_failed_limit_min' => (int) $this->settings->get('auth_login_failed_limit_min', 5),
                'auth_forgot_password_limit_15min' => (int) $this->settings->get('auth_forgot_password_limit_15min', 3),
                'auth_disposable_email_protection' => (bool) $this->settings->get('auth_disposable_email_protection', true),
                'auth_suspicious_risk_threshold' => (int) $this->settings->get('auth_suspicious_risk_threshold', 50),
                'auth_pending_retention_hours' => (int) $this->settings->get('auth_pending_retention_hours', 24),
            ],
            'unverified_audit' => $unverifiedAudit,
            'pending_list' => $pendingList,
            'disposable_domains' => $disposableDomains,
            'recent_events' => $recentEvents,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateAuthProtectionSettings(array $validated, User $actor): void
    {
        $payload = [
            'feature_registration' => (bool) ($validated['feature_registration'] ?? true),
            'feature_require_email_verification' => true,
            'auth_emergency_registration_pause' => (bool) ($validated['auth_emergency_registration_pause'] ?? false),
            'auth_turnstile_enabled' => (bool) ($validated['auth_turnstile_enabled'] ?? false),
            'auth_turnstile_site_key' => trim((string) ($validated['auth_turnstile_site_key'] ?? '')),
            'auth_otp_expire_minutes' => (int) ($validated['auth_otp_expire_minutes'] ?? 5),
            'auth_otp_resend_cooldown' => (int) ($validated['auth_otp_resend_cooldown'] ?? 60),
            'auth_otp_max_attempts' => (int) ($validated['auth_otp_max_attempts'] ?? 5),
            'auth_otp_max_per_email_hour' => (int) ($validated['auth_otp_max_per_email_hour'] ?? 5),
            'auth_global_otp_hourly_budget' => (int) ($validated['auth_global_otp_hourly_budget'] ?? 300),
            'auth_register_limit_10min' => (int) ($validated['auth_register_limit_10min'] ?? 3),
            'auth_register_limit_day' => (int) ($validated['auth_register_limit_day'] ?? 10),
            'auth_login_failed_limit_min' => (int) ($validated['auth_login_failed_limit_min'] ?? 5),
            'auth_forgot_password_limit_15min' => (int) ($validated['auth_forgot_password_limit_15min'] ?? 3),
            'auth_disposable_email_protection' => (bool) ($validated['auth_disposable_email_protection'] ?? true),
            'auth_suspicious_risk_threshold' => (int) ($validated['auth_suspicious_risk_threshold'] ?? 50),
            'auth_pending_retention_hours' => (int) ($validated['auth_pending_retention_hours'] ?? 24),
        ];

        $rawSecret = isset($validated['auth_turnstile_secret']) && is_string($validated['auth_turnstile_secret'])
            ? trim($validated['auth_turnstile_secret'])
            : '';

        if ($rawSecret !== '') {
            $payload['auth_turnstile_secret_encrypted'] = Crypt::encryptString($rawSecret);
        }

        $this->settings->updateMany($payload, $actor, $this->auditLogger);
    }

    public function toggleEmergencyPause(bool $paused, User $actor): void
    {
        $this->settings->updateMany([
            'auth_emergency_registration_pause' => $paused,
        ], $actor, $this->auditLogger);

        $this->authEvents->record('emergency_pause_toggled', $paused ? 'high' : 'info', $actor, null, null, 0, [
            'paused' => $paused,
        ]);
    }

    public function addDisposableDomain(string $domain, ?string $notes, User $actor): DisposableEmailDomain
    {
        $clean = Str::lower(trim(ltrim($domain, '@')));
        $record = DisposableEmailDomain::query()->updateOrCreate(
            ['domain' => $clean],
            ['is_active' => true, 'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null],
        );

        DisposableEmailService::flushCache();
        $this->auditLogger->log('security.disposable_domain.added', $record, ['domain' => $clean], $actor);

        return $record;
    }

    public function removeDisposableDomain(DisposableEmailDomain $domain, User $actor): void
    {
        $name = $domain->domain;
        $domain->delete();

        DisposableEmailService::flushCache();
        $this->auditLogger->log('security.disposable_domain.removed', null, ['domain' => $name], $actor);
    }

    public function revokeSession(User $actor, string $sessionId): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('id', $sessionId)->delete();
        }

        $this->auditLogger->log('security.session_revoked', null, ['session_hash' => sha1($sessionId)], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function backupsPayload(): array
    {
        $items = BackupRecord::query()
            ->with(['creator:id,username'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (BackupRecord $b): array => [
                'id' => $b->id,
                'type' => $b->type,
                'disk' => $b->disk,
                'path' => basename($b->path),
                'size_bytes' => $b->size_bytes,
                'checksum' => $b->checksum,
                'status' => $b->status,
                'notes' => $b->notes,
                'creator_username' => $b->creator?->username,
                'created_at' => $b->created_at->toIso8601String(),
            ])->values()->all();

        return ['backups' => $items];
    }

    /**
     * Create a Hostinger-safe portable JSON governance & metadata snapshot with SHA-256 checksum.
     */
    public function createSnapshot(User $actor, ?string $notes = null): BackupRecord
    {
        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'counts' => [
                'users' => User::query()->withTrashed()->count(),
                'posts' => DB::table('posts')->count(),
                'comments' => DB::table('comments')->count(),
                'communities' => DB::table('communities')->count(),
                'payments' => DB::table('payments')->count(),
                'ad_campaigns' => DB::table('ad_campaigns')->count(),
            ],
            'roles' => Role::query()->with('permissions:id,slug')->get()->toArray(),
            'languages' => Language::query()->orderBy('sort_order')->get()->toArray(),
            'system_settings' => SystemSetting::query()->get(['group', 'key', 'value', 'type', 'is_public'])->toArray(),
            'policy_pages' => PolicyPage::query()->get(['slug', 'title_vi', 'title_en', 'status', 'is_visible', 'footer_group'])->toArray(),
            'footer_links' => FooterLink::query()->get(['label_vi', 'label_en', 'url', 'footer_group', 'is_visible'])->toArray(),
        ];

        $json = (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $json);
        $filename = 'backups/syncviet-snapshot-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.json';

        Storage::disk('local')->put($filename, $json);

        $record = new BackupRecord([
            'type' => 'governance_snapshot',
            'disk' => 'local',
            'path' => $filename,
            'size_bytes' => strlen($json),
            'checksum' => $checksum,
            'status' => 'completed',
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);
        $record->created_by = $actor->id;
        $record->created_at = now();
        $record->save();

        $this->auditLogger->log('admin.backup.created', $record, ['size_bytes' => $record->size_bytes, 'checksum' => $checksum], $actor);

        return $record;
    }
}
