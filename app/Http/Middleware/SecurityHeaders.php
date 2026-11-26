<?php

namespace App\Http\Middleware;

use App\Services\Seo\SeoConfigService;
use App\Services\System\SystemSettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function __construct(
        private readonly SeoConfigService $seoConfig,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Apply defense-in-depth HTTP security headers to all web responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $isAdminOrSensitive = $request->is('admin*', 'wallet*', 'payments*', 'settings*', 'login', 'register');
        $ga4Active = $this->seoConfig->ga4PublicConfig()['enabled'] && ! $request->is('admin*');
        $adsenseActive = ! $isAdminOrSensitive
            && (bool) $this->settings->get('feature_adsense', false)
            && (bool) $this->settings->get('adsense_enabled', false);

        $scriptParts = ["'self'", "'unsafe-inline'"];
        $connectParts = ["'self'"];
        $frameParts = ["'self'"];

        if (app()->isLocal()) {
            $scriptParts[] = 'http://127.0.0.1:5173 http://localhost:5173';
            $connectParts[] = 'http://127.0.0.1:5173 http://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5173';
        }

        if ($ga4Active) {
            $scriptParts[] = 'https://www.googletagmanager.com';
            $connectParts[] = 'https://www.google-analytics.com https://region1.google-analytics.com https://www.googletagmanager.com';
        }

        if ($adsenseActive) {
            $scriptParts[] = 'https://pagead2.googlesyndication.com https://fundingchoicesmessages.google.com';
            $connectParts[] = 'https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net';
            $frameParts[] = 'https://googleads.g.doubleclick.net https://tpc.googlesyndication.com';
        }

        $scriptSrc = implode(' ', $scriptParts);
        $connectSrc = implode(' ', $connectParts);
        $frameSrc = implode(' ', $frameParts);

        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "frame-src {$frameSrc}",
            "form-action 'self'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src {$scriptSrc}",
            "connect-src {$connectSrc}",
            "worker-src 'self'",
            "manifest-src 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
