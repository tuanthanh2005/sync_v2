<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LogoutOtherSessionsRequest;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Services\Security\AuditLogger;
use App\Services\Security\BrowserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function __construct(
        private readonly BrowserSessionService $browserSessionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $user = $request->user();

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'hasPassword' => (bool) $user->has_password,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sessions' => $this->browserSessionService->activeSessions($user, $request),
            'loginHistory' => $this->browserSessionService->recentLoginHistory($user),
            'requiresAdminTwoFactor' => $user->isAdmin() && ! $user->hasEnabledTwoFactorAuthentication(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update([
            'password' => $request->password,
            'has_password' => true,
        ]);

        // Mark password as confirmed in session so OAuth users can enable 2FA right after setting their first password
        $request->session()->put('auth.password_confirmed_at', time());

        $this->auditLogger->log('auth.password_updated', $user, [], $user, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }

    /**
     * Log out from all other browser sessions and devices.
     */
    public function logoutOtherSessions(LogoutOtherSessionsRequest $request): RedirectResponse
    {
        /** @var string $password */
        $password = $request->validated('password');

        $this->browserSessionService->logoutOtherSessions(
            $request->user(),
            $password,
            $request,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Đã đăng xuất khỏi tất cả thiết bị và phiên trình duyệt khác.'),
        ]);

        return back();
    }
}
