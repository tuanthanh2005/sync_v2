<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRecord;
use App\Models\DisposableEmailDomain;
use App\Models\User;
use App\Services\Admin\AdminSecurityService;
use App\Services\Auth\PendingRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityAuditController extends Controller
{
    public function __construct(
        private readonly AdminSecurityService $securityService,
        private readonly PendingRegistrationService $pendingRegistrations,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/Security/Index', $this->securityService->securityPayload([
            'tab' => (string) $request->input('tab', 'audits'),
            'search' => (string) $request->input('search', ''),
            'action' => (string) $request->input('action', ''),
        ]));
    }

    public function revokeSession(Request $request, string $sessionId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->securityService->revokeSession($actor, $sessionId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Active sessions revoked.')]);

        return back();
    }

    public function updateAuthProtection(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'feature_registration' => ['required', 'boolean'],
            'auth_emergency_registration_pause' => ['required', 'boolean'],
            'auth_turnstile_enabled' => ['required', 'boolean'],
            'auth_turnstile_site_key' => ['nullable', 'string', 'max:255'],
            'auth_turnstile_secret' => ['nullable', 'string', 'max:255'],
            'auth_otp_expire_minutes' => ['required', 'integer', 'min:3', 'max:15'],
            'auth_otp_resend_cooldown' => ['required', 'integer', 'min:30', 'max:300'],
            'auth_otp_max_attempts' => ['required', 'integer', 'min:3', 'max:10'],
            'auth_otp_max_per_email_hour' => ['required', 'integer', 'min:2', 'max:15'],
            'auth_global_otp_hourly_budget' => ['required', 'integer', 'min:20', 'max:5000'],
            'auth_register_limit_10min' => ['required', 'integer', 'min:1', 'max:20'],
            'auth_register_limit_day' => ['required', 'integer', 'min:3', 'max:50'],
            'auth_login_failed_limit_min' => ['required', 'integer', 'min:3', 'max:20'],
            'auth_forgot_password_limit_15min' => ['required', 'integer', 'min:1', 'max:10'],
            'auth_disposable_email_protection' => ['required', 'boolean'],
            'auth_suspicious_risk_threshold' => ['required', 'integer', 'min:20', 'max:100'],
            'auth_pending_retention_hours' => ['required', 'integer', 'min:6', 'max:72'],
        ]);

        $this->securityService->updateAuthProtectionSettings($validated, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Authentication protection settings saved.')]);

        return back();
    }

    public function toggleEmergencyPause(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'paused' => ['required', 'boolean'],
        ]);

        $this->securityService->toggleEmergencyPause((bool) $validated['paused'], $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => (bool) $validated['paused']
                ? __('Emergency registration pause activated.')
                : __('Emergency registration pause lifted.'),
        ]);

        return back();
    }

    public function storeDisposableDomain(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:191', 'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->securityService->addDisposableDomain(
            (string) $validated['domain'],
            isset($validated['notes']) ? (string) $validated['notes'] : null,
            $actor,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disposable email domain added.')]);

        return back();
    }

    public function destroyDisposableDomain(Request $request, DisposableEmailDomain $disposableEmailDomain): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->securityService->removeDisposableDomain($disposableEmailDomain, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disposable email domain removed.')]);

        return back();
    }

    public function remediateUnverified(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $res = $this->pendingRegistrations->auditAndRemediateUnverifiedUsers(revokeSessions: true, actor: $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Revoked :sessions session(s) across :users unverified account(s).', [
                'sessions' => $res['sessions_revoked'],
                'users' => $res['total_unverified'],
            ]),
        ]);

        return back();
    }

    public function cleanupPending(): RedirectResponse
    {
        $res = $this->pendingRegistrations->cleanupExpired();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cleaned up :pending expired pending registration(s) and :challenges challenge(s).', [
                'pending' => $res['pending_expired'] + $res['pending_deleted'],
                'challenges' => $res['challenges_deleted'],
            ]),
        ]);

        return back();
    }

    public function backups(): Response
    {
        return Inertia::render('admin/Backups/Index', $this->securityService->backupsPayload());
    }

    public function createBackup(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->securityService->createSnapshot($actor, isset($validated['notes']) ? (string) $validated['notes'] : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Snapshot backup created.')]);

        return back();
    }

    public function downloadBackup(BackupRecord $backupRecord): StreamedResponse
    {
        abort_unless(Storage::disk($backupRecord->disk)->exists($backupRecord->path), 404);

        return Storage::disk($backupRecord->disk)->download($backupRecord->path, basename($backupRecord->path));
    }
}
