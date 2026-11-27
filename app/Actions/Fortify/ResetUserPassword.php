<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     * Revokes all active sessions & remember tokens, never bypasses Email Verification or 2FA,
     * and dispatches a password-changed security alert.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $request = request();

        $limit = app(AuthRateLimiterService::class)->attemptResetPassword($request, $user->email);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'email' => [__('Too many password reset attempts. Please try again in :seconds seconds.', ['seconds' => $limit['retry_after']])],
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(static function () use ($user, $input): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Explicitly NEVER modify email_verified_at or two_factor_* during password reset
            $locked->forceFill([
                'password' => $input['password'],
                'has_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            // Revoke all active database sessions across every device immediately
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
        });

        app(AuthSecurityEventService::class)->record('password_reset_completed', 'info', $user, $user->email, $request);
        app(AuditLogger::class)->log('auth.password_reset', $user, [], $user, $request);

        if ($user->hasVerifiedEmail()) {
            try {
                $user->notify(new SecurityAlertNotification(
                    alertKey: 'password_reset',
                    titleKey: 'Your account password has been reset',
                    bodyKey: 'Your account password was recently reset and all previous sessions were signed out.',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
