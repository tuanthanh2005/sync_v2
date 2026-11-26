<?php

namespace App\Services\Seo;

use App\Models\SeoSetting;
use App\Models\User;
use App\Services\System\LanguageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class SeoConfigService
{
    public const CACHE_KEY = 'syncviet.seo.settings.v1';

    public function __construct(
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Always derive the trusted canonical origin from server configuration, never from request Host header.
     */
    public function canonicalBaseUrl(): string
    {
        $configured = (string) config('syncviet.seo.canonical_base_url', config('app.url', 'https://syncviet.com'));
        $trimmed = rtrim(trim($configured), '/');

        if ($trimmed === '' || ! preg_match('#^https?://[a-zA-Z0-9.-]+(?::\d+)?$#', $trimmed)) {
            return 'https://syncviet.com';
        }

        return $trimmed;
    }

    public function canonicalHost(): string
    {
        $host = parse_url($this->canonicalBaseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'syncviet.com';
    }

    public function isIndexingAllowed(): bool
    {
        if (! $this->bool('seo_enabled', true)) {
            return false;
        }

        if (app()->isProduction()) {
            return true;
        }

        return $this->bool('allow_indexing_in_non_production', false);
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        if (! Schema::hasTable('seo_settings')) {
            return [];
        }

        /** @var array<string, string|null> $cached */
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), static function (): array {
            $rows = SeoSetting::query()->get(['key', 'value', 'is_encrypted']);
            $result = [];

            foreach ($rows as $row) {
                if ($row->is_encrypted && is_string($row->value) && $row->value !== '') {
                    try {
                        $result[$row->key] = Crypt::decryptString($row->value);
                    } catch (\Throwable) {
                        $result[$row->key] = null;
                    }
                } else {
                    $result[$row->key] = $row->value;
                }
            }

            return $result;
        });

        return $cached;
    }

    public function string(string $key, string $default = ''): string
    {
        $all = $this->all();
        $val = $all[$key] ?? null;

        return is_string($val) && $val !== '' ? $val : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $all = $this->all();

        if (! array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }

        return in_array(strtolower((string) $all[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $all = $this->all();

        if (! array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        return (int) $all[$key];
    }

    /**
     * @return list<string>
     */
    public function jsonStringList(string $key): array
    {
        $raw = $this->string($key, '[]');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $decoded),
            static fn (string $v): bool => $v !== '',
        ));
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string', bool $encrypt = false, ?User $actor = null): void
    {
        if (! Schema::hasTable('seo_settings')) {
            return;
        }

        $serialized = match ($type) {
            'boolean' => ((bool) $value) ? '1' : '0',
            'integer' => (string) ((int) $value),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            default => $value === null ? null : (string) $value,
        };

        if ($encrypt && is_string($serialized) && $serialized !== '') {
            $serialized = Crypt::encryptString($serialized);
        }

        SeoSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $serialized,
                'type' => $type,
                'is_encrypted' => $encrypt,
                'updated_by' => $actor?->id,
            ],
        );

        $this->flushCache();
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    public function enabledLocales(): array
    {
        $codes = $this->languageService->enabledCodes();

        return $codes !== [] ? $codes : ['en'];
    }

    public function defaultLocale(): string
    {
        return 'en';
    }

    /**
     * @return array{enabled: bool, measurement_id: string|null, require_consent: bool}
     */
    public function ga4PublicConfig(): array
    {
        $enabled = $this->bool('ga4_enabled', false);
        $measurementId = trim($this->string('ga4_measurement_id', ''));
        $valid = $enabled && preg_match('/^G-[A-Z0-9]{6,16}$/', $measurementId) === 1;

        return [
            'enabled' => $valid,
            'measurement_id' => $valid ? $measurementId : null,
            'require_consent' => $this->bool('ga4_require_consent', true),
        ];
    }

    public function defaultOgImageUrl(): string
    {
        $path = $this->string('default_og_image', (string) config('syncviet.seo.default_og_image', '/og-default.svg'));

        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }

        return $this->canonicalBaseUrl().'/'.ltrim($path, '/');
    }

    public function sitemapShardSize(): int
    {
        return max(100, min(50000, (int) config('syncviet.seo.sitemap_shard_size', 5000)));
    }
}
