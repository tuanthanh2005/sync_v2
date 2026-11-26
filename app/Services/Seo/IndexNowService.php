<?php

namespace App\Services\Seo;

use App\Enums\SeoIndexEligibility;
use App\Models\SeoApiConnection;
use App\Models\SeoApiUsage;
use App\Models\SeoIndexNowSubmission;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class IndexNowService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->bool('indexnow_enabled', false)
            && $this->config->isIndexingAllowed()
            && $this->getKey() !== '';
    }

    public function getKey(): string
    {
        $key = trim($this->config->string('indexnow_key', ''));

        return preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key) === 1 ? $key : '';
    }

    public function regenerateKey(User $actor): string
    {
        $newKey = Str::lower(Str::random(32));
        $this->config->set('indexnow_key', $newKey, 'indexnow', 'string', false, $actor);

        SeoApiConnection::query()->updateOrCreate(
            ['provider' => 'bing_indexnow'],
            [
                'auth_type' => 'key_file',
                'verification_token' => $newKey,
                'status' => $this->config->bool('indexnow_enabled', false) ? 'configured' : 'disabled',
                'updated_by' => $actor->id,
            ],
        );

        $this->auditLogger->log('seo.indexnow.key_regenerated', null, [], $actor);

        return $newKey;
    }

    public function setEnabled(bool $enabled, User $actor): void
    {
        $key = $this->getKey();
        if ($key === '') {
            $key = $this->regenerateKey($actor);
        }

        $this->config->set('indexnow_enabled', $enabled, 'indexnow', 'boolean', false, $actor);

        SeoApiConnection::query()->updateOrCreate(
            ['provider' => 'bing_indexnow'],
            [
                'auth_type' => 'key_file',
                'verification_token' => $key,
                'status' => $enabled ? 'configured' : 'disabled',
                'updated_by' => $actor->id,
            ],
        );

        $this->auditLogger->log('seo.indexnow.toggled', null, ['enabled' => $enabled], $actor);
    }

    /**
     * Enqueue an eligible URL for IndexNow submission with strict content-hash deduplication.
     */
    public function queueUrl(SeoUrlRegistry $registry): ?SeoIndexNowSubmission
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if ($registry->index_eligibility !== SeoIndexEligibility::Eligible || $registry->http_status !== 200) {
            return null;
        }

        $url = $registry->canonical_url ?: $registry->url;
        $urlHash = $this->canonicalUrls->urlHash($url);

        if ($registry->indexnow_last_hash !== null && $registry->indexnow_last_hash === $registry->content_hash) {
            return null;
        }

        $alreadyQueued = SeoIndexNowSubmission::query()
            ->where('url_hash', $urlHash)
            ->where('content_hash', $registry->content_hash)
            ->whereIn('status', ['queued', 'submitted'])
            ->exists();

        if ($alreadyQueued) {
            return null;
        }

        return SeoIndexNowSubmission::query()->create([
            'url_registry_id' => $registry->id,
            'url' => $url,
            'url_hash' => $urlHash,
            'content_hash' => $registry->content_hash,
            'endpoint' => (string) config('syncviet.seo.indexnow_endpoint', 'https://api.indexnow.org/indexnow'),
            'status' => 'queued',
            'attempts' => 0,
        ]);
    }

    /**
     * Submit a bounded batch of queued IndexNow URLs.
     *
     * @return array{submitted: int, failed: int, http_status: int|null, message: string}
     */
    public function submitQueuedBatch(int $limit = 100): array
    {
        if (! $this->isEnabled()) {
            return ['submitted' => 0, 'failed' => 0, 'http_status' => null, 'message' => __('IndexNow is disabled or indexing is blocked in this environment.')];
        }

        $batch = SeoIndexNowSubmission::query()
            ->where('status', 'queued')
            ->where('attempts', '<', 3)
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->get();

        if ($batch->isEmpty()) {
            return ['submitted' => 0, 'failed' => 0, 'http_status' => null, 'message' => __('No queued URLs pending IndexNow submission.')];
        }

        $key = $this->getKey();
        $host = $this->config->canonicalHost();
        $baseUrl = $this->config->canonicalBaseUrl();
        $endpoint = (string) config('syncviet.seo.indexnow_endpoint', 'https://api.indexnow.org/indexnow');

        $urlList = $batch->pluck('url')->unique()->values()->all();

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($endpoint, [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => "{$baseUrl}/{$key}.txt",
                    'urlList' => $urlList,
                ]);

            $status = $response->status();
            $ok = $status >= 200 && $status < 300;
            $excerpt = Str::limit(trim($response->body()), 450);

            $this->recordApiUsage('bing_indexnow', 'indexnow_batch', ! $ok);

            foreach ($batch as $row) {
                $row->forceFill([
                    'status' => $ok ? 'submitted' : ($row->attempts + 1 >= 3 ? 'failed' : 'queued'),
                    'http_status' => $status,
                    'attempts' => $row->attempts + 1,
                    'response_excerpt' => $excerpt !== '' ? $excerpt : ($ok ? 'Accepted' : 'HTTP '.$status),
                    'submitted_at' => now(),
                ])->save();

                if ($ok && $row->url_registry_id !== null) {
                    SeoUrlRegistry::query()
                        ->whereKey($row->url_registry_id)
                        ->update([
                            'indexnow_last_submitted_at' => now(),
                            'indexnow_last_hash' => $row->content_hash,
                        ]);
                }
            }

            SeoApiConnection::query()
                ->where('provider', 'bing_indexnow')
                ->update([
                    'status' => $ok ? 'connected' : 'error',
                    'last_synced_at' => now(),
                    'last_error' => $ok ? null : "HTTP {$status}: {$excerpt}",
                ]);

            return [
                'submitted' => $ok ? $batch->count() : 0,
                'failed' => $ok ? 0 : $batch->count(),
                'http_status' => $status,
                'message' => $ok ? __('IndexNow batch accepted.') : __('IndexNow returned HTTP :status.', ['status' => $status]),
            ];
        } catch (\Throwable $e) {
            $this->recordApiUsage('bing_indexnow', 'indexnow_batch', true);

            foreach ($batch as $row) {
                $row->forceFill([
                    'status' => $row->attempts + 1 >= 3 ? 'failed' : 'queued',
                    'attempts' => $row->attempts + 1,
                    'response_excerpt' => Str::limit($e->getMessage(), 450),
                ])->save();
            }

            return [
                'submitted' => 0,
                'failed' => $batch->count(),
                'http_status' => null,
                'message' => __('IndexNow request failed: :error', ['error' => Str::limit($e->getMessage(), 180)]),
            ];
        }
    }

    private function recordApiUsage(string $provider, string $endpoint, bool $isError): void
    {
        $usage = SeoApiUsage::query()->firstOrCreate(
            [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'usage_date' => now()->toDateString(),
            ],
            [
                'requests_count' => 0,
                'errors_count' => 0,
                'quota_limit' => 10000,
            ],
        );

        $usage->increment('requests_count');
        if ($isError) {
            $usage->increment('errors_count');
        }
        $usage->forceFill(['last_called_at' => now()])->save();
    }
}
