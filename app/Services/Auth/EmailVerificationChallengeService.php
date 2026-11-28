<?php

namespace App\Services\Auth;

use App\Models\AuthVerificationChallenge;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\AuthOtpVerificationNotification;
use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class EmailVerificationChallengeService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuthSecurityEventService $events,
        private readonly DisposableEmailService $disposableEmails,
    ) {}

    /**
     * Issue a new cryptographic 6-digit OTP and scanner-safe confirmation link token,
     * invalidating all previous active challenges for the same purpose + subject.
     * Throws if actual email delivery fails so the UI never reports a false success.
     *
     * @param  array<string, mixed>  $meta
     */
    public function issueAndSend(
        string $purpose,
        string $email,
        string $recipientName,
        string $locale,
        Request $request,
        ?PendingRegistration $pending = null,
        ?User $user = null,
        array $meta = [],
    ): AuthVerificationChallenge {
        $normalizedEmail = $this->disposableEmails->normalizeEmail($email);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $rawLinkToken = bin2hex(random_bytes(32));
        $expireMinutes = max(3, min(15, (int) $this->settings->get('auth_otp_expire_minutes', 5)));
        $linkExpireMinutes = max($expireMinutes, (int) config('syncviet.auth_security.otp_link_expire_minutes', 30));
        $maxAttempts = max(3, min(10, (int) $this->settings->get('auth_otp_max_attempts', 5)));

        $challenge = DB::transaction(function () use (
            $purpose,
            $normalizedEmail,
            $otp,
            $rawLinkToken,
            $expireMinutes,
            $linkExpireMinutes,
            $maxAttempts,
            $request,
            $pending,
            $user,
            $meta,
        ): AuthVerificationChallenge {
            $priorQuery = AuthVerificationChallenge::query()
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->whereNull('invalidated_at');

            if ($pending !== null) {
                $priorQuery->where('pending_registration_id', $pending->id);
            } elseif ($user !== null) {
                $priorQuery->where('user_id', $user->id);
            } else {
                $priorQuery->where('email', $normalizedEmail);
            }

            $priorResends = (int) ($priorQuery->max('resend_count') ?? 0);
            $priorQuery->update(['invalidated_at' => now()]);

            return AuthVerificationChallenge::query()->create([
                'purpose' => $purpose,
                'pending_registration_id' => $pending?->id,
                'user_id' => $user?->id,
                'email' => $normalizedEmail,
                'otp_hmac' => $this->computeOtpHmac($purpose, $normalizedEmail, $otp),
                'link_token_hash' => hash('sha256', $rawLinkToken),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'resend_count' => $priorResends + 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes($expireMinutes),
                'link_expires_at' => now()->addMinutes($linkExpireMinutes),
                'ip_address' => Str::limit((string) $request->ip(), 45, ''),
                'meta' => $meta !== [] ? $meta : null,
            ]);
        });

        $trustedOrigin = rtrim((string) config('app.url', 'https://sysnoxis.com'), '/');
        $confirmationUrl = "{$trustedOrigin}/verify-email/confirm/{$rawLinkToken}";

        try {
            Notification::route('mail', $normalizedEmail)
                ->notify(
                    (new AuthOtpVerificationNotification(
                        otpCode: $otp,
                        confirmationUrl: $confirmationUrl,
                        expireMinutes: $expireMinutes,
                        purpose: $purpose,
                        recipientName: $recipientName,
                    ))->locale($locale),
                );
        } catch (\Throwable $e) {
            // Invalidate challenge if delivery failed so user isn't misled into thinking mail was sent
            $challenge->forceFill(['invalidated_at' => now()])->save();

            $this->events->record('mail_delivery_failed', 'critical', $user, $normalizedEmail, $request, 20, [
                'purpose' => $purpose,
                'error' => Str::limit($e->getMessage(), 180, ''),
            ]);

            throw $e;
        }

        $this->events->record('otp_sent', 'info', $user, $normalizedEmail, $request, 0, [
            'purpose' => $purpose,
            'resend_count' => $challenge->resend_count,
        ]);

        return $challenge;
    }

    /**
     * Verify a 6-digit OTP atomically with constant-time HMAC check and persistent brute-force caps.
     *
     * @return array{ok: bool, challenge: ?AuthVerificationChallenge, reason: ?string, remaining_attempts: int}
     */
    public function verifyOtp(
        string $purpose,
        string $email,
        string $rawOtp,
        Request $request,
        ?PendingRegistration $pending = null,
        ?User $user = null,
    ): array {
        $normalizedEmail = $this->disposableEmails->normalizeEmail($email);
        $cleanOtp = preg_replace('/\D+/', '', trim($rawOtp)) ?? '';

        if (strlen($cleanOtp) !== 6) {
            return ['ok' => false, 'challenge' => null, 'reason' => 'invalid_format', 'remaining_attempts' => 0];
        }

        // Cumulative 15-min anti-brute-force guard across resends (resending MUST NOT reset guess budget)
        $bruteForceKey = 'auth:otp:fail15m:'.$purpose.':'.$this->events->hashEmail($normalizedEmail);
        $maxGlobalGuesses = max(10, (int) $this->settings->get('auth_otp_max_attempts', 5) * 2);
        $priorGuesses = (int) Cache::get($bruteForceKey, 0);

        if ($priorGuesses >= $maxGlobalGuesses) {
            $this->events->record('otp_failed', 'high', $user, $normalizedEmail, $request, 60, [
                'purpose' => $purpose,
                'reason' => 'brute_force_locked',
            ]);

            return ['ok' => false, 'challenge' => null, 'reason' => 'too_many_attempts', 'remaining_attempts' => 0];
        }

        $expectedHmac = $this->computeOtpHmac($purpose, $normalizedEmail, $cleanOtp);

        /** @var array{ok: bool, challenge: ?AuthVerificationChallenge, reason: ?string, remaining_attempts: int} $result */
        $result = DB::transaction(function () use (
            $purpose,
            $normalizedEmail,
            $expectedHmac,
            $pending,
            $user,
            $bruteForceKey,
        ): array {
            $query = AuthVerificationChallenge::query()
                ->where('purpose', $purpose)
                ->where('email', $normalizedEmail)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->latest('id')
                ->lockForUpdate();

            if ($pending !== null) {
                $query->where('pending_registration_id', $pending->id);
            } elseif ($user !== null) {
                $query->where('user_id', $user->id);
            }

            /** @var AuthVerificationChallenge|null $challenge */
            $challenge = $query->first();

            if ($challenge === null) {
                return ['ok' => false, 'challenge' => null, 'reason' => 'not_found', 'remaining_attempts' => 0];
            }

            if ($challenge->expires_at->isPast()) {
                $challenge->forceFill(['invalidated_at' => now()])->save();

                return ['ok' => false, 'challenge' => $challenge, 'reason' => 'expired', 'remaining_attempts' => 0];
            }

            if ($challenge->attempts >= $challenge->max_attempts) {
                $challenge->forceFill(['invalidated_at' => now()])->save();

                return ['ok' => false, 'challenge' => $challenge, 'reason' => 'too_many_attempts', 'remaining_attempts' => 0];
            }

            if (! hash_equals($challenge->otp_hmac, $expectedHmac)) {
                $nextAttempts = $challenge->attempts + 1;
                $updates = ['attempts' => $nextAttempts];
                if ($nextAttempts >= $challenge->max_attempts) {
                    $updates['invalidated_at'] = now();
                }
                $challenge->forceFill($updates)->save();

                Cache::put($bruteForceKey, ((int) Cache::get($bruteForceKey, 0)) + 1, now()->addMinutes(15));

                return [
                    'ok' => false,
                    'challenge' => $challenge,
                    'reason' => $nextAttempts >= $challenge->max_attempts ? 'too_many_attempts' : 'mismatch',
                    'remaining_attempts' => max(0, $challenge->max_attempts - $nextAttempts),
                ];
            }

            $challenge->forceFill(['used_at' => now()])->save();
            Cache::forget($bruteForceKey);

            return [
                'ok' => true,
                'challenge' => $challenge,
                'reason' => null,
                'remaining_attempts' => max(0, $challenge->max_attempts - $challenge->attempts),
            ];
        });

        if (! $result['ok']) {
            $this->events->record('otp_failed', $result['reason'] === 'too_many_attempts' ? 'high' : 'warning', $user, $normalizedEmail, $request, 25, [
                'purpose' => $purpose,
                'reason' => $result['reason'],
            ]);
        } else {
            $this->events->record('otp_verified', 'info', $user, $normalizedEmail, $request, 0, [
                'purpose' => $purpose,
            ]);
        }

        return $result;
    }

    /**
     * Read-only lookup for GET confirmation link page (scanner-safe: NEVER mutates state!).
     */
    public function inspectLinkToken(string $rawToken): ?AuthVerificationChallenge
    {
        $clean = trim($rawToken);
        if ($clean === '' || strlen($clean) > 128) {
            return null;
        }

        /** @var AuthVerificationChallenge|null $challenge */
        $challenge = AuthVerificationChallenge::query()
            ->with(['pendingRegistration', 'user'])
            ->where('link_token_hash', hash('sha256', $clean))
            ->first();

        if ($challenge === null || ! $challenge->isUsableForLink()) {
            return null;
        }

        return $challenge;
    }

    /**
     * Atomically consume a confirmation link token on explicit POST confirmation (prevents replay & races).
     */
    public function consumeLinkToken(string $rawToken, Request $request): ?AuthVerificationChallenge
    {
        $clean = trim($rawToken);
        if ($clean === '' || strlen($clean) > 128) {
            return null;
        }

        $tokenHash = hash('sha256', $clean);

        /** @var AuthVerificationChallenge|null $consumed */
        $consumed = DB::transaction(static function () use ($tokenHash): ?AuthVerificationChallenge {
            /** @var AuthVerificationChallenge|null $challenge */
            $challenge = AuthVerificationChallenge::query()
                ->where('link_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($challenge === null || ! $challenge->isUsableForLink()) {
                return null;
            }

            $challenge->forceFill(['used_at' => now()])->save();

            return $challenge;
        });

        if ($consumed !== null) {
            $this->events->record('otp_verified', 'info', $consumed->user, $consumed->email, $request, 0, [
                'purpose' => $consumed->purpose,
                'method' => 'confirmation_link',
            ]);
        }

        return $consumed;
    }

    private function computeOtpHmac(string $purpose, string $normalizedEmail, string $otp): string
    {
        $secret = (string) config('app.key', 'syncviet-otp-secret');

        return hash_hmac('sha256', "{$purpose}|{$normalizedEmail}|{$otp}", $secret);
    }
}
