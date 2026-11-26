<?php

namespace App\Http\Middleware;

use App\Services\Seo\SeoConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function __construct(
        private readonly SeoConfigService $seoConfig,
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

        $ga4Active = $this->seoConfig->ga4PublicConfig()['enabled'] && ! $request->is('admin*');

        $scriptParts = ["'self'", "'unsafe-inline'"];
        $connectParts = ["'self'"];

        if (app()->isLocal()) {
            $scriptParts[] = 'http://127.0.0.1:5173 http://localhost:5173';
            $connectParts[] = 'http://127.0.0.1:5173 http://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5173';
        }

        if ($ga4Active) {
            $scriptParts[] = 'https://www.googletagmanager.com';
            $connectParts[] = 'https://www.google-analytics.com https://region1.google-analytics.com https://www.googletagmanager.com';
        }

        $scriptSrc = implode(' ', $scriptParts);
        $connectSrc = implode(' ', $connectParts);

        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
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
