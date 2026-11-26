<?php

namespace App\Http\Middleware;

use App\Services\Seo\OrganicGrowthService;
use App\Services\Seo\SeoConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoMiddleware
{
    public function __construct(
        private readonly OrganicGrowthService $organicGrowth,
        private readonly SeoConfigService $seoConfig,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Capture privacy-safe organic arrival attribution on public GET visits
        if ($request->isMethod('GET') && ! $request->is('admin*', 'webhooks/*', 'sitemap*.xml', 'robots.txt')) {
            $this->organicGrowth->captureOrganicVisitIfApplicable($request);
        }

        /** @var Response $response */
        $response = $next($request);

        // 3. Enforce X-Robots-Tag on non-indexable / sensitive routes and non-production environments
        $isNonIndexableRoute = $request->is(
            'admin',
            'admin/*',
            'wallet',
            'wallet/*',
            'payments',
            'payments/*',
            'messages',
            'messages/*',
            'notifications',
            'notifications/*',
            'settings',
            'settings/*',
            'dashboard',
            'login',
            'register',
            'forgot-password',
            'reset-password*',
            'two-factor*',
            'feed/more',
            'webhooks/*',
            'account/*',
        ) || ($request->is('search') && $request->query->count() > 0);

        if ($isNonIndexableRoute || ! $this->seoConfig->isIndexingAllowed()) {
            if (! $request->is('robots.txt', 'sitemap*.xml')) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        // 4. Safe Cache-Control headers: private/no-store for authenticated/sensitive routes, never caching private pages publicly
        if ($request->user() !== null || $isNonIndexableRoute) {
            if (! $response->headers->has('Cache-Control') || ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
                $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            }
        }

        return $response;
    }
}
