<?php

namespace App\Services\Seo;

use App\Models\SeoApiConnection;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CloudflareAndPageSpeedService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function configureCloudflare(bool $enabled, string $zoneId, ?string $apiToken, User $actor): void
    {
        $this->config->set('cloudflare_purge_enabled', $enabled, 'cloudflare', 'boolean', false, $actor);
        $this->config->set('cloudflare_zone_id', trim($zoneId), 'cloudflare', 'string', false, $actor);

        $conn = SeoApiConnection::query()->firstOrCreate(
            ['provider' => 'cloudflare'],
            ['auth_type' => 'api_token', 'status' => 'not_configured'],
        );

        if (is_string($apiToken) && trim($apiToken) !== '') {
            $conn->credentials_encrypted = Crypt::encryptString(trim($apiToken));
        }

        $conn->forceFill([
            'property_identifier' => trim($zoneId) !== '' ? trim($zoneId) : null,
            'status' => ($enabled && trim($zoneId) !== '' && $conn->hasCredentials()) ? 'configured' : 'not_configured',
            'updated_by' => $actor->id,
        ])->save();

        $this->auditLogger->log('seo.cloudflare.updated', $conn, ['enabled' => $enabled], $actor);
    }

    /**
     * Purge specific public URLs from Cloudflare CDN cache ONLY (never modifies DNS/SSL/WAF).
     *
     * @param  list<string>  $urls
     * @return array{ok: bool, purged: int, message: string}
     */
    public function purgeUrls(array $urls): array
    {
        if (! $this->config->bool('cloudflare_purge_enabled', false)) {
            return ['ok' => false, 'purged' => 0, 'message' => __('Cloudflare Cache Purge is disabled.')];
        }

        $zoneId = trim($this->config->string('cloudflare_zone_id', ''));
        $conn = SeoApiConnection::query()->where('provider', 'cloudflare')->first();

        if ($zoneId === '' || $conn === null || ! is_string($conn->credentials_encrypted) || $conn->credentials_encrypted === '') {
            return ['ok' => false, 'purged' => 0, 'message' => __('Cloudflare Zone ID or API Token is not configured.')];
        }

        $cleanUrls = array_slice(array_values(array_unique(array_filter($urls))), 0, 30);
        if ($cleanUrls === []) {
            return ['ok' => false, 'purged' => 0, 'message' => __('No URLs provided to purge.')];
        }

        try {
            $token = Crypt::decryptString($conn->credentials_encrypted);
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache", [
                    'files' => $cleanUrls,
                ]);

            $ok = $response->successful();
            $conn->forceFill([
                'status' => $ok ? 'connected' : 'error',
                'last_synced_at' => now(),
                'last_error' => $ok ? null : 'HTTP '.$response->status(),
            ])->save();

            return [
                'ok' => $ok,
                'purged' => $ok ? count($cleanUrls) : 0,
                'message' => $ok ? __('Cloudflare cache purged for selected URLs.') : __('Cloudflare API returned HTTP :status.', ['status' => $response->status()]),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'purged' => 0, 'message' => Str::limit($e->getMessage(), 180)];
        }
    }

    /**
     * Query Google PageSpeed Insights API v5 honestly distinguishing Lab Data vs CrUX Field Data.
     *
     * @return array<string, mixed>
     */
    public function inspectPageSpeed(string $url, string $strategy = 'mobile'): array
    {
        $apiKey = (string) (config('syncviet.seo.pagespeed_api_key') ?: $this->config->string('pagespeed_api_key', ''));
        if (trim($apiKey) === '') {
            return [
                'configured' => false,
                'status' => 'not_configured',
                'message' => __('PageSpeed Insights API key is not configured (set PAGESPEED_INSIGHTS_API_KEY in .env).'),
                'lab_data' => null,
                'field_data_crux' => null,
            ];
        }

        $safeStrategy = in_array($strategy, ['mobile', 'desktop'], true) ? $strategy : 'mobile';

        try {
            $response = Http::timeout(25)->acceptJson()->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                'url' => $url,
                'strategy' => $safeStrategy,
                'category' => 'performance',
                'key' => trim($apiKey),
            ]);

            if (! $response->successful()) {
                return [
                    'configured' => true,
                    'status' => 'error',
                    'message' => __('PageSpeed Insights API returned HTTP :status.', ['status' => $response->status()]),
                    'lab_data' => null,
                    'field_data_crux' => null,
                ];
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            /** @var array<string, mixed> $lh */
            $lh = is_array($json['lighthouseResult'] ?? null) ? $json['lighthouseResult'] : [];
            /** @var array<string, mixed> $audits */
            $audits = is_array($lh['audits'] ?? null) ? $lh['audits'] : [];
            /** @var array<string, mixed> $crux */
            $crux = is_array($json['loadingExperience'] ?? null) ? $json['loadingExperience'] : [];

            $perfScore = isset($lh['categories']['performance']['score'])
                ? (int) round(((float) $lh['categories']['performance']['score']) * 100)
                : null;

            return [
                'configured' => true,
                'status' => 'ok',
                'strategy' => $safeStrategy,
                'url' => $url,
                'lab_data' => [
                    'source' => 'Lighthouse Lab Simulation',
                    'performance_score' => $perfScore,
                    'lcp' => $audits['largest-contentful-paint']['displayValue'] ?? null,
                    'cls' => $audits['cumulative-layout-shift']['displayValue'] ?? null,
                    'tbt' => $audits['total-blocking-time']['displayValue'] ?? null,
                ],
                'field_data_crux' => [
                    'source' => 'Chrome User Experience Report (Real Field Data)',
                    'overall_category' => $crux['overall_category'] ?? 'UNAVAILABLE',
                    'metrics' => is_array($crux['metrics'] ?? null) ? $crux['metrics'] : null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'status' => 'error',
                'message' => Str::limit($e->getMessage(), 180),
                'lab_data' => null,
                'field_data_crux' => null,
            ];
        }
    }
}
