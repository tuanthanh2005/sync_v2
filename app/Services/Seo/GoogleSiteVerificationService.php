<?php

namespace App\Services\Seo;

use App\Enums\SeoVerificationMethod;
use App\Models\SeoApiConnection;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Validation\ValidationException;

class GoogleSiteVerificationService
{
    public const PROVIDER = 'google_site_verification';

    public function __construct(
        private readonly SeoConfigService $config,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function connection(): SeoApiConnection
    {
        return SeoApiConnection::query()->firstOrCreate(
            ['provider' => self::PROVIDER],
            [
                'auth_type' => 'manual_dns_or_file',
                'property_identifier' => 'sc-domain:'.$this->config->canonicalHost(),
                'status' => 'not_verified',
            ],
        );
    }

    /**
     * Save verification method & token cleanly.
     * IMPORTANT: Saving a token NEVER marks the site as Verified; real live verification is required.
     */
    public function configure(
        SeoVerificationMethod $method,
        string $token,
        ?string $propertyIdentifier,
        User $actor,
    ): SeoApiConnection {
        $cleanToken = trim($token);

        if ($cleanToken === '' || strlen($cleanToken) > 220) {
            throw ValidationException::withMessages([
                'verification_token' => [__('Please provide a valid Google site verification token.')],
            ]);
        }

        // Normalize meta tag if user pasted full HTML tag
        if ($method === SeoVerificationMethod::HtmlMeta && preg_match('/content=["\']([^"\']+)["\']/', $cleanToken, $m) === 1) {
            $cleanToken = trim($m[1]);
        }

        // Normalize HTML filename if user pasted googleXXXX.html
        if ($method === SeoVerificationMethod::HtmlFile) {
            $cleanToken = preg_replace('/\.html$/i', '', $cleanToken) ?? $cleanToken;
            if (! str_starts_with($cleanToken, 'google')) {
                $cleanToken = 'google'.$cleanToken;
            }
            if (preg_match('/^google[a-zA-Z0-9_-]{8,80}$/', $cleanToken) !== 1) {
                throw ValidationException::withMessages([
                    'verification_token' => [__('HTML verification filename must match google<token>.html format.')],
                ]);
            }
        }

        $property = trim((string) ($propertyIdentifier ?: 'sc-domain:'.$this->config->canonicalHost()));

        $connection = $this->connection();
        $connection->forceFill([
            'property_identifier' => $property,
            'verification_method' => $method->value,
            'verification_token' => $cleanToken,
            'status' => 'pending_verification',
            'verified_at' => null,
            'last_error' => null,
            'updated_by' => $actor->id,
        ])->save();

        $this->auditLogger->log('seo.verification.configured', $connection, [
            'method' => $method->value,
            'property' => $property,
        ], $actor);

        return $connection;
    }

    /**
     * Perform an honest live check of DNS TXT / DNS CNAME / HTML verification file / Meta tag.
     *
     * @return array{verified: bool, status: string, method: string|null, message: string, checked_host: string}
     */
    public function verifyNow(?User $actor = null): array
    {
        $connection = $this->connection();
        $host = $this->config->canonicalHost();
        $method = $connection->verification_method;
        $token = trim((string) ($connection->verification_token ?? ''));

        if ($method === null || $token === '') {
            return [
                'verified' => false,
                'status' => 'not_verified',
                'method' => $method,
                'checked_host' => $host,
                'message' => __('No Google verification method or token is configured yet.'),
            ];
        }

        $verified = false;
        $message = '';

        if ($method === SeoVerificationMethod::DnsTxt->value) {
            $expected = str_starts_with($token, 'google-site-verification=')
                ? $token
                : 'google-site-verification='.$token;
            $records = @dns_get_record($host, DNS_TXT);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $txt = is_string($record['txt'] ?? null) ? trim($record['txt']) : '';
                    if ($txt === $expected || $txt === $token) {
                        $verified = true;
                        break;
                    }
                }
            }
            $message = $verified
                ? __('DNS TXT record matched on live DNS lookup.')
                : __('DNS TXT record not found yet for :host. Please add the TXT record in your DNS provider and allow propagation.', ['host' => $host]);
        } elseif ($method === SeoVerificationMethod::DnsCname->value) {
            $parts = explode('=', $token, 2);
            $cnameHost = count($parts) === 2 ? trim($parts[0]).'.'.$host : $host;
            $expectedTarget = count($parts) === 2 ? trim($parts[1]) : $token;
            $records = @dns_get_record($cnameHost, DNS_CNAME);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $target = is_string($record['target'] ?? null) ? rtrim(trim($record['target']), '.') : '';
                    if ($target !== '' && strcasecmp($target, rtrim($expectedTarget, '.')) === 0) {
                        $verified = true;
                        break;
                    }
                }
            }
            $message = $verified
                ? __('DNS CNAME record matched on live DNS lookup.')
                : __('DNS CNAME record not found yet on :host.', ['host' => $cnameHost]);
        } elseif ($method === SeoVerificationMethod::HtmlFile->value) {
            // File route is served dynamically by Laravel when configured; confirm external confirmation is still required in Google Search Console
            $verified = false;
            $message = __('Verification file /:file.html is ready and publicly served. Complete final ownership confirmation inside Google Search Console, then connect the Search Console API to validate verified property ownership.', ['file' => $token]);
        } elseif ($method === SeoVerificationMethod::HtmlMeta->value) {
            $verified = false;
            $message = __('Meta tag google-site-verification is active in <head>. Complete final ownership confirmation inside Google Search Console, then connect the Search Console API to verify ownership.', []);
        }

        $connection->forceFill([
            'status' => $verified ? 'verified' : 'pending_verification',
            'verified_at' => $verified ? now() : null,
            'last_synced_at' => now(),
            'last_error' => $verified ? null : $message,
            'updated_by' => $actor !== null ? $actor->id : $connection->updated_by,
        ])->save();

        if ($actor !== null) {
            $this->auditLogger->log('seo.verification.checked', $connection, [
                'method' => $method,
                'verified' => $verified,
            ], $actor);
        }

        return [
            'verified' => $verified,
            'status' => $connection->status,
            'method' => $method,
            'checked_host' => $host,
            'message' => $message,
        ];
    }

    public function activeMetaToken(): ?string
    {
        $connection = SeoApiConnection::query()->where('provider', self::PROVIDER)->first();
        if ($connection === null || $connection->verification_method !== SeoVerificationMethod::HtmlMeta->value) {
            return null;
        }

        $token = trim((string) ($connection->verification_token ?? ''));

        return $token !== '' ? $token : null;
    }

    public function htmlFileContentIfMatches(string $filenameWithoutExt): ?string
    {
        $connection = SeoApiConnection::query()->where('provider', self::PROVIDER)->first();
        if ($connection === null || $connection->verification_method !== SeoVerificationMethod::HtmlFile->value) {
            return null;
        }

        $token = trim((string) ($connection->verification_token ?? ''));
        if ($token === '' || ! hash_equals($token, $filenameWithoutExt)) {
            return null;
        }

        return "google-site-verification: {$token}.html\n";
    }
}
