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
        $slogan = htmlspecialchars((string) config('syncviet.branding.default_slogan', 'Connect Beyond Borders.'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <rect width="1200" height="630" fill="#0B0F17"/>
  <rect x="64" y="64" width="1072" height="502" rx="32" fill="#161B26" stroke="#273244" stroke-width="2"/>
  <rect x="120" y="132" width="84" height="84" rx="20" fill="#4F46E5"/>
  <g transform="translate(131 143) scale(1.55)">
    <path d="M33 5H15A8 8 0 0 0 15 21H17.2L22.6 15.6H15A2.6 2.6 0 0 1 15 10.4H27.6Z" fill="#FFFFFF"/>
    <path d="M7 35H25A8 8 0 0 0 25 19H22.8L17.4 24.4H25A2.6 2.6 0 0 1 25 29.6H12.4Z" fill="#FFFFFF"/>
  </g>
  <text x="232" y="180" fill="#F1F5F9" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="52" font-weight="700" letter-spacing="-0.02em">{$brand}</text>
  <text x="232" y="214" fill="#94A3B8" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="22" font-weight="500">{$slogan}</text>
  <text x="120" y="316" fill="#F1F5F9" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="42" font-weight="700">Global Community &amp; Multilingual Discussions</text>
  <text x="120" y="376" fill="#94A3B8" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="26">Discover ideas, share stories, and build communities across 15 languages.</text>
  <rect x="120" y="446" width="248" height="48" rx="24" fill="#25264A"/>
  <text x="150" y="478" fill="#818CF8" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="20" font-weight="600">sysnoxis.com · Global</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
