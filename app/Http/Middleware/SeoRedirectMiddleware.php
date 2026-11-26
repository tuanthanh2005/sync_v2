<?php

namespace App\Http\Middleware;

use App\Services\Seo\CanonicalUrlService;
use App\Services\Seo\RobotsAndRedirectService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SeoRedirectMiddleware
{
    public function __construct(
        private readonly RobotsAndRedirectService $redirects,
        private readonly CanonicalUrlService $canonicalUrls,
    ) {}

    /**
     * Global middleware executed before route resolution so moved/legacy paths redirect cleanly instead of returning 404.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->is('admin*', 'webhooks/*', 'up', 'build/*', 'storage/*')
        ) {
            try {
                if (Schema::hasTable('seo_redirects')) {
                    $redirect = $this->redirects->findActiveRedirect($request->getPathInfo());
                    if ($redirect !== null && $this->canonicalUrls->isSafeRedirectTarget($redirect->target_url)) {
                        $targetPath = $this->canonicalUrls->normalizePath($redirect->target_url);
                        $currentPath = $this->canonicalUrls->normalizePath($request->getPathInfo());

                        if ($targetPath !== $currentPath) {
                            $redirect->increment('hit_count');
                            $redirect->forceFill(['last_hit_at' => now()])->saveQuietly();

                            return redirect()->to($redirect->target_url, $redirect->status_code);
                        }
                    }
                }
            } catch (\Throwable) {
                // Never block incoming requests if seo_redirects table is unavailable during deploy.
            }
        }

        return $next($request);
    }
}
