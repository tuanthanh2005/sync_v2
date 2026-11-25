<?php

namespace App\Services\System;

use App\Models\Language;
use App\Models\TranslationOverride;
use App\Models\TranslationRevision;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LanguageService
{
    public const CACHE_KEY = 'syncviet.languages.catalog.v1';

    /**
     * Canonical list of all 15 supported platform languages.
     *
     * @return list<array{
     *     code: string,
     *     native_name: string,
     *     english_name: string,
     *     flag_code: string,
     *     is_rtl: bool,
     *     is_enabled: bool,
     *     is_default: bool,
     *     sort_order: int
     * }>
     */
    public static function catalog(): array
    {
        return [
            ['code' => 'en', 'native_name' => 'English', 'english_name' => 'English', 'flag_code' => 'us', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => true, 'sort_order' => 1],
            ['code' => 'vi', 'native_name' => 'Tiếng Việt', 'english_name' => 'Vietnamese', 'flag_code' => 'vn', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 2],
            ['code' => 'es', 'native_name' => 'Español', 'english_name' => 'Spanish', 'flag_code' => 'es', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 3],
            ['code' => 'pt-BR', 'native_name' => 'Português (Brasil)', 'english_name' => 'Portuguese (Brazil)', 'flag_code' => 'br', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 4],
            ['code' => 'id', 'native_name' => 'Bahasa Indonesia', 'english_name' => 'Indonesian', 'flag_code' => 'id', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 5],
            ['code' => 'hi', 'native_name' => 'हिन्दी', 'english_name' => 'Hindi', 'flag_code' => 'in', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 6],
            ['code' => 'th', 'native_name' => 'ภาษาไทย', 'english_name' => 'Thai', 'flag_code' => 'th', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 7],
            ['code' => 'fil', 'native_name' => 'Filipino', 'english_name' => 'Filipino', 'flag_code' => 'ph', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 8],
            ['code' => 'fr', 'native_name' => 'Français', 'english_name' => 'French', 'flag_code' => 'fr', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 9],
            ['code' => 'de', 'native_name' => 'Deutsch', 'english_name' => 'German', 'flag_code' => 'de', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 10],
            ['code' => 'ja', 'native_name' => '日本語', 'english_name' => 'Japanese', 'flag_code' => 'jp', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 11],
            ['code' => 'ko', 'native_name' => '한국어', 'english_name' => 'Korean', 'flag_code' => 'kr', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 12],
            ['code' => 'zh-CN', 'native_name' => '简体中文', 'english_name' => 'Chinese (Simplified)', 'flag_code' => 'cn', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 13],
            ['code' => 'ar', 'native_name' => 'العربية', 'english_name' => 'Arabic', 'flag_code' => 'sa', 'is_rtl' => true, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 14],
            ['code' => 'tr', 'native_name' => 'Türkçe', 'english_name' => 'Turkish', 'flag_code' => 'tr', 'is_rtl' => false, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 15],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allCodes(): array
    {
        return array_map(
            static fn (array $lang): string => $lang['code'],
            self::catalog(),
        );
    }

    public function ensureSeeded(): void
    {
        if (! Schema::hasTable('languages') || Language::query()->exists()) {
            return;
        }

        foreach (self::catalog() as $item) {
            Language::query()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'native_name' => $item['native_name'],
                    'english_name' => $item['english_name'],
                    'flag_code' => $item['flag_code'],
                    'is_rtl' => $item['is_rtl'],
                    'is_enabled' => $item['is_enabled'],
                    'is_default' => $item['is_default'],
                    'sort_order' => $item['sort_order'],
                    'completion_percent' => 100,
                    'missing_keys_count' => 0,
                ],
            );
        }

        $this->flushCache();
    }

    /**
     * @return list<array{
     *     code: string,
     *     native_name: string,
     *     english_name: string,
     *     flag_code: string,
     *     is_rtl: bool,
     *     is_enabled: bool,
     *     is_default: bool,
     *     sort_order: int,
     *     completion_percent: int,
     *     missing_keys_count: int
     * }>
     */
    public function allLanguages(): array
    {
        if (! Schema::hasTable('languages')) {
            return array_map(
                static fn (array $item): array => [
                    ...$item,
                    'completion_percent' => 100,
                    'missing_keys_count' => 0,
                ],
                self::catalog(),
            );
        }

        $this->ensureSeeded();

        /** @var list<array{
         *     code: string,
         *     native_name: string,
         *     english_name: string,
         *     flag_code: string,
         *     is_rtl: bool,
         *     is_enabled: bool,
         *     is_default: bool,
         *     sort_order: int,
         *     completion_percent: int,
         *     missing_keys_count: int
         * }> $cached */
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), static function (): array {
            return array_values(
                Language::query()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->map(static fn (Language $lang): array => [
                        'code' => $lang->code,
                        'native_name' => $lang->native_name,
                        'english_name' => $lang->english_name,
                        'flag_code' => $lang->flag_code,
                        'is_rtl' => $lang->is_rtl,
                        'is_enabled' => $lang->is_enabled,
                        'is_default' => $lang->is_default,
                        'sort_order' => $lang->sort_order,
                        'completion_percent' => $lang->completion_percent,
                        'missing_keys_count' => $lang->missing_keys_count,
                    ])
                    ->all(),
            );
        });

        return $cached;
    }

    /**
     * @return list<array{code: string, native_name: string, english_name: string, flag_code: string, is_rtl: bool}>
     */
    public function publicEnabledLanguages(): array
    {
        return array_values(
            array_map(
                static fn (array $lang): array => [
                    'code' => $lang['code'],
                    'native_name' => $lang['native_name'],
                    'english_name' => $lang['english_name'],
                    'flag_code' => $lang['flag_code'],
                    'is_rtl' => $lang['is_rtl'],
                ],
                array_filter(
                    $this->allLanguages(),
                    static fn (array $lang): bool => $lang['is_enabled'],
                ),
            ),
        );
    }

    /**
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        return array_map(
            static fn (array $lang): string => $lang['code'],
            $this->publicEnabledLanguages(),
        );
    }

    public function defaultCode(): string
    {
        foreach ($this->allLanguages() as $lang) {
            if ($lang['is_default'] && $lang['is_enabled']) {
                return $lang['code'];
            }
        }

        return 'en';
    }

    public function isRtl(string $code): bool
    {
        foreach ($this->allLanguages() as $lang) {
            if ($lang['code'] === $code) {
                return $lang['is_rtl'];
            }
        }

        return $code === 'ar';
    }

    public function detectFromAcceptLanguage(?string $header): ?string
    {
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $enabled = $this->enabledCodes();
        $enabledLower = [];
        foreach ($enabled as $code) {
            $enabledLower[strtolower($code)] = $code;
        }

        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = strtolower(trim($segments[0]));
            if ($tag === '') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($segments, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $q = (float) substr(trim($param), 2);
                }
            }
            $candidates[$tag] = $q;
        }

        arsort($candidates);

        foreach (array_keys($candidates) as $tag) {
            if (isset($enabledLower[$tag])) {
                return $enabledLower[$tag];
            }

            $base = explode('-', $tag)[0];

            if ($base === 'zh' && isset($enabledLower['zh-cn'])) {
                return $enabledLower['zh-cn'];
            }

            if ($base === 'pt' && isset($enabledLower['pt-br'])) {
                return $enabledLower['pt-br'];
            }

            if ($base === 'tl' && isset($enabledLower['fil'])) {
                return $enabledLower['fil'];
            }

            if (isset($enabledLower[$base])) {
                return $enabledLower[$base];
            }
        }

        return null;
    }

    public function resolveFrontendJsonPath(string $code): ?string
    {
        $safeCode = in_array($code, self::allCodes(), true) ? $code : null;

        if ($safeCode === null) {
            return null;
        }

        return resource_path("js/i18n/locales/{$safeCode}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public function translationHealth(string $code): array
    {
        $enPath = resource_path('js/i18n/locales/en.json');
        $targetPath = $this->resolveFrontendJsonPath($code);

        if (! file_exists($enPath) || $targetPath === null || ! file_exists($targetPath)) {
            return [
                'total_keys' => 0,
                'translated_keys' => 0,
                'missing_keys' => [],
                'completion_percent' => 0,
            ];
        }

        /** @var array<string, mixed> $enData */
        $enData = json_decode((string) file_get_contents($enPath), true) ?? [];
        /** @var array<string, mixed> $targetData */
        $targetData = json_decode((string) file_get_contents($targetPath), true) ?? [];

        $enFlat = $this->flattenKeys($enData);
        $targetFlat = $this->flattenKeys($targetData);

        if (Schema::hasTable('translation_overrides')) {
            $overrides = TranslationOverride::query()
                ->where('language_code', $code)
                ->pluck('value', 'translation_key')
                ->all();

            foreach ($overrides as $k => $v) {
                $targetFlat[(string) $k] = (string) $v;
            }
        }

        $missing = [];
        foreach ($enFlat as $key => $val) {
            if (! isset($targetFlat[$key]) || trim((string) $targetFlat[$key]) === '') {
                $missing[] = $key;
            }
        }

        $total = count($enFlat);
        $translated = max(0, $total - count($missing));
        $percent = $total > 0 ? (int) floor(($translated / $total) * 100) : 100;

        return [
            'total_keys' => $total,
            'translated_keys' => $translated,
            'missing_keys' => $missing,
            'completion_percent' => $percent,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function overridesForLocale(string $code): array
    {
        if (! Schema::hasTable('translation_overrides')) {
            return [];
        }

        return Cache::remember("syncviet.lang.overrides.{$code}", now()->addMinutes(15), static function () use ($code): array {
            /** @var array<string, string> $pairs */
            $pairs = TranslationOverride::query()
                ->where('language_code', $code)
                ->pluck('value', 'translation_key')
                ->map(static fn (mixed $v): string => (string) $v)
                ->all();

            return $pairs;
        });
    }

    /**
     * @param  array<string, mixed>  $flatMap
     */
    public function importOverrides(Language $language, array $flatMap, User $actor, AuditLogger $auditLogger): int
    {
        $validKeys = [];
        $enPath = resource_path('js/i18n/locales/en.json');
        if (file_exists($enPath)) {
            /** @var array<string, mixed> $enData */
            $enData = json_decode((string) file_get_contents($enPath), true) ?? [];
            $validKeys = $this->flattenKeys($enData);
        }

        $sanitizedEntries = [];
        foreach ($flatMap as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $cleanKey = trim((string) $key);
            $cleanValue = trim(strip_tags($value));

            if ($cleanKey === '' || $cleanValue === '') {
                continue;
            }

            if ($validKeys !== [] && ! array_key_exists($cleanKey, $validKeys)) {
                continue;
            }

            if (mb_strlen($cleanKey) > 180 || mb_strlen($cleanValue) > 2000) {
                continue;
            }

            $sanitizedEntries[$cleanKey] = $cleanValue;
        }

        if ($sanitizedEntries === []) {
            throw ValidationException::withMessages([
                'translations' => [__('No valid translation keys matched the platform schema.')],
            ]);
        }

        DB::transaction(function () use ($language, $sanitizedEntries, $actor): void {
            foreach ($sanitizedEntries as $key => $value) {
                $override = TranslationOverride::query()->firstOrNew([
                    'language_code' => $language->code,
                    'translation_key' => $key,
                ]);
                $override->value = $value;
                $override->updated_by = $actor->id;
                $override->save();
            }

            TranslationRevision::query()->create([
                'language_code' => $language->code,
                'actor_id' => $actor->id,
                'action' => 'import',
                'keys_count' => count($sanitizedEntries),
                'summary' => 'Imported '.count($sanitizedEntries).' keys',
                'created_at' => now(),
            ]);
        });

        $health = $this->translationHealth($language->code);
        $language->forceFill([
            'completion_percent' => $health['completion_percent'],
            'missing_keys_count' => count($health['missing_keys']),
        ])->save();

        Cache::forget("syncviet.lang.overrides.{$language->code}");
        $this->flushCache();

        $auditLogger->log(
            'language.translations_imported',
            $language,
            ['code' => $language->code, 'keys_count' => count($sanitizedEntries)],
            $actor,
        );

        return count($sanitizedEntries);
    }

    public function updateLanguage(
        Language $language,
        bool $isEnabled,
        bool $isDefault,
        int $sortOrder,
        User $actor,
        AuditLogger $auditLogger,
    ): Language {
        if ($isDefault && ! $isEnabled) {
            throw ValidationException::withMessages([
                'is_enabled' => [__('The default language must remain enabled.')],
            ]);
        }

        if ($language->is_default && ! $isEnabled) {
            throw ValidationException::withMessages([
                'is_enabled' => [__('Switch the default language before disabling the active default.')],
            ]);
        }

        DB::transaction(function () use ($language, $isEnabled, $isDefault, $sortOrder): void {
            if ($isDefault) {
                Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
            }

            $language->forceFill([
                'is_enabled' => $isEnabled,
                'is_default' => $isDefault || $language->is_default,
                'sort_order' => max(1, min(999, $sortOrder)),
            ])->save();
        });

        $this->flushCache();

        $auditLogger->log(
            'language.updated',
            $language,
            ['code' => $language->code, 'is_enabled' => $isEnabled, 'is_default' => $language->is_default],
            $actor,
        );

        return $language->fresh() ?? $language;
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function flattenKeys(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $flat = array_merge($flat, $this->flattenKeys($nested, $fullKey));
            } elseif (is_string($value) || is_numeric($value)) {
                $flat[$fullKey] = (string) $value;
            }
        }

        return $flat;
    }
}
