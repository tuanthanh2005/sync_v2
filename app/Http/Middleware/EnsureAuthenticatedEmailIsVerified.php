<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedEmailIsVerified
{
    /**
     * Route names explicitly allowed for authenticated sessions that have not yet verified email.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'verification.notice',
        'verification.verify',
        'verification.send',
        'auth.verify.otp',
        'auth.verify.resend',
        'auth.verify.change-email',
        'auth.verify.cancel',
        'auth.verify.confirm.show',
        'auth.verify.confirm.store',
        'auth.social.redirect',
        'auth.social.callback',
        'logout',
        'locale.update',
        'policies.show',
        'policies.localized.show',
    ];

    /**
     * Globally prevent ANY authenticated unverified session (including stale Remember Me cookies
     * or direct URL/API navigation) from accessing member pages, public-as-member UI, settings, or APIs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($this->isAllowedPathOrRoute($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => __('Your email address is not verified.'),
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        return redirect()->route('verification.notice');
    }

    private function isAllowedPathOrRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return true;
        }

        return $request->is(
            'email/verify',
            'email/verify/*',
            'verify-email/confirm/*',
            'email/verification-notification',
            'logout',
            'locale',
            'policies/*',
            'auth/*/redirect',
            'auth/*/callback',
            'webhooks/*',
            'up',
            'robots.txt',
            'sitemap*.xml',
            'ads.txt',
            'manifest.webmanifest',
            'google*.html',
            '*-indexnow.txt',
        );
    }
}
