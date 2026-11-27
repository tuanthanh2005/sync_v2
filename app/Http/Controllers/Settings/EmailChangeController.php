<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Auth\DisposableEmailService;
use App\Services\Auth\EmailVerificationChallengeService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmailChangeController extends Controller
{
    public function __construct(
        private readonly AuthRateLimiterService $rateLimiter,
        private readonly DisposableEmailService $disposableEmails,
        private readonly EmailVerificationChallengeService $challenges,
        private readonly AuthSecurityEventService $events,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Request a secure email address change: requires current password, keeps old email intact
     * until new email OTP is verified, and alerts the old email immediately.
     */
    public function requestChange(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rules = [
            'new_email' => ['required', 'string', 'email', 'max:255'],
        ];
        if ($user->has_password) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if ($user->has_password && ! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $newEmail = $this->disposableEmails->normalizeEmail((string) $validated['new_email']);

        if ($newEmail === $this->disposableEmails->normalizeEmail($user->email)) {
            throw ValidationException::withMessages([
                'new_email' => [__('Please enter a different email address from your current one.')],
            ]);
        }

        $limit = $this->rateLimiter->attemptChangeEmail($request, 'user:'.$user->id, $newEmail, $user);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'new_email' => [__('Too many email change attempts. Please try again later.')],
            ]);
        }

        if ($this->disposableEmails->isDisposable($newEmail)) {
            throw ValidationException::withMessages([
                'new_email' => [__('Temporary or disposable email addresses are not allowed. Please use a permanent email address.')],
            ]);
        }

        if (User::withTrashed()->where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'new_email' => [__('The email has already been taken.')],
            ]);
        }

        $oldEmail = $user->email;

        try {
            $this->challenges->issueAndSend(
                purpose: AuthVerificationChallenge::PURPOSE_CHANGE_EMAIL,
                email: $newEmail,
                recipientName: $user->name,
                locale: $user->preferredLocale(),
                request: $request,
                user: $user,
                meta: ['old_email' => $oldEmail, 'new_email' => $newEmail],
            );
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'new_email' => [__('Unable to send the verification email right now. Please try again in a moment.')],
            ]);
        }

        // Notify old email address immediately for account-takeover defense
        try {
            Notification::route('mail', $oldEmail)->notify(
                (new SecurityAlertNotification(
                    alertKey: 'email_change_requested',
                    titleKey: 'An email address change was requested for your account',
                    bodyKey: 'A request was made to change your account email to :email. Your current email will remain active until the new address is verified.',
                    params: ['email' => $this->events->maskEmail($newEmail)],
                ))->locale($user->preferredLocale()),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $request->session()->put('settings.pending_email_change', $newEmail);

        $this->events->record('email_change_requested', 'info', $user, $newEmail, $request, 0);
        $this->auditLogger->log('auth.email_change_requested', $user, [
            'new_email_masked' => $this->events->maskEmail($newEmail),
        ], $user, $request);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('A 6-digit verification code has been sent to your new email address.'),
        ]);

        return back();
    }

    /**
     * Confirm the 6-digit OTP sent to the new email and atomically swap users.email.
     */
    public function verifyChange(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'new_email' => ['nullable', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'min:6', 'max:12'],
        ]);

        $targetEmail = is_string($validated['new_email'] ?? null) && trim($validated['new_email']) !== ''
            ? $this->disposableEmails->normalizeEmail($validated['new_email'])
            : (string) $request->session()->get('settings.pending_email_change', '');

        if ($targetEmail === '') {
            throw ValidationException::withMessages([
                'code' => [__('No pending email change request was found. Please request a new code.')],
            ]);
        }

        $result = $this->challenges->verifyOtp(
            purpose: AuthVerificationChallenge::PURPOSE_CHANGE_EMAIL,
            email: $targetEmail,
            rawOtp: (string) $validated['code'],
            request: $request,
            user: $user,
        );

        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'code' => [__('Invalid or expired verification code. Please try again.')],
            ]);
        }

        $oldEmail = $user->email;

        DB::transaction(static function () use ($user, $targetEmail): void {
            if (User::withTrashed()->where('email', $targetEmail)->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'code' => [__('The email has already been taken.')],
                ]);
            }

            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'email' => $targetEmail,
                'email_verified_at' => now(),
            ])->save();
        });

        $request->session()->forget('settings.pending_email_change');

        try {
            Notification::route('mail', $oldEmail)->notify(
                (new SecurityAlertNotification(
                    alertKey: 'email_changed',
                    titleKey: 'Your account email address has been changed',
                    bodyKey: 'Your account email address was updated to :email.',
                    params: ['email' => $this->events->maskEmail($targetEmail)],
                ))->locale($user->preferredLocale()),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $this->events->record('email_change_completed', 'info', $user, $targetEmail, $request, 0);
        $this->auditLogger->log('auth.email_changed', $user, [
            'new_email_masked' => $this->events->maskEmail($targetEmail),
        ], $user, $request);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your email address has been verified and updated.'),
        ]);

        return back();
    }
}
