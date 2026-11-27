<?php

namespace App\Services\Auth;

use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class TurnstileService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuthSecurityEventService $events,
    ) {}

    public function isEnabled(): bool
    {
        if (! (bool) $this->settings->get('auth_turnstile_enabled', false)) {
            return false;
        }

        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    public function siteKey(): string
    {
        $fromSetting = trim((string) $this->settings->get('auth_turnstile_site_key', ''));
        if ($fromSetting !== '') {
            return $fromSetting;
        }

        return trim((string) config('syncviet.auth_security.turnstile_site_key', ''));
    }

    public function hasSecretKey(): bool
    {
        return $this->secretKey() !== '';
    }

    public function maskedSecretKey(): ?string
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return null;
        }

        $tail = mb_substr($secret, max(0, mb_strlen($secret) - 4));

        return '••••••••'.$tail;
    }

    /**
     * Fail-closed backend verification of a Cloudflare Turnstile token.
     * Enforces single-use replay protection, hostname validation, and action validation.
     *
     * @return array{ok: bool, message: ?string, reason: ?string}
     */
    public function verify(?string $token, Request $request, string $expectedAction, ?string $email = null): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => true, 'message' => null, 'reason' => null];
        }

        $cleanToken = trim((string) $token);
        if ($cleanToken === '' || strlen($cleanToken) > 2048) {
            $this->events->record('captcha_failed', 'warning', null, $email, $request, 35, [
                'action' => $expectedAction,
                'reason' => 'missing_token',
            ]);

            return [
                'ok' => false,
                'message' => __('Please complete the security verification challenge and try again.'),
                'reason' => 'missing_token',
            ];
        }

        // Prevent replay of an already-submitted Turnstile token
        $replayKey = 'syncviet.turnstile.used.'.hash('sha256', $cleanToken);
        if (! Cache::add($replayKey, 1, now()->addMinutes(10))) {
            $this->events->record('captcha_failed', 'high', null, $email, $request, 55, [
                'action' => $expectedAction,
                'reason' => 'token_replay',
            ]);

            return [
                'ok' => false,
                'message' => __('Security verification expired or was already used. Please try again.'),
                'reason' => 'token_replay',
            ];
        }

        try {
            $verifyUrl = (string) config('syncviet.auth_security.turnstile_verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
            $response = Http::asForm()
                ->timeout(8)
                ->connectTimeout(4)
                ->post($verifyUrl, [
                    'secret' => $this->secretKey(),
                    'response' => $cleanToken,
                    'remoteip' => (string) $request->ip(),
                ]);

            if (! $response->successful()) {
                $this->events->record('captcha_failed', 'high', null, $email, $request, 30, [
                    'action' => $expectedAction,
                    'reason' => 'http_error_'.$response->status(),
                ]);

                return [
                    'ok' => false,
                    'message' => __('Security verification service is temporarily unavailable. Please try again.'),
                    'reason' => 'provider_unavailable',
                ];
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $success = (bool) ($body['success'] ?? false);

            if (! $success) {
                $this->events->record('captcha_failed', 'warning', null, $email, $request, 45, [
                    'action' => $expectedAction,
                    'reason' => 'verification_rejected',
                    'codes' => is_array($body['error-codes'] ?? null) ? array_slice($body['error-codes'], 0, 5) : [],
                ]);

                return [
                    'ok' => false,
                    'message' => __('Security verification failed. Please complete the challenge again.'),
                    'reason' => 'verification_rejected',
                ];
            }

            // Validate action when Cloudflare returns it
            $returnedAction = isset($body['action']) && is_string($body['action']) ? trim($body['action']) : '';
            if ($returnedAction !== '' && $returnedAction !== $expectedAction) {
                $this->events->record('captcha_failed', 'high', null, $email, $request, 60, [
                    'action' => $expectedAction,
                    'returned_action' => $returnedAction,
                    'reason' => 'action_mismatch',
                ]);

                return [
                    'ok' => false,
                    'message' => __('Security verification mismatch. Please refresh and try again.'),
                    'reason' => 'action_mismatch',
                ];
            }

            // Validate hostname against trusted APP_URL host (never trusting incoming Host header)
            $returnedHost = isset($body['hostname']) && is_string($body['hostname']) ? strtolower(trim($body['hostname'])) : '';
            $expectedHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
            if (
                $returnedHost !== ''
                && $expectedHost !== ''
                && $returnedHost !== $expectedHost
                && ! str_ends_with($returnedHost, '.'.$expectedHost)
                && $returnedHost !== 'example.com'
            ) {
                $this->events->record('captcha_failed', 'high', null, $email, $request, 65, [
                    'action' => $expectedAction,
                    'returned_host' => $returnedHost,
                    'reason' => 'hostname_mismatch',
                ]);

                return [
                    'ok' => false,
                    'message' => __('Security verification hostname mismatch. Please refresh and try again.'),
                    'reason' => 'hostname_mismatch',
                ];
            }

            return ['ok' => true, 'message' => null, 'reason' => null];
        } catch (\Throwable) {
            // Fail-closed: NEVER silently bypass bot protection when verification throws
            $this->events->record('captcha_failed', 'high', null, $email, $request, 30, [
                'action' => $expectedAction,
                'reason' => 'network_exception',
            ]);

            return [
                'ok' => false,
                'message' => __('Security verification service is temporarily unavailable. Please try again.'),
                'reason' => 'network_exception',
            ];
        }
    }

    private function secretKey(): string
    {
        $encrypted = trim((string) $this->settings->get('auth_turnstile_secret_encrypted', ''));
        if ($encrypted !== '') {
            try {
                return trim(Crypt::decryptString($encrypted));
            } catch (\Throwable) {
                // Fall back to env config if DB cipher cannot be decrypted
            }
        }

        return trim((string) config('syncviet.auth_security.turnstile_secret_key', ''));
    }
}
