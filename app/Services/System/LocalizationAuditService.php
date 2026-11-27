<?php

namespace App\Services\System;

use App\Models\TranslationOverride;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LocalizationAuditService
{
    /** @var list<string> */
    private const PHP_LANG_GROUPS = ['auth', 'pagination', 'passwords', 'validation'];

    public function __construct(
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Run a comprehensive localization audit across all 15 locales, frontend JSON,
     * backend JSON, Laravel PHP translation files, and optionally Vue/PHP/Blade sources.
     *
     * @return array{
     *     generated_at: string,
     *     default_locale: string,
     *     fallback_locale: string,
     *     frontend_total_keys: int,
     *     backend_total_keys: int,
     *     php_lang_total_keys: int,
     *     modules: list<string>,
     *     locales: list<array{
     *         code: string,
     *         native_name: string,
     *         english_name: string,
     *         direction: string,
     *         frontend_total: int,
     *         frontend_translated: int,
     *         frontend_missing: list<string>,
     *         frontend_empty: list<string>,
     *         frontend_extra: list<string>,
     *         frontend_duplicates: list<string>,
     *         frontend_placeholder_errors: list<string>,
     *         frontend_coverage_percent: float,
     *         backend_total: int,
     *         backend_translated: int,
     *         backend_missing: list<string>,
     *         backend_empty: list<string>,
     *         backend_placeholder_errors: list<string>,
     *         backend_coverage_percent: float,
     *         php_lang_total: int,
     *         php_lang_translated: int,
     *         php_lang_missing: list<string>,
     *         php_lang_placeholder_errors: list<string>,
     *         php_lang_coverage_percent: float,
     *         fallback_usage_count: int,
     *         overall_coverage_percent: float,
     *         review_status: string
     *     }>,
     *     source_scan: array{
     *         undefined_frontend_keys: list<array{key: string, file: string}>,
     *         unused_frontend_keys_count: int,
     *         unused_frontend_keys_sample: list<string>,
     *         missing_backend_json_keys: list<array{key: string, file: string}>,
     *         vi_locale_shortcuts: list<string>,
     *         hardcoded_vue_files: list<array{file: string, count: int, samples: list<string>}>,
     *         hardcoded_vue_total: int,
     *         hardcoded_php_messages: list<string>
     *     },
     *     summary: array{
     *         locales_checked: int,
     *         total_missing_keys: int,
     *         total_empty_keys: int,
     *         total_duplicate_keys: int,
     *         total_placeholder_errors: int,
     *         undefined_frontend_keys: int,
     *         missing_backend_keys: int,
     *         vi_locale_shortcuts: int,
     *         hardcoded_vue_total: int,
     *         hardcoded_php_messages: int,
     *         strict_passed: bool
     *     }
     * }
     */
    public function audit(?string $onlyLocale = null, ?string $onlyModule = null, bool $includeSourceScan = true): array
    {
        $catalog = LanguageService::catalog();
        $enFrontend = $this->readFrontendFlat('en');
        $enBackend = $this->readBackendJson('en');
        $enPhpLang = $this->readPhpLangFlat('en');

        $modules = $this->extractModules($enFrontend);

        if ($onlyModule !== null && $onlyModule !== '') {
            $prefix = rtrim($onlyModule, '.').'.';
            $enFrontend = array_filter(
                $enFrontend,
                static fn (string $k): bool => $k === $onlyModule || str_starts_with($k, $prefix),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $frontendTotal = count($enFrontend);
        $backendTotal = count($enBackend);
        $phpLangTotal = count($enPhpLang);

        $localeReports = [];
        $totalMissing = 0;
        $totalEmpty = 0;
        $totalDuplicates = 0;
        $totalPlaceholderErrors = 0;

        foreach ($catalog as $meta) {
            $code = $meta['code'];
            if ($onlyLocale !== null && $onlyLocale !== '' && $code !== $onlyLocale) {
                continue;
            }

            $targetFrontend = $this->readFrontendFlat($code, mergeOverrides: true);
            if ($onlyModule !== null && $onlyModule !== '') {
                $prefix = rtrim($onlyModule, '.').'.';
                $targetFrontend = array_filter(
                    $targetFrontend,
                    static fn (string $k): bool => $k === $onlyModule || str_starts_with($k, $prefix),
                    ARRAY_FILTER_USE_KEY,
                );
            }

            $feDuplicates = $this->detectJsonDuplicateKeys(resource_path("js/i18n/locales/{$code}.json"));
            $feMissing = [];
            $feEmpty = [];
            $fePlaceholderErrors = [];

            foreach ($enFrontend as $key => $enVal) {
                if (! array_key_exists($key, $targetFrontend)) {
                    $feMissing[] = $key;
                } elseif (trim($targetFrontend[$key]) === '') {
                    $feEmpty[] = $key;
                } else {
                    $enPh = $this->extractVuePlaceholders($enVal);
                    $loPh = $this->extractVuePlaceholders($targetFrontend[$key]);
                    if ($enPh !== $loPh || $this->hasUnescapedVueI18nAtSign($targetFrontend[$key])) {
                        $fePlaceholderErrors[] = $key;
                    }
                }
            }

            $feExtra = array_values(array_diff(array_keys($targetFrontend), array_keys($enFrontend)));
            $feTranslated = max(0, $frontendTotal - count($feMissing) - count($feEmpty));
            $feCoverage = $frontendTotal > 0 ? round(($feTranslated / $frontendTotal) * 100, 2) : 100.0;

            // Backend JSON audit
            $targetBackend = $this->readBackendJson($code);
            $beMissing = [];
            $beEmpty = [];
            $bePlaceholderErrors = [];

            foreach ($enBackend as $key => $enVal) {
                if (! array_key_exists($key, $targetBackend)) {
                    $beMissing[] = $key;
                } elseif (trim($targetBackend[$key]) === '') {
                    $beEmpty[] = $key;
                } else {
                    $enPh = $this->extractLaravelPlaceholders($enVal);
                    $loPh = $this->extractLaravelPlaceholders($targetBackend[$key]);
                    if ($enPh !== $loPh) {
                        $bePlaceholderErrors[] = $key;
                    }
                }
            }

            $beTranslated = max(0, $backendTotal - count($beMissing) - count($beEmpty));
            $beCoverage = $backendTotal > 0 ? round(($beTranslated / $backendTotal) * 100, 2) : 100.0;

            // PHP lang files audit (auth, pagination, passwords, validation)
            $targetPhpLang = $this->readPhpLangFlat($code);
            $phpMissing = [];
            $phpPlaceholderErrors = [];

            foreach ($enPhpLang as $key => $enVal) {
                if (! array_key_exists($key, $targetPhpLang) || trim($targetPhpLang[$key]) === '') {
                    $phpMissing[] = $key;
                } else {
                    $enPh = $this->extractLaravelPlaceholders($enVal);
                    $loPh = $this->extractLaravelPlaceholders($targetPhpLang[$key]);
                    if ($enPh !== $loPh) {
                        $phpPlaceholderErrors[] = $key;
                    }
                }
            }

            $phpTranslated = max(0, $phpLangTotal - count($phpMissing));
            $phpCoverage = $phpLangTotal > 0 ? round(($phpTranslated / $phpLangTotal) * 100, 2) : 100.0;

            $combinedTotal = $frontendTotal + $backendTotal + $phpLangTotal;
            $combinedTranslated = $feTranslated + $beTranslated + $phpTranslated;
            $overallCoverage = $combinedTotal > 0 ? round(($combinedTranslated / $combinedTotal) * 100, 2) : 100.0;

            $fallbackUsage = count($feMissing) + count($feEmpty) + count($beMissing) + count($beEmpty) + count($phpMissing);
            $phErrorsTotal = count($fePlaceholderErrors) + count($bePlaceholderErrors) + count($phpPlaceholderErrors);

            $reviewStatus = match (true) {
                $code === 'en' && $fallbackUsage === 0 && $phErrorsTotal === 0 => 'default_source',
                $fallbackUsage === 0 && $phErrorsTotal === 0 => 'complete_pending_native_review',
                default => 'needs_completion',
            };

            $totalMissing += count($feMissing) + count($beMissing) + count($phpMissing);
            $totalEmpty += count($feEmpty) + count($beEmpty);
            $totalDuplicates += count($feDuplicates);
            $totalPlaceholderErrors += $phErrorsTotal;

            $localeReports[] = [
                'code' => $code,
                'native_name' => $meta['native_name'],
                'english_name' => $meta['english_name'],
                'direction' => $meta['is_rtl'] ? 'rtl' : 'ltr',
                'frontend_total' => $frontendTotal,
                'frontend_translated' => $feTranslated,
                'frontend_missing' => $feMissing,
                'frontend_empty' => $feEmpty,
                'frontend_extra' => $feExtra,
                'frontend_duplicates' => $feDuplicates,
                'frontend_placeholder_errors' => $fePlaceholderErrors,
                'frontend_coverage_percent' => $feCoverage,
                'backend_total' => $backendTotal,
                'backend_translated' => $beTranslated,
                'backend_missing' => $beMissing,
                'backend_empty' => $beEmpty,
                'backend_placeholder_errors' => $bePlaceholderErrors,
                'backend_coverage_percent' => $beCoverage,
                'php_lang_total' => $phpLangTotal,
                'php_lang_translated' => $phpTranslated,
                'php_lang_missing' => $phpMissing,
                'php_lang_placeholder_errors' => $phpPlaceholderErrors,
                'php_lang_coverage_percent' => $phpCoverage,
                'fallback_usage_count' => $fallbackUsage,
                'overall_coverage_percent' => $overallCoverage,
                'review_status' => $reviewStatus,
            ];
        }

        $sourceScan = $includeSourceScan
            ? $this->scanSources($enFrontend, $enBackend)
            : [
                'undefined_frontend_keys' => [],
                'unused_frontend_keys_count' => 0,
                'unused_frontend_keys_sample' => [],
                'missing_backend_json_keys' => [],
                'vi_locale_shortcuts' => [],
                'hardcoded_vue_files' => [],
                'hardcoded_vue_total' => 0,
                'hardcoded_php_messages' => [],
            ];

        $strictPassed = $totalMissing === 0
            && $totalEmpty === 0
            && $totalDuplicates === 0
            && $totalPlaceholderErrors === 0
            && count($sourceScan['undefined_frontend_keys']) === 0
            && count($sourceScan['missing_backend_json_keys']) === 0
            && count($sourceScan['vi_locale_shortcuts']) === 0
            && $sourceScan['hardcoded_vue_total'] === 0
            && count($sourceScan['hardcoded_php_messages']) === 0;

        return [
            'generated_at' => now()->toIso8601String(),
            'default_locale' => $this->languageService->defaultCode(),
            'fallback_locale' => (string) config('localization.fallback_locale', 'en'),
            'frontend_total_keys' => $frontendTotal,
            'backend_total_keys' => $backendTotal,
            'php_lang_total_keys' => $phpLangTotal,
            'modules' => $modules,
            'locales' => $localeReports,
            'source_scan' => $sourceScan,
            'summary' => [
                'locales_checked' => count($localeReports),
                'total_missing_keys' => $totalMissing,
                'total_empty_keys' => $totalEmpty,
                'total_duplicate_keys' => $totalDuplicates,
                'total_placeholder_errors' => $totalPlaceholderErrors,
                'undefined_frontend_keys' => count($sourceScan['undefined_frontend_keys']),
                'missing_backend_keys' => count($sourceScan['missing_backend_json_keys']),
                'vi_locale_shortcuts' => count($sourceScan['vi_locale_shortcuts']),
                'hardcoded_vue_total' => $sourceScan['hardcoded_vue_total'],
                'hardcoded_php_messages' => count($sourceScan['hardcoded_php_messages']),
                'strict_passed' => $strictPassed,
            ],
        ];
    }

    /**
     * Browse keys for the Admin Localization Center (side-by-side English source vs target locale).
     *
     * @return array{
     *     locale: string,
     *     modules: list<string>,
     *     total_matched: int,
     *     items: list<array{
     *         key: string,
     *         module: string,
     *         en_value: string,
     *         locale_value: string,
     *         is_overridden: bool,
     *         status: string
     *     }>
     * }
     */
    public function browseKeys(
        string $locale,
        ?string $module = null,
        ?string $search = null,
        string $statusFilter = 'all',
        int $limit = 100,
    ): array {
        $safeLocale = in_array($locale, LanguageService::allCodes(), true) ? $locale : 'en';
        $enFlat = $this->readFrontendFlat('en');
        $targetBase = $this->readFrontendFlat($safeLocale, mergeOverrides: false);
        $overrides = $this->languageService->overridesForLocale($safeLocale);
        $modules = $this->extractModules($enFlat);

        $searchNeedle = $search !== null ? mb_strtolower(trim($search)) : '';
        $items = [];
        $totalMatched = 0;

        foreach ($enFlat as $key => $enVal) {
            $keyModule = str_contains($key, '.') ? explode('.', $key)[0] : 'common';

            if ($module !== null && $module !== '' && $module !== 'all' && $keyModule !== $module) {
                continue;
            }

            $isOverridden = array_key_exists($key, $overrides);
            $localeVal = $isOverridden ? $overrides[$key] : ($targetBase[$key] ?? '');

            $status = 'translated';
            if (! array_key_exists($key, $targetBase) && ! $isOverridden) {
                $status = 'missing';
            } elseif (trim($localeVal) === '') {
                $status = 'empty';
            } elseif (
                $this->extractVuePlaceholders($enVal) !== $this->extractVuePlaceholders($localeVal)
                || $this->hasUnescapedVueI18nAtSign($localeVal)
            ) {
                $status = 'placeholder_error';
            } elseif ($isOverridden) {
                $status = 'overridden';
            }

            if ($statusFilter !== 'all' && $status !== $statusFilter) {
                continue;
            }

            if ($searchNeedle !== '') {
                $haystack = mb_strtolower($key.' '.$enVal.' '.$localeVal);
                if (! str_contains($haystack, $searchNeedle)) {
                    continue;
                }
            }

            $totalMatched++;
            if (count($items) < $limit) {
                $items[] = [
                    'key' => $key,
                    'module' => $keyModule,
                    'en_value' => $enVal,
                    'locale_value' => $localeVal,
                    'is_overridden' => $isOverridden,
                    'status' => $status,
                ];
            }
        }

        return [
            'locale' => $safeLocale,
            'modules' => $modules,
            'total_matched' => $totalMatched,
            'items' => $items,
        ];
    }

    /**
     * Format coverage audit data as a formula-safe CSV string.
     *
     * @param  array<string, mixed>  $report
     */
    public function toCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'Locale',
            'Native Name',
            'Direction',
            'Frontend Total',
            'Frontend Translated',
            'Frontend Missing',
            'Frontend Empty',
            'Frontend Placeholder Errors',
            'Frontend Coverage %',
            'Backend Total',
            'Backend Translated',
            'Backend Missing',
            'Backend Coverage %',
            'PHP Lang Total',
            'PHP Lang Translated',
            'PHP Lang Coverage %',
            'Overall Coverage %',
            'Review Status',
        ]);

        /** @var list<array<string, mixed>> $locales */
        $locales = $report['locales'] ?? [];
        foreach ($locales as $row) {
            fputcsv($handle, [
                $this->csvSafe((string) $row['code']),
                $this->csvSafe((string) $row['native_name']),
                $this->csvSafe((string) $row['direction']),
                (int) $row['frontend_total'],
                (int) $row['frontend_translated'],
                count((array) $row['frontend_missing']),
                count((array) $row['frontend_empty']),
                count((array) $row['frontend_placeholder_errors']),
                (float) $row['frontend_coverage_percent'],
                (int) $row['backend_total'],
                (int) $row['backend_translated'],
                count((array) $row['backend_missing']),
                (float) $row['backend_coverage_percent'],
                (int) $row['php_lang_total'],
                (int) $row['php_lang_translated'],
                (float) $row['php_lang_coverage_percent'],
                (float) $row['overall_coverage_percent'],
                $this->csvSafe((string) $row['review_status']),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return array<string, string>
     */
    private function readFrontendFlat(string $code, bool $mergeOverrides = false): array
    {
        $path = $this->languageService->resolveFrontendJsonPath($code);
        if ($path === null || ! file_exists($path)) {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $flat = $this->languageService->flattenKeys($decoded);

        if ($mergeOverrides && Schema::hasTable('translation_overrides')) {
            $overrides = TranslationOverride::query()
                ->where('language_code', $code)
                ->pluck('value', 'translation_key')
                ->all();

            foreach ($overrides as $k => $v) {
                $flat[(string) $k] = (string) $v;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, string>
     */
    private function readBackendJson(string $code): array
    {
        if (! in_array($code, LanguageService::allCodes(), true)) {
            return [];
        }

        $path = base_path("lang/{$code}.json");
        if (! file_exists($path)) {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = is_scalar($v) ? (string) $v : '';
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function readPhpLangFlat(string $code): array
    {
        if (! in_array($code, LanguageService::allCodes(), true)) {
            return [];
        }

        $out = [];
        foreach (self::PHP_LANG_GROUPS as $group) {
            $path = base_path("lang/{$code}/{$group}.php");
            if (! file_exists($path)) {
                continue;
            }

            /** @var mixed $loaded */
            $loaded = require $path;
            if (! is_array($loaded)) {
                continue;
            }

            // Exclude empty custom/attributes scaffolds from key parity count
            unset($loaded['custom'], $loaded['attributes']);

            $flat = $this->languageService->flattenKeys($loaded, $group);
            foreach ($flat as $k => $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $enFlat
     * @return list<string>
     */
    private function extractModules(array $enFlat): array
    {
        $set = [];
        foreach (array_keys($enFlat) as $key) {
            $mod = str_contains($key, '.') ? explode('.', $key)[0] : 'common';
            $set[$mod] = true;
        }
        $modules = array_keys($set);
        sort($modules);

        return $modules;
    }

    /**
     * @return list<string>
     */
    private function extractVuePlaceholders(string $value): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $value, $matches);
        $list = array_values(array_unique($matches[1]));
        sort($list);

        return $list;
    }

    /**
     * Detect unescaped `@` characters in vue-i18n v11 message strings.
     * In vue-i18n, raw `@` begins linked-message syntax and throws a fatal SyntaxError at runtime
     * unless escaped as `{'@'}` (or `{"@"}`).
     */
    private function hasUnescapedVueI18nAtSign(string $value): bool
    {
        if (! str_contains($value, '@')) {
            return false;
        }

        $withoutEscapedLiterals = str_replace(["{'@'}", '{"@"}'], '', $value);

        return str_contains($withoutEscapedLiterals, '@');
    }

    /**
     * @return list<string>
     */
    private function extractLaravelPlaceholders(string $value): array
    {
        preg_match_all('/:([a-zA-Z][a-zA-Z0-9_]*)/', $value, $matches);
        $list = array_values(array_unique($matches[1]));
        sort($list);

        return $list;
    }

    /**
     * Detect duplicate sibling keys in a JSON file before PHP's json_decode overwrites them.
     *
     * @return list<string>
     */
    private function detectJsonDuplicateKeys(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $raw = (string) file_get_contents($path);
        $duplicates = [];
        $stack = [[]];
        $len = strlen($raw);
        $inString = false;
        $escaped = false;
        $buf = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inString) {
                if ($escaped) {
                    $buf .= $ch;
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                    // Look ahead past whitespace to see if this string is an object key
                    $j = $i + 1;
                    while ($j < $len && ctype_space($raw[$j])) {
                        $j++;
                    }
                    if ($j < $len && $raw[$j] === ':') {
                        $topIdx = count($stack) - 1;
                        if (isset($stack[$topIdx][$buf])) {
                            $duplicates[] = $buf;
                        } else {
                            $stack[$topIdx][$buf] = true;
                        }
                    }
                    $buf = '';
                } else {
                    $buf .= $ch;
                }
            } else {
                if ($ch === '"') {
                    $inString = true;
                    $buf = '';
                } elseif ($ch === '{') {
                    $stack[] = [];
                } elseif ($ch === '}' && count($stack) > 1) {
                    array_pop($stack);
                }
            }
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * @param  array<string, string>  $enFrontend
     * @param  array<string, string>  $enBackend
     * @return array{
     *     undefined_frontend_keys: list<array{key: string, file: string}>,
     *     unused_frontend_keys_count: int,
     *     unused_frontend_keys_sample: list<string>,
     *     missing_backend_json_keys: list<array{key: string, file: string}>,
     *     vi_locale_shortcuts: list<string>,
     *     hardcoded_vue_files: list<array{file: string, count: int, samples: list<string>}>,
     *     hardcoded_vue_total: int,
     *     hardcoded_php_messages: list<string>
     * }
     */
    private function scanSources(array $enFrontend, array $enBackend): array
    {
        $usedFrontendKeys = [];
        $undefinedFrontendKeys = [];
        $viShortcuts = [];
        $hardcodedVueFiles = [];
        $hardcodedVueTotal = 0;

        /** @var list<string> $allowTokens */
        $allowTokens = config('localization.non_translatable_tokens', []);
        $allowLookup = array_fill_keys(array_map('strtolower', $allowTokens), true);

        $jsRoot = resource_path('js');
        if (is_dir($jsRoot)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jsRoot));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $ext = $file->getExtension();
                if (! in_array($ext, ['vue', 'ts'], true)) {
                    continue;
                }

                $rel = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $file->getPathname()), '\\/'));
                if (
                    str_starts_with($rel, 'resources/js/actions/')
                    || str_starts_with($rel, 'resources/js/routes/')
                    || str_starts_with($rel, 'resources/js/wayfinder/')
                ) {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                if (preg_match_all('/(?:\$t|\bt)\(\s*[\'"]([a-zA-Z0-9_.-]+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $usedFrontendKeys[$key] = true;
                        if (! array_key_exists($key, $enFrontend)) {
                            $undefinedFrontendKeys[] = ['key' => $key, 'file' => $rel];
                        }
                    }
                }

                if (preg_match('/locale(?:\.value)?\s*===?\s*[\'"]vi[\'"]|[\'"]vi[\'"]\s*===?\s*locale/', $content)) {
                    $viShortcuts[] = $rel;
                }

                if ($ext === 'vue' && preg_match('/<template>([\s\S]*)<\/template>/', $content, $tplMatch)) {
                    $tplRaw = $tplMatch[1];
                    $tplClean = (string) preg_replace('/<!--[\s\S]*?-->/', '', $tplRaw);
                    $tplClean = (string) preg_replace('/\{\{[\s\S]*?\}\}/', '', $tplClean);

                    $found = [];
                    if (preg_match_all('/>([^<>{}]+)</u', $tplClean, $textMatches)) {
                        foreach ($textMatches[1] as $rawTxt) {
                            $txt = trim((string) preg_replace('/\s+/u', ' ', $rawTxt));
                            if ($this->isHardcodedUiText($txt, $allowLookup)) {
                                $found[] = $txt;
                            }
                        }
                    }

                    if (preg_match_all('/(?<![:@a-zA-Z0-9-])(placeholder|aria-label|title)="([^"]+)"/u', $tplRaw, $attrMatches, PREG_SET_ORDER)) {
                        foreach ($attrMatches as $am) {
                            $val = trim($am[2]);
                            if ($this->isHardcodedUiText($val, $allowLookup)) {
                                $found[] = "{$am[1]}=\"{$val}\"";
                            }
                        }
                    }

                    if ($found !== []) {
                        $hardcodedVueTotal += count($found);
                        $hardcodedVueFiles[] = [
                            'file' => $rel,
                            'count' => count($found),
                            'samples' => array_slice($found, 0, 5),
                        ];
                    }
                }
            }
        }

        // Unused frontend keys (informational only; excludes dynamic lookup prefixes)
        $dynamicPrefixes = ['admin.nav.', 'admin.groups.', 'roles.', 'status.', 'reactions.', 'categories.', 'seo.', 'ai.', 'growth.', 'monetization.'];
        $unusedKeys = [];
        foreach (array_keys($enFrontend) as $key) {
            if (isset($usedFrontendKeys[$key])) {
                continue;
            }
            $isDynamic = false;
            foreach ($dynamicPrefixes as $dp) {
                if (str_starts_with($key, $dp)) {
                    $isDynamic = true;
                    break;
                }
            }
            if (! $isDynamic) {
                $unusedKeys[] = $key;
            }
        }

        // Scan PHP + Blade files
        $missingBackendKeys = [];
        $seenMissingBe = [];
        $hardcodedPhpMessages = [];

        foreach ([app_path(), resource_path('views')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $rel = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $file->getPathname()), '\\/'));
                $content = (string) file_get_contents($file->getPathname());

                if (preg_match_all('/__\(\s*\'((?:\\\\\'|[^\'])+)\'/', $content, $sqMatches)) {
                    foreach ($sqMatches[1] as $rawKey) {
                        $key = str_replace("\\'", "'", $rawKey);
                        if (! array_key_exists($key, $enBackend) && ! isset($seenMissingBe[$key])) {
                            $seenMissingBe[$key] = true;
                            $missingBackendKeys[] = ['key' => $key, 'file' => $rel];
                        }
                    }
                }

                if (preg_match_all('/__\(\s*"([^"$]+)"/', $content, $dqMatches)) {
                    foreach ($dqMatches[1] as $key) {
                        if (! array_key_exists($key, $enBackend) && ! isset($seenMissingBe[$key])) {
                            $seenMissingBe[$key] = true;
                            $missingBackendKeys[] = ['key' => $key, 'file' => $rel];
                        }
                    }
                }

                if (str_starts_with($rel, 'resources/views/') && preg_match('/isLocale\(\s*[\'"]vi[\'"]\s*\)/', $content)) {
                    $viShortcuts[] = $rel;
                }

                if (str_starts_with($rel, 'app/') && ! str_contains($rel, 'LocalizationAuditService.php')) {
                    if (preg_match_all('/abort\(\s*\d+\s*,\s*\'([^\']+)\'\s*\)/', $content, $abortMatches)) {
                        foreach ($abortMatches[1] as $msg) {
                            $hardcodedPhpMessages[] = "{$rel}: abort('{$msg}')";
                        }
                    }
                }
            }
        }

        usort($hardcodedVueFiles, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'undefined_frontend_keys' => $undefinedFrontendKeys,
            'unused_frontend_keys_count' => count($unusedKeys),
            'unused_frontend_keys_sample' => array_slice($unusedKeys, 0, 25),
            'missing_backend_json_keys' => $missingBackendKeys,
            'vi_locale_shortcuts' => array_values(array_unique($viShortcuts)),
            'hardcoded_vue_files' => $hardcodedVueFiles,
            'hardcoded_vue_total' => $hardcodedVueTotal,
            'hardcoded_php_messages' => $hardcodedPhpMessages,
        ];
    }

    /**
     * @param  array<string, bool>  $allowLookup
     */
    private function isHardcodedUiText(string $txt, array $allowLookup): bool
    {
        if ($txt === '' || mb_strlen($txt) <= 1) {
            return false;
        }

        if (isset($allowLookup[strtolower($txt)])) {
            return false;
        }

        // Ignore pure punctuation, numbers, symbols, URLs, paths, emails, @handles, currency symbols
        if (preg_match('/^[\d\s.,:%+\-–—\/|·•#@$€£¥₫()\[\]{}?!&*=<>~`\'\"\\\\×]+$/u', $txt)) {
            return false;
        }

        if (preg_match('/^(https?:\/\/|\/[a-z0-9_.\-\/]+|@[a-zA-Z0-9_.-]+|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|#[a-zA-Z0-9_]+|ca-pub-|google\.com)/i', $txt)) {
            return false;
        }

        // Must contain at least one letter (Latin, Vietnamese, Arabic, CJK, Devanagari, Thai, Hangul, Kana)
        if (! preg_match('/\p{L}/u', $txt)) {
            return false;
        }

        return true;
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
