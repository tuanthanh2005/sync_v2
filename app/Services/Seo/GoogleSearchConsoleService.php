<?php

namespace App\Services\Seo;

use App\Enums\SeoIndexEligibility;
use App\Enums\SeoUrlStatus;
use App\Models\SeoApiConnection;
use App\Models\SeoApiUsage;
use App\Models\SeoSearchAnalytic;
use App\Models\SeoSitemap;
use App\Models\SeoUrlInspection;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleSearchConsoleService
{
    public const PROVIDER = 'google_search_console';

    public const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE_WEBMASTERS = 'https://www.googleapis.com/auth/webmasters';

    public const SCOPE_WEBMASTERS_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function connection(): SeoApiConnection
    {
        return SeoApiConnection::query()->firstOrCreate(
            ['provider' => self::PROVIDER],
            [
                'auth_type' => 'none',
                'property_identifier' => 'sc-domain:'.$this->config->canonicalHost(),
                'status' => 'not_connected',
            ],
        );
    }

    /**
     * Safe status payload for Admin UI — NEVER returns secrets, private keys, or OAuth tokens.
     *
     * @return array<string, mixed>
     */
    public function safeSummary(): array
    {
        $conn = $this->connection();
        $todayUsage = SeoApiUsage::query()
            ->where('provider', self::PROVIDER)
            ->where('endpoint', 'url_inspection')
            ->where('usage_date', now()->toDateString())
            ->first();

        $dailyLimit = max(50, $this->config->int('gsc_daily_inspection_limit', (int) config('syncviet.seo.gsc_daily_inspection_quota', 1800)));

        return [
            'status' => $conn->status,
            'auth_type' => $conn->auth_type,
            'property_identifier' => $conn->property_identifier,
            'has_credentials' => $conn->hasCredentials(),
            'has_access_token' => $conn->hasAccessToken(),
            'token_expires_at' => $conn->token_expires_at?->toIso8601String(),
            'last_synced_at' => $conn->last_synced_at?->toIso8601String(),
            'last_error' => $conn->last_error,
            'inspection_quota_used_today' => $todayUsage !== null ? $todayUsage->requests_count : 0,
            'inspection_quota_limit_daily' => $dailyLimit,
            'setup_required' => ! $conn->hasCredentials() && ! $conn->hasAccessToken(),
            'indexing_api_note' => 'SyncViet strictly uses official Search Console APIs (Sites, Sitemaps, Search Analytics, URL Inspection) and NEVER abuses Google Indexing API for social posts.',
        ];
    }

    /**
     * Save Service Account JSON securely (encrypted at rest).
     */
    public function saveServiceAccount(string $jsonPayload, string $propertyIdentifier, User $actor): SeoApiConnection
    {
        $decoded = json_decode(trim($jsonPayload), true);

        if (
            ! is_array($decoded)
            || ($decoded['type'] ?? null) !== 'service_account'
            || ! is_string($decoded['client_email'] ?? null)
            || ! is_string($decoded['private_key'] ?? null)
            || ! str_contains($decoded['private_key'], 'PRIVATE KEY')
        ) {
            throw ValidationException::withMessages([
                'service_account_json' => [__('Invalid Google Service Account JSON. Must include type=service_account, client_email, and private_key.')],
            ]);
        }

        $conn = $this->connection();
        $conn->forceFill([
            'auth_type' => 'service_account',
            'property_identifier' => trim($propertyIdentifier),
            'status' => 'configured_untested',
            'scopes' => [self::SCOPE_WEBMASTERS],
            'settings' => [
                'client_email' => $decoded['client_email'],
                'project_id' => is_string($decoded['project_id'] ?? null) ? $decoded['project_id'] : null,
            ],
            'last_error' => null,
            'updated_by' => $actor->id,
        ]);
        $conn->credentials_encrypted = Crypt::encryptString(json_encode([
            'client_email' => $decoded['client_email'],
            'private_key' => $decoded['private_key'],
            'token_uri' => is_string($decoded['token_uri'] ?? null) ? $decoded['token_uri'] : self::OAUTH_TOKEN_URL,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $conn->access_token_encrypted = null;
        $conn->refresh_token_encrypted = null;
        $conn->token_expires_at = null;
        $conn->save();

        $this->auditLogger->log('seo.gsc.service_account_saved', $conn, [
            'property' => $propertyIdentifier,
            'client_email' => $decoded['client_email'],
        ], $actor);

        return $conn;
    }

    /**
     * Save OAuth 2.0 Client credentials securely (encrypted at rest).
     */
    public function saveOAuthClient(string $clientId, string $clientSecret, string $propertyIdentifier, User $actor): SeoApiConnection
    {
        if (trim($clientId) === '' || trim($clientSecret) === '') {
            throw ValidationException::withMessages([
                'client_id' => [__('Google OAuth Client ID and Client Secret are required.')],
            ]);
        }

        $conn = $this->connection();
        $conn->forceFill([
            'auth_type' => 'oauth2',
            'property_identifier' => trim($propertyIdentifier),
            'status' => 'pending_oauth_authorization',
            'scopes' => [self::SCOPE_WEBMASTERS],
            'settings' => [
                'client_id_preview' => Str::limit(trim($clientId), 24, '...'),
            ],
            'last_error' => null,
            'updated_by' => $actor->id,
        ]);
        $conn->credentials_encrypted = Crypt::encryptString(json_encode([
            'client_id' => trim($clientId),
            'client_secret' => trim($clientSecret),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $conn->save();

        $this->auditLogger->log('seo.gsc.oauth_client_saved', $conn, ['property' => $propertyIdentifier], $actor);

        return $conn;
    }

    public function buildOAuthAuthorizeUrl(string $redirectUri, string $state): ?string
    {
        $creds = $this->decryptCredentials();
        if (! is_array($creds) || ! is_string($creds['client_id'] ?? null)) {
            return null;
        }

        return self::OAUTH_AUTH_URL.'?'.http_build_query([
            'client_id' => $creds['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE_WEBMASTERS,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeOAuthCode(string $code, string $redirectUri, User $actor): bool
    {
        $creds = $this->decryptCredentials();
        if (! is_array($creds) || ! is_string($creds['client_id'] ?? null) || ! is_string($creds['client_secret'] ?? null)) {
            return false;
        }

        $response = Http::timeout(12)->asForm()->post(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            $this->recordConnectionError('OAuth token exchange failed: HTTP '.$response->status());

            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
        if ($accessToken === '') {
            $this->recordConnectionError('OAuth token response missing access_token.');

            return false;
        }

        $conn = $this->connection();
        $conn->access_token_encrypted = Crypt::encryptString($accessToken);
        if (is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== '') {
            $conn->refresh_token_encrypted = Crypt::encryptString($body['refresh_token']);
        }
        $conn->token_expires_at = now()->addSeconds(max(60, ((int) ($body['expires_in'] ?? 3600)) - 60));
        $conn->status = 'connected';
        $conn->last_error = null;
        $conn->updated_by = $actor->id;
        $conn->save();

        $this->auditLogger->log('seo.gsc.oauth_connected', $conn, [], $actor);

        return true;
    }

    public function disconnect(User $actor): void
    {
        $conn = $this->connection();
        $conn->forceFill([
            'auth_type' => 'none',
            'status' => 'not_connected',
            'token_expires_at' => null,
            'last_error' => null,
            'settings' => null,
            'updated_by' => $actor->id,
        ]);
        $conn->credentials_encrypted = null;
        $conn->access_token_encrypted = null;
        $conn->refresh_token_encrypted = null;
        $conn->save();

        $this->auditLogger->log('seo.gsc.disconnected', $conn, [], $actor);
    }

    /**
     * Test live connection via official Sites API (`GET /webmasters/v3/sites`).
     *
     * @return array{ok: bool, status: string, properties: list<array{siteUrl: string, permissionLevel: string}>, message: string}
     */
    public function testConnection(): array
    {
        $token = $this->resolveValidAccessToken();
        if ($token === null) {
            return [
                'ok' => false,
                'status' => $this->connection()->status,
                'properties' => [],
                'message' => __('Google Search Console is not connected. Configure OAuth 2.0 or a Service Account JSON first.'),
            ];
        }

        try {
            $response = Http::timeout(12)
                ->withToken($token)
                ->acceptJson()
                ->get('https://www.googleapis.com/webmasters/v3/sites');

            $this->recordUsage('sites_list', ! $response->successful(), 1000);

            if (! $response->successful()) {
                $err = 'Sites API returned HTTP '.$response->status();
                $this->recordConnectionError($err);

                return ['ok' => false, 'status' => 'error', 'properties' => [], 'message' => $err];
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $entries = is_array($json['siteEntry'] ?? null) ? $json['siteEntry'] : [];
            $properties = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && is_string($entry['siteUrl'] ?? null)) {
                    $properties[] = [
                        'siteUrl' => $entry['siteUrl'],
                        'permissionLevel' => is_string($entry['permissionLevel'] ?? null) ? $entry['permissionLevel'] : 'unknown',
                    ];
                }
            }

            $conn = $this->connection();
            $conn->forceFill([
                'status' => 'connected',
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'ok' => true,
                'status' => 'connected',
                'properties' => $properties,
                'message' => __('Connected to Google Search Console successfully.'),
            ];
        } catch (\Throwable $e) {
            $this->recordConnectionError($e->getMessage());

            return ['ok' => false, 'status' => 'error', 'properties' => [], 'message' => Str::limit($e->getMessage(), 180)];
        }
    }

    /**
     * Submit the sitemap index URL via official Sitemaps API (NEVER uses deprecated /ping).
     *
     * @return array{ok: bool, message: string}
     */
    public function submitSitemapToGsc(?string $sitemapUrl = null): array
    {
        $token = $this->resolveValidAccessToken();
        $conn = $this->connection();
        $property = trim((string) ($conn->property_identifier ?? ''));

        if ($token === null || $property === '') {
            return ['ok' => false, 'message' => __('Google Search Console is not connected.')];
        }

        $feedUrl = $sitemapUrl ?: $this->config->canonicalBaseUrl().'/sitemap.xml';
        $endpoint = sprintf(
            'https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps/%s',
            rawurlencode($property),
            rawurlencode($feedUrl),
        );

        try {
            $response = Http::timeout(12)->withToken($token)->put($endpoint);
            $this->recordUsage('sitemaps_submit', ! $response->successful(), 500);

            if (! $response->successful()) {
                return ['ok' => false, 'message' => 'GSC Sitemaps API returned HTTP '.$response->status()];
            }

            SeoSitemap::query()->update([
                'gsc_submitted_at' => now(),
                'gsc_status' => 'submitted',
            ]);

            return ['ok' => true, 'message' => __('Sitemap index submitted to Google Search Console.')];
        } catch (\Throwable $e) {
            $this->recordUsage('sitemaps_submit', true, 500);

            return ['ok' => false, 'message' => Str::limit($e->getMessage(), 180)];
        }
    }

    /**
     * Pull real Search Analytics metrics via official Search Analytics API.
     *
     * @return array{ok: bool, rows_synced: int, message: string}
     */
    public function syncSearchAnalytics(int $days = 28): array
    {
        $token = $this->resolveValidAccessToken();
        $conn = $this->connection();
        $property = trim((string) ($conn->property_identifier ?? ''));

        if ($token === null || $property === '') {
            return ['ok' => false, 'rows_synced' => 0, 'message' => __('Google Search Console is not connected.')];
        }

        $endDate = now()->subDays(2)->toDateString();
        $startDate = now()->subDays(max(3, min(90, $days)))->toDateString();
        $endpoint = sprintf(
            'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query',
            rawurlencode($property),
        );

        try {
            $response = Http::timeout(20)->withToken($token)->acceptJson()->post($endpoint, [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['date', 'page', 'query', 'country', 'device'],
                'rowLimit' => 2500,
                'dataState' => 'final',
            ]);

            $this->recordUsage('search_analytics', ! $response->successful(), 1200);

            if (! $response->successful()) {
                $err = 'Search Analytics API returned HTTP '.$response->status();
                $this->recordConnectionError($err);

                return ['ok' => false, 'rows_synced' => 0, 'message' => $err];
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $rows = is_array($json['rows'] ?? null) ? $json['rows'] : [];
            $synced = 0;

            foreach ($rows as $row) {
                if (! is_array($row) || ! is_array($row['keys'] ?? null) || count($row['keys']) < 5) {
                    continue;
                }

                $date = (string) $row['keys'][0];
                $pageUrl = Str::limit((string) $row['keys'][1], 1000, '');
                $queryText = Str::limit((string) $row['keys'][2], 250, '');
                $country = Str::limit(strtoupper((string) $row['keys'][3]), 8, '');
                $device = Str::limit(strtolower((string) $row['keys'][4]), 16, '');

                $pageHash = $this->canonicalUrls->urlHash($pageUrl);
                $rowHash = hash('sha256', implode('|', [$date, $property, $pageUrl, $queryText, $country, $device]));

                $reg = SeoUrlRegistry::query()->where('url_hash', $pageHash)->first(['locale', 'entity_type', 'entity_id']);

                SeoSearchAnalytic::query()->updateOrCreate(
                    ['row_hash' => $rowHash],
                    [
                        'date' => $date,
                        'property_url' => $property,
                        'page_url_hash' => $pageHash,
                        'page_url' => $pageUrl,
                        'query' => $queryText,
                        'country' => $country,
                        'device' => $device,
                        'locale' => $reg?->locale,
                        'entity_type' => $reg?->entity_type->value,
                        'entity_id' => $reg?->entity_id,
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'ctr' => round((float) ($row['ctr'] ?? 0), 5),
                        'position' => round((float) ($row['position'] ?? 0), 2),
                    ],
                );
                $synced++;
            }

            $conn->forceFill(['status' => 'connected', 'last_synced_at' => now(), 'last_error' => null])->save();

            return ['ok' => true, 'rows_synced' => $synced, 'message' => __('Search Analytics synchronized.')];
        } catch (\Throwable $e) {
            $this->recordUsage('search_analytics', true, 1200);
            $this->recordConnectionError($e->getMessage());

            return ['ok' => false, 'rows_synced' => 0, 'message' => Str::limit($e->getMessage(), 180)];
        }
    }

    /**
     * Inspect a bounded batch of high-priority URLs via official URL Inspection API respecting daily quota.
     *
     * @return array{ok: bool, inspected: int, quota_remaining: int, message: string}
     */
    public function inspectPriorityBatch(?int $limit = null): array
    {
        $dailyLimit = max(50, $this->config->int('gsc_daily_inspection_limit', (int) config('syncviet.seo.gsc_daily_inspection_quota', 1800)));
        $usedToday = (int) (SeoApiUsage::query()
            ->where('provider', self::PROVIDER)
            ->where('endpoint', 'url_inspection')
            ->where('usage_date', now()->toDateString())
            ->value('requests_count') ?? 0);

        $remaining = max(0, $dailyLimit - $usedToday);
        if ($remaining <= 0) {
            return ['ok' => false, 'inspected' => 0, 'quota_remaining' => 0, 'message' => __('Daily URL Inspection API quota limit reached.')];
        }

        $token = $this->resolveValidAccessToken();
        $conn = $this->connection();
        $property = trim((string) ($conn->property_identifier ?? ''));

        if ($token === null || $property === '') {
            return ['ok' => false, 'inspected' => 0, 'quota_remaining' => $remaining, 'message' => __('Google Search Console is not connected.')];
        }

        $batchSize = min($remaining, max(1, $limit ?? (int) config('syncviet.seo.gsc_inspection_batch_size', 25)));

        $targets = SeoUrlRegistry::query()
            ->where('index_eligibility', SeoIndexEligibility::Eligible->value)
            ->where(static function ($q): void {
                $q->whereNull('last_inspected_at')
                    ->orWhere('last_inspected_at', '<=', now()->subDays(7))
                    ->orWhere('status', SeoUrlStatus::PendingInspection->value);
            })
            ->orderByDesc('priority_score')
            ->orderBy('last_inspected_at')
            ->limit($batchSize)
            ->get();

        $inspected = 0;

        foreach ($targets as $entry) {
            $res = $this->inspectSingleUrl($entry, $token, $property);
            if ($res['rate_limited']) {
                break;
            }
            $inspected++;
            $remaining = max(0, $remaining - 1);
        }

        return [
            'ok' => true,
            'inspected' => $inspected,
            'quota_remaining' => $remaining,
            'message' => __('Inspected :count URLs.', ['count' => $inspected]),
        ];
    }

    /**
     * @return array{ok: bool, rate_limited: bool, verdict: string|null}
     */
    public function inspectSingleUrl(SeoUrlRegistry $entry, ?string $token = null, ?string $property = null): array
    {
        $conn = $this->connection();
        $accessToken = $token ?? $this->resolveValidAccessToken();
        $siteUrl = $property ?? trim((string) ($conn->property_identifier ?? ''));

        if ($accessToken === null || $siteUrl === '') {
            return ['ok' => false, 'rate_limited' => false, 'verdict' => null];
        }

        $inspectionUrl = $entry->canonical_url ?: $entry->url;

        try {
            $response = Http::timeout(15)
                ->withToken($accessToken)
                ->acceptJson()
                ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                    'inspectionUrl' => $inspectionUrl,
                    'siteUrl' => $siteUrl,
                    'languageCode' => $entry->locale ?: 'en',
                ]);

            $this->recordUsage('url_inspection', ! $response->successful(), $this->config->int('gsc_daily_inspection_limit', 1800));

            if ($response->status() === 429) {
                SeoUrlInspection::query()->create([
                    'url_registry_id' => $entry->id,
                    'provider' => self::PROVIDER,
                    'inspection_status' => 'rate_limited',
                    'error_message' => 'HTTP 429 Quota Exceeded',
                    'inspected_at' => now(),
                ]);

                return ['ok' => false, 'rate_limited' => true, 'verdict' => null];
            }

            if (! $response->successful()) {
                SeoUrlInspection::query()->create([
                    'url_registry_id' => $entry->id,
                    'provider' => self::PROVIDER,
                    'inspection_status' => 'error',
                    'error_message' => 'HTTP '.$response->status().': '.Str::limit($response->body(), 300),
                    'inspected_at' => now(),
                ]);
                $entry->forceFill([
                    'last_inspected_at' => now(),
                    'status' => SeoUrlStatus::Error,
                ])->save();

                return ['ok' => false, 'rate_limited' => false, 'verdict' => 'ERROR'];
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            /** @var array<string, mixed> $indexStatus */
            $indexStatus = is_array($body['inspectionResult']['indexStatusResult'] ?? null)
                ? $body['inspectionResult']['indexStatusResult']
                : [];

            $verdict = is_string($indexStatus['verdict'] ?? null) ? $indexStatus['verdict'] : 'VERDICT_UNSPECIFIED';
            $coverageState = is_string($indexStatus['coverageState'] ?? null) ? $indexStatus['coverageState'] : null;

            SeoUrlInspection::query()->create([
                'url_registry_id' => $entry->id,
                'provider' => self::PROVIDER,
                'inspection_status' => 'completed',
                'verdict' => $verdict,
                'coverage_state' => $coverageState,
                'indexing_state' => is_string($indexStatus['indexingState'] ?? null) ? $indexStatus['indexingState'] : null,
                'page_fetch_state' => is_string($indexStatus['pageFetchState'] ?? null) ? $indexStatus['pageFetchState'] : null,
                'robots_txt_state' => is_string($indexStatus['robotsTxtState'] ?? null) ? $indexStatus['robotsTxtState'] : null,
                'google_canonical' => is_string($indexStatus['googleCanonical'] ?? null) ? $indexStatus['googleCanonical'] : null,
                'user_canonical' => is_string($indexStatus['userCanonical'] ?? null) ? $indexStatus['userCanonical'] : null,
                'last_crawl_time' => is_string($indexStatus['lastCrawlTime'] ?? null) ? $indexStatus['lastCrawlTime'] : null,
                'raw_summary' => $indexStatus,
                'inspected_at' => now(),
            ]);

            $mappedStatus = match ($verdict) {
                'PASS' => SeoUrlStatus::Indexed,
                'FAIL', 'PARTIAL', 'NEUTRAL' => SeoUrlStatus::NotIndexed,
                default => SeoUrlStatus::Unknown,
            };

            $entry->forceFill([
                'status' => $mappedStatus,
                'last_inspected_at' => now(),
                'last_crawled_at' => is_string($indexStatus['lastCrawlTime'] ?? null) ? $indexStatus['lastCrawlTime'] : $entry->last_crawled_at,
                'inspection_verdict' => $verdict,
                'inspection_coverage_state' => $coverageState,
            ])->save();

            return ['ok' => true, 'rate_limited' => false, 'verdict' => $verdict];
        } catch (\Throwable $e) {
            $this->recordUsage('url_inspection', true, 1800);

            return ['ok' => false, 'rate_limited' => false, 'verdict' => null];
        }
    }

    private function resolveValidAccessToken(): ?string
    {
        $conn = $this->connection();

        if (
            $conn->hasAccessToken()
            && $conn->token_expires_at !== null
            && $conn->token_expires_at->isFuture()
            && is_string($conn->access_token_encrypted)
        ) {
            try {
                return Crypt::decryptString($conn->access_token_encrypted);
            } catch (\Throwable) {
                return null;
            }
        }

        if ($conn->auth_type === 'service_account') {
            return $this->mintServiceAccountToken($conn);
        }

        if ($conn->auth_type === 'oauth2') {
            return $this->refreshOAuthAccessToken($conn);
        }

        return null;
    }

    private function mintServiceAccountToken(SeoApiConnection $conn): ?string
    {
        $creds = $this->decryptCredentials();
        if (! is_array($creds) || ! is_string($creds['client_email'] ?? null) || ! is_string($creds['private_key'] ?? null)) {
            return null;
        }

        $now = time();
        $tokenUri = is_string($creds['token_uri'] ?? null) ? $creds['token_uri'] : self::OAUTH_TOKEN_URL;

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '{}');
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE_WEBMASTERS,
            'aud' => $tokenUri,
            'exp' => $now + 3600,
            'iat' => $now,
        ]) ?: '{}');

        $unsignedJwt = $header.'.'.$claims;
        $signature = '';

        $signed = @openssl_sign($unsignedJwt, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        if (! $signed) {
            $this->recordConnectionError('Unable to sign Service Account JWT with provided private key.');

            return null;
        }

        $assertion = $unsignedJwt.'.'.$this->base64UrlEncode($signature);

        try {
            $response = Http::timeout(12)->asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (! $response->successful()) {
                $this->recordConnectionError('Service Account token exchange failed: HTTP '.$response->status());

                return null;
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
            if ($accessToken === '') {
                return null;
            }

            $conn->access_token_encrypted = Crypt::encryptString($accessToken);
            $conn->token_expires_at = now()->addSeconds(max(60, ((int) ($body['expires_in'] ?? 3600)) - 60));
            $conn->last_error = null;
            $conn->save();

            return $accessToken;
        } catch (\Throwable $e) {
            $this->recordConnectionError($e->getMessage());

            return null;
        }
    }

    private function refreshOAuthAccessToken(SeoApiConnection $conn): ?string
    {
        $creds = $this->decryptCredentials();
        if (
            ! is_array($creds)
            || ! is_string($creds['client_id'] ?? null)
            || ! is_string($creds['client_secret'] ?? null)
            || ! is_string($conn->refresh_token_encrypted)
        ) {
            return null;
        }

        try {
            $refreshToken = Crypt::decryptString($conn->refresh_token_encrypted);
            $response = Http::timeout(12)->asForm()->post(self::OAUTH_TOKEN_URL, [
                'client_id' => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                $this->recordConnectionError('OAuth token refresh failed: HTTP '.$response->status());

                return null;
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
            if ($accessToken === '') {
                return null;
            }

            $conn->access_token_encrypted = Crypt::encryptString($accessToken);
            $conn->token_expires_at = now()->addSeconds(max(60, ((int) ($body['expires_in'] ?? 3600)) - 60));
            $conn->last_error = null;
            $conn->save();

            return $accessToken;
        } catch (\Throwable $e) {
            $this->recordConnectionError($e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decryptCredentials(): ?array
    {
        $conn = $this->connection();
        if (! is_string($conn->credentials_encrypted) || $conn->credentials_encrypted === '') {
            return null;
        }

        try {
            $raw = Crypt::decryptString($conn->credentials_encrypted);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordConnectionError(string $message): void
    {
        SeoApiConnection::query()
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => 'error',
                'last_error' => Str::limit($message, 500),
            ]);
    }

    private function recordUsage(string $endpoint, bool $isError, int $quotaLimit): void
    {
        $usage = SeoApiUsage::query()->firstOrCreate(
            [
                'provider' => self::PROVIDER,
                'endpoint' => $endpoint,
                'usage_date' => now()->toDateString(),
            ],
            [
                'requests_count' => 0,
                'errors_count' => 0,
                'quota_limit' => $quotaLimit,
            ],
        );

        $usage->increment('requests_count');
        if ($isError) {
            $usage->increment('errors_count');
        }
        $usage->forceFill(['last_called_at' => now()])->save();
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
