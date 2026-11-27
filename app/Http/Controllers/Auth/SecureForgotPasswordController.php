<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Auth\DisposableEmailService;
use App\Services\Auth\TurnstileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class SecureForgotPasswordController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
        private readonly AuthRateLimiterService $rateLimiter,
        private readonly DisposableEmailService $disposableEmails,
        private readonly AuthSecurityEventService $events,
    ) {}

    /**
     * Enumeration-safe password reset request with Turnstile, multi-key rate limit, and uniform response.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = $this->disposableEmails->normalizeEmail((string) $validated['email']);

        $token = is_string($request->input('cf_turnstile_response'))
            ? $request->input('cf_turnstile_response')
            : (is_string($request->input('cf-turnstile-response')) ? $request->input('cf-turnstile-response') : null);

        $captcha = $this->turnstile->verify($token, $request, 'forgot_password', $email);
        if (! $captcha['ok']) {
            throw ValidationException::withMessages([
                'cf_turnstile_response' => [$captcha['message'] ?? __('Please complete the security verification challenge and try again.')],
            ]);
        }

        $limit = $this->rateLimiter->attemptForgotPassword($request, $email);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'email' => [__('Too many password reset requests. Please try again in :seconds seconds.', ['seconds' => $limit['retry_after']])],
            ]);
        }

        $this->events->record('forgot_password_requested', 'info', null, $email, $request, 0);

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $user->isActive()) {
            try {
                Password::broker()->sendResetLink(['email' => $email]);
            } catch (\Throwable $e) {
                report($e);
                $this->events->record('mail_delivery_failed', 'critical', $user, $email, $request, 20, [
                    'purpose' => 'password_reset',
                ]);
            }
        }

        // Always return the EXACT same generic message to eliminate Account Enumeration
        return back()->with(
            'status',
            __('If that email address is valid and eligible, we will send password reset instructions.'),
        );
    }
}
