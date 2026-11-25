<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasTwoFactor
{
    /**
     * Enforce mandatory Two-Factor Authentication for Administrator accounts.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isAdmin() && ! $user->hasEnabledTwoFactorAuthentication()) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, __('Admin accounts must enable Two-Factor Authentication.'));
            }

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Quản trị viên bắt buộc phải bật xác thực 2 lớp (2FA) trước khi truy cập khu vực quản trị.'),
            ]);

            return to_route('security.edit');
        }

        return $next($request);
    }
}
