<?php

namespace App\Services\Seo;

use App\Models\SeoSetting;
use App\Models\User;
use App\Services\System\LanguageService;
use App\Services\System\SystemSettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class SeoConfigService
{
    public const CACHE_KEY = 'syncviet.seo.settings.v1';

    /** @var array<string, string|null>|null */
    private ?array $memoizedSettings = null;

    public function __construct(
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Always derive the trusted canonical origin from server configuration, never from request Host header.
     */
    public function canonicalBaseUrl(): string
    {
        $configured = (string) config('syncviet.seo.canonical_base_url', config('app.url', 'https://sysnoxis.com'));
        $trimmed = rtrim(trim($configured), '/');

        if ($trimmed === '' || ! preg_match('#^https?://[a-zA-Z0-9.-]+(?::\d+)?$#', $trimmed)) {
            return 'https://sysnoxis.com';
        }

        return $trimmed;
    }

    public function canonicalHost(): string
    {
        $host = parse_url($this->canonicalBaseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'sysnoxis.com';
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
        if ($this->memoizedSettings !== null) {
            return $this->memoizedSettings;
        }

        try {
            /** @var array<string, string|null> $cached */
            $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), static function (): array {
                if (! Schema::hasTable('seo_settings')) {
                    return [];
                }

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

            $this->memoizedSettings = $cached;

            return $cached;
        } catch (\Throwable) {
            return [];
        }
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
        $this->memoizedSettings = null;
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
        return $this->defaultOgImageMetadata()['url'];
    }

    /**
     * Resolve the default Open Graph raster image URL and dimensions for crawler previews.
     *
     * @return array{url: string, secure_url: string|null, type: string, width: int|null, height: int|null}
     */
    public function defaultOgImageMetadata(): array
    {
        $base = $this->canonicalBaseUrl();

        try {
            $branding = app(SystemSettingService::class)->brandingPayload();
            $customOg = $branding['ogImageUrl'] ?? null;

            if (is_string($customOg) && $customOg !== '') {
                $url = str_starts_with($customOg, 'https://') || str_starts_with($customOg, 'http://')
                    ? $customOg
                    : $base.'/'.ltrim($customOg, '/');

                $inspected = $this->inspectPublicImageMeta($customOg);

                return [
                    'url' => $url,
                    'secure_url' => str_starts_with($url, 'https://') ? $url : null,
                    'type' => $inspected['type'],
                    'width' => $inspected['width'],
                    'height' => $inspected['height'],
                ];
            }
        } catch (\Throwable) {
            // Fall through to SEO config default when system_settings is unavailable.
        }

        $path = $this->string('default_og_image', (string) config('syncviet.seo.default_og_image', '/og-default.png'));

        // Upgrade legacy SVG fallback from pre-V1.0 installations transparently to the raster PNG card.
        if ($path === '/og-default.svg') {
            $path = '/og-default.png';
        }

        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return [
                'url' => $path,
                'secure_url' => str_starts_with($path, 'https://') ? $path : null,
                'type' => str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'image/png',
                'width' => null,
                'height' => null,
            ];
        }

        $version = (string) config('syncviet.branding.asset_version', 'sysnoxis-2');
        $isBuiltInDefault = in_array($path, ['/og-default.png', '/og-default.svg'], true);
        $suffix = ($isBuiltInDefault && $version !== '') ? '?v='.rawurlencode($version) : '';
        $url = $base.'/'.ltrim($path, '/').$suffix;

        if ($path === '/og-default.png') {
            return [
                'url' => $url,
                'secure_url' => str_starts_with($url, 'https://') ? $url : null,
                'type' => 'image/png',
                'width' => 1200,
                'height' => 630,
            ];
        }

        $inspected = $this->inspectPublicImageMeta($path);

        return [
            'url' => $url,
            'secure_url' => str_starts_with($url, 'https://') ? $url : null,
            'type' => $inspected['type'],
            'width' => $inspected['width'],
            'height' => $inspected['height'],
        ];
    }

    /**
     * @return array{type: string, width: int|null, height: int|null}
     */
    private function inspectPublicImageMeta(string $relativeOrUrl): array
    {
        $parsedPath = parse_url($relativeOrUrl, PHP_URL_PATH);
        $path = is_string($parsedPath) ? '/'.ltrim($parsedPath, '/') : '';
        $lower = strtolower($path);

        $fallbackType = match (true) {
            str_ends_with($lower, '.webp') => 'image/webp',
            str_ends_with($lower, '.jpg'), str_ends_with($lower, '.jpeg') => 'image/jpeg',
            str_ends_with($lower, '.svg') => 'image/svg+xml',
            default => 'image/png',
        };

        $candidateFiles = array_values(array_filter([
            $path !== '' ? public_path(ltrim($path, '/')) : null,
            str_starts_with($path, '/storage/')
                ? storage_path('app/public/'.substr($path, strlen('/storage/')))
                : null,
        ]));

        foreach ($candidateFiles as $file) {
            if (is_file($file)) {
                $info = @getimagesize($file);
                if (is_array($info)) {
                    return [
                        'type' => (string) $info['mime'],
                        'width' => (int) $info[0],
                        'height' => (int) $info[1],
                    ];
                }
            }
        }

        return [
            'type' => $fallbackType,
            'width' => null,
            'height' => null,
        ];
    }

    public function ogLocaleCode(string $locale): string
    {
        return match ($locale) {
            'en' => 'en_US',
            'vi' => 'vi_VN',
            'es' => 'es_ES',
            'pt-BR' => 'pt_BR',
            'id' => 'id_ID',
            'hi' => 'hi_IN',
            'th' => 'th_TH',
            'fil' => 'fil_PH',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'ja' => 'ja_JP',
            'ko' => 'ko_KR',
            'zh-CN' => 'zh_CN',
            'ar' => 'ar_AR',
            'tr' => 'tr_TR',
            default => str_replace('-', '_', $locale),
        };
    }

    /**
     * @return list<string>
     */
    public function ogLocaleAlternates(string $currentLocale): array
    {
        $currentOg = $this->ogLocaleCode($currentLocale);
        $alternates = [];

        foreach ($this->enabledLocales() as $code) {
            $og = $this->ogLocaleCode($code);
            if ($og !== $currentOg && ! in_array($og, $alternates, true)) {
                $alternates[] = $og;
            }
        }

        return $alternates;
    }

    public function twitterSiteHandle(): ?string
    {
        $raw = trim($this->string('twitter_site_handle', ''));
        if ($raw === '') {
            return null;
        }

        $normalized = str_starts_with($raw, '@') ? $raw : '@'.$raw;

        return preg_match('/^@[A-Za-z0-9_]{1,15}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Resolve localized Homepage SEO Title & Description across all 15 supported languages.
     *
     * @return array{title: string, description: string}
     */
    public function homeSeoCopy(string $locale): array
    {
        $siteName = (string) config('app.name', 'SysNoxis');
        $defaultCatalog = self::localizedHomeDefaults();
        $fallback = $defaultCatalog[$locale] ?? $defaultCatalog['en'];

        $customLocaleTitle = trim($this->string('home_title_'.$locale, ''));
        $customLocaleDescription = trim($this->string('home_description_'.$locale, ''));

        if ($locale === 'en') {
            $tplTitle = trim($this->string('title_template_home', ''));
            $tplDescription = trim($this->string('description_template_home', ''));

            $resolvedTitle = $customLocaleTitle !== ''
                ? $customLocaleTitle
                : ($tplTitle !== '' ? str_replace('{site}', $siteName, $tplTitle) : str_replace('{site}', $siteName, $fallback['title']));

            $resolvedDesc = $customLocaleDescription !== ''
                ? $customLocaleDescription
                : ($tplDescription !== '' ? str_replace('{site}', $siteName, $tplDescription) : str_replace('{site}', $siteName, $fallback['description']));

            return [
                'title' => $resolvedTitle,
                'description' => $resolvedDesc,
            ];
        }

        return [
            'title' => $customLocaleTitle !== ''
                ? str_replace('{site}', $siteName, $customLocaleTitle)
                : str_replace('{site}', $siteName, $fallback['title']),
            'description' => $customLocaleDescription !== ''
                ? str_replace('{site}', $siteName, $customLocaleDescription)
                : str_replace('{site}', $siteName, $fallback['description']),
        ];
    }

    /**
     * Canonical defaults for all 15 locales when per-locale Admin overrides are unset.
     *
     * @return array<string, array{title: string, description: string}>
     */
    public static function localizedHomeDefaults(): array
    {
        return [
            'en' => [
                'title' => '{site} — Connect Beyond Borders',
                'description' => 'Connect, share ideas, and join vibrant multilingual communities on {site}.',
            ],
            'vi' => [
                'title' => '{site} — Kết nối vượt mọi biên giới',
                'description' => 'Kết nối, chia sẻ câu chuyện và khám phá cộng đồng toàn cầu trên {site}.',
            ],
            'es' => [
                'title' => '{site} — Conecta más allá de las fronteras',
                'description' => 'Conecta, comparte ideas y únete a comunidades multilingües globales en {site}.',
            ],
            'pt-BR' => [
                'title' => '{site} — Conecte-se Além das Fronteiras',
                'description' => 'Conecte-se, compartilhe ideias e participe de comunidades globais em {site}.',
            ],
            'id' => [
                'title' => '{site} — Terhubung Melampaui Batas',
                'description' => 'Terhubung, berbagi cerita, dan bergabung dengan komunitas global di {site}.',
            ],
            'hi' => [
                'title' => '{site} — सीमाओं से परे जुड़ें',
                'description' => '{site} पर जुड़ें, विचार साझा करें और वैश्विक बहुभाषी समुदायों का हिस्सा बनें।',
            ],
            'th' => [
                'title' => '{site} — เชื่อมต่อข้ามพรมแดน',
                'description' => 'เชื่อมต่อ แบ่งปันเรื่องราว และเข้าร่วมชุมชนระดับโลกบน {site}',
            ],
            'fil' => [
                'title' => '{site} — Kumonekta Lampas sa Mga Hangganan',
                'description' => 'Kumonekta, magbahagi ng mga kuwento, at sumali sa mga pandaigdigang komunidad sa {site}.',
            ],
            'fr' => [
                'title' => '{site} — Connectez-vous au-delà des frontières',
                'description' => 'Échangez des idées, partagez vos histoires et rejoignez des communautés mondiales sur {site}.',
            ],
            'de' => [
                'title' => '{site} — Grenzenlos vernetzt',
                'description' => 'Vernetze dich, teile Ideen und entdecke weltweite Communities auf {site}.',
            ],
            'ja' => [
                'title' => '{site} — 国境を越えてつながる',
                'description' => '{site}で世界中のコミュニティとつながり、アイデアやストーリーを共有しよう。',
            ],
            'ko' => [
                'title' => '{site} — 국경을 넘어 연결되다',
                'description' => '{site}에서 전 세계 커뮤니티와 소통하고 아이디어와 이야기를 나누세요.',
            ],
            'zh-CN' => [
                'title' => '{site} — 跨越边界的连接',
                'description' => '在 {site} 与全球社区交流想法、分享故事并发现精彩讨论。',
            ],
            'ar' => [
                'title' => '{site} — تواصل بلا حدود',
                'description' => 'تواصل وشارك الأفكار والقصص وانضم إلى مجتمعات عالمية متعددة اللغات على {site}.',
            ],
            'tr' => [
                'title' => '{site} — Sınırların Ötesinde Bağlan',
                'description' => '{site} üzerinde bağlantı kurun, fikirlerinizi paylaşın ve küresel topluluklara katılın.',
            ],
        ];
    }

    public function cacheVersionToken(): string
    {
        $brandVersion = (string) config('syncviet.branding.asset_version', 'sysnoxis-2');
        $ogCustom = $this->defaultOgImageUrl();

        return sha1($brandVersion.'|'.$ogCustom.'|'.($this->isIndexingAllowed() ? '1' : '0').'|'.count($this->enabledLocales()));
    }

    public function sitemapShardSize(): int
    {
        return max(100, min(50000, (int) config('syncviet.seo.sitemap_shard_size', 5000)));
    }
}
