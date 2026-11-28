<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalDomainMiddleware
{
    /**
     * Redirect legacy domains (e.g., syncviet.com, www.syncviet.com) and non-canonical
     * www hosts (www.sysnoxis.com) to the configured primary domain while preserving
     * full path + query string and exempting payment webhooks, health checks, and ACME/verification paths.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('syncviet.domain_migration.redirect_enabled', false)) {
            return $next($request);
        }

        // Never redirect payment webhooks, healthchecks, SSL ACME challenges, or Google verification files.
        if ($request->is('webhooks/*', 'up', '.well-known/*', 'google*.html')) {
            return $next($request);
        }

        $primaryUrl = rtrim(trim((string) config('syncviet.domain_migration.primary_url', config('app.url', ''))), '/');
        $parsedPrimary = parse_url($primaryUrl);

        if (! is_array($parsedPrimary) || ! isset($parsedPrimary['scheme'], $parsedPrimary['host'])) {
            return $next($request);
        }

        $primaryScheme = strtolower((string) $parsedPrimary['scheme']);
        $primaryHost = strtolower((string) $parsedPrimary['host']);

        if (! in_array($primaryScheme, ['http', 'https'], true) || $primaryHost === '') {
            return $next($request);
        }

        $incomingHost = strtolower(trim($request->getHost()));

        // Zero redirect loop guarantee when already on the canonical primary host.
        if ($incomingHost === '' || $incomingHost === $primaryHost) {
            return $next($request);
        }

        /** @var list<string> $legacyHosts */
        $legacyHosts = array_values(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '',
            (array) config('syncviet.domain_migration.legacy_hosts', []),
        )));

        $redirectWwwToApex = (bool) config('syncviet.domain_migration.redirect_www_to_apex', true);
        $wwwPrimaryHost = str_starts_with($primaryHost, 'www.') ? null : 'www.'.$primaryHost;

        $shouldRedirect = in_array($incomingHost, $legacyHosts, true)
            || ($redirectWwwToApex && $wwwPrimaryHost !== null && $incomingHost === $wwwPrimaryHost);

        if (! $shouldRedirect) {
            return $next($request);
        }

        $requestUri = $request->getRequestUri();
        $safePath = '/'.ltrim($requestUri, '/');
        $portSuffix = isset($parsedPrimary['port']) ? ':'.$parsedPrimary['port'] : '';
        $destination = "{$primaryScheme}://{$primaryHost}{$portSuffix}{$safePath}";

        $statusCode = in_array($request->method(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->away($destination, $statusCode);
    }
}
