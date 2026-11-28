<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Services\Seo\GoogleSiteVerificationService;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\RobotsAndRedirectService;
use App\Services\Seo\SitemapEngineService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SeoPublicController extends Controller
{
    public function __construct(
        private readonly RobotsAndRedirectService $robotsService,
        private readonly SitemapEngineService $sitemapEngine,
        private readonly IndexNowService $indexNow,
        private readonly GoogleSiteVerificationService $googleVerification,
    ) {}

    public function robots(): Response
    {
        return response($this->robotsService->renderRobotsTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    public function sitemapIndex(Request $request): Response
    {
        $payload = $this->sitemapEngine->renderIndexXml();

        if ($request->headers->get('If-None-Match') === $payload['etag']) {
            return response('', 304, [
                'ETag' => $payload['etag'],
                'Last-Modified' => $payload['lastmod'],
            ]);
        }

        return response($payload['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'ETag' => $payload['etag'],
            'Last-Modified' => $payload['lastmod'],
            'Cache-Control' => 'public, max-age=900, stale-while-revalidate=3600',
        ]);
    }

    public function sitemapShard(Request $request, string $shard): Response
    {
        $payload = $this->sitemapEngine->renderShardXml('sitemap-'.$shard);

        if ($payload === null) {
            abort(404);
        }

        if ($request->headers->get('If-None-Match') === $payload['etag']) {
            return response('', 304, [
                'ETag' => $payload['etag'],
                'Last-Modified' => $payload['lastmod'],
            ]);
        }

        return response($payload['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'ETag' => $payload['etag'],
            'Last-Modified' => $payload['lastmod'],
            'Cache-Control' => 'public, max-age=900, stale-while-revalidate=3600',
        ]);
    }

    public function indexNowKeyFile(string $key): Response
    {
        $activeKey = $this->indexNow->getKey();
        if ($activeKey === '' || ! hash_equals($activeKey, $key)) {
            abort(404);
        }

        return response($activeKey."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function googleVerificationFile(string $token): Response
    {
        $content = $this->googleVerification->htmlFileContentIfMatches('google'.$token);
        if ($content === null) {
            abort(404);
        }

        return response($content, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Brand 1200x630 Open Graph fallback image generated cleanly with zero external dependency.
     */
    public function defaultOgImage(): Response
    {
        $brand = htmlspecialchars((string) config('app.name', 'SysNoxis'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <rect width="1200" height="630" fill="#0B0F17"/>
  <rect x="64" y="64" width="1072" height="502" rx="32" fill="#161B26" stroke="#273244" stroke-width="2"/>
  <rect x="120" y="136" width="76" height="76" rx="20" fill="#4F46E5"/>
  <path d="M144 176l12 12 24-26" fill="none" stroke="#FFFFFF" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
  <text x="224" y="188" fill="#F1F5F9" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="54" font-weight="700">{$brand}</text>
  <text x="120" y="295" fill="#F1F5F9" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="44" font-weight="700">Global Community &amp; Multilingual Discussions</text>
  <text x="120" y="360" fill="#94A3B8" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="28">Connect, discover insights, and grow communities across 15 languages.</text>
  <rect x="120" y="438" width="230" height="48" rx="24" fill="#25264A"/>
  <text x="150" y="470" fill="#818CF8" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="20" font-weight="600">15 Languages · Global</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
