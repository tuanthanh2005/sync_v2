<?php

namespace App\Services\Seo;

use App\Models\SeoRedirect;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RobotsAndRedirectService
{
    public const ROBOTS_CACHE_KEY = 'syncviet.seo.robots.v1';

    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function renderRobotsTxt(): string
    {
        $baseUrl = $this->config->canonicalBaseUrl();

        if (! $this->config->isIndexingAllowed()) {
            return implode("\n", [
                '# SyncViet Non-Production / Indexing Disabled Guard',
                'User-agent: *',
                'Disallow: /',
                '',
            ]);
        }

        $lines = [
            '# SyncViet International SEO Engine — Dynamic robots.txt',
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /admin/',
            'Disallow: /wallet',
            'Disallow: /payments',
            'Disallow: /messages',
            'Disallow: /notifications',
            'Disallow: /settings',
            'Disallow: /feed/more',
            'Disallow: /webhooks/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /two-factor-challenge',
            'Disallow: /user/',
            'Disallow: /search?*',
        ];

        $extra = trim($this->config->string('robots_extra_disallow', ''));
        if ($extra !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $rule) {
                $clean = trim($rule);
                if ($clean !== '' && str_starts_with($clean, '/')) {
                    $lines[] = 'Disallow: '.$clean;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$baseUrl.'/sitemap.xml';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function createOrUpdateRedirect(
        string $sourcePath,
        string $targetUrl,
        int $statusCode = 301,
        ?string $reason = null,
        ?User $actor = null,
    ): SeoRedirect {
        if (! in_array($statusCode, [301, 302, 307, 308], true)) {
            throw ValidationException::withMessages([
                'status_code' => [__('Redirect status code must be 301, 302, 307, or 308.')],
            ]);
        }

        $normalizedSource = $this->canonicalUrls->normalizePath($sourcePath);
        if ($normalizedSource === '/') {
            throw ValidationException::withMessages([
                'source_path' => [__('Cannot create a redirect from the root homepage path.')],
            ]);
        }

        if (! $this->canonicalUrls->isSafeRedirectTarget($targetUrl)) {
            throw ValidationException::withMessages([
                'target_url' => [__('Redirect target must be a safe internal path or trusted canonical host URL.')],
            ]);
        }

        $targetPath = $this->canonicalUrls->normalizePath($targetUrl);
        if ($targetPath === $normalizedSource) {
            throw ValidationException::withMessages([
                'target_url' => [__('Redirect loop detected: source and target resolve to the same path.')],
            ]);
        }

        // Detect multi-hop loop (target already redirects back to source)
        $visited = [$normalizedSource => true];
        $cursor = $targetPath;
        for ($depth = 0; $depth < 6; $depth++) {
            $next = SeoRedirect::query()
                ->where('source_path_hash', $this->canonicalUrls->pathHash($cursor))
                ->where('is_active', true)
                ->first();
            if ($next === null) {
                break;
            }
            $nextPath = $this->canonicalUrls->normalizePath($next->target_url);
            if (isset($visited[$nextPath])) {
                throw ValidationException::withMessages([
                    'target_url' => [__('Redirect chain/loop detected involving :path.', ['path' => $nextPath])],
                ]);
            }
            $visited[$nextPath] = true;
            $cursor = $nextPath;
        }

        $redirect = SeoRedirect::query()->updateOrCreate(
            ['source_path_hash' => $this->canonicalUrls->pathHash($normalizedSource)],
            [
                'source_path' => $normalizedSource,
                'target_url' => trim($targetUrl),
                'status_code' => $statusCode,
                'is_active' => true,
                'reason' => $reason,
                'created_by' => $actor?->id,
            ],
        );

        Cache::forget(self::ROBOTS_CACHE_KEY);

        if ($actor !== null) {
            $this->auditLogger->log('seo.redirect.saved', $redirect, [
                'source' => $normalizedSource,
                'target' => trim($targetUrl),
                'status_code' => $statusCode,
            ], $actor);
        }

        return $redirect;
    }

    public function findActiveRedirect(string $path): ?SeoRedirect
    {
        $normalized = $this->canonicalUrls->normalizePath($path);

        return SeoRedirect::query()
            ->where('source_path_hash', $this->canonicalUrls->pathHash($normalized))
            ->where('is_active', true)
            ->first();
    }
}
