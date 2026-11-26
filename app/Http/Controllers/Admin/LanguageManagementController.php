<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\TranslationRevision;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\LanguageService;
use App\Services\System\LocalizationAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class LanguageManagementController extends Controller
{
    public function __construct(
        private readonly LanguageService $languageService,
        private readonly LocalizationAuditService $auditService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $this->languageService->ensureSeeded();

        $audit = $this->auditService->audit(includeSourceScan: false);
        $auditByCode = [];
        foreach ($audit['locales'] as $row) {
            $auditByCode[$row['code']] = $row;
        }

        $languages = array_map(function (array $lang) use ($auditByCode): array {
            $health = $this->languageService->translationHealth($lang['code']);
            $auditRow = $auditByCode[$lang['code']] ?? null;
            $overridesCount = count($this->languageService->overridesForLocale($lang['code']));

            return [
                ...$lang,
                'total_keys' => $health['total_keys'],
                'translated_keys' => $health['translated_keys'],
                'completion_percent' => $health['completion_percent'],
                'missing_keys' => array_slice((array) $health['missing_keys'], 0, 25),
                'missing_keys_count' => count((array) $health['missing_keys']),
                'overrides_count' => $overridesCount,
                'frontend_empty_count' => $auditRow !== null ? count($auditRow['frontend_empty']) : 0,
                'frontend_placeholder_errors_count' => $auditRow !== null ? count($auditRow['frontend_placeholder_errors']) : 0,
                'backend_total' => $auditRow['backend_total'] ?? 0,
                'backend_translated' => $auditRow['backend_translated'] ?? 0,
                'backend_coverage_percent' => $auditRow['backend_coverage_percent'] ?? 100.0,
                'php_lang_total' => $auditRow['php_lang_total'] ?? 0,
                'php_lang_translated' => $auditRow['php_lang_translated'] ?? 0,
                'php_lang_coverage_percent' => $auditRow['php_lang_coverage_percent'] ?? 100.0,
                'overall_coverage_percent' => $auditRow['overall_coverage_percent'] ?? (float) $health['completion_percent'],
                'review_status' => $auditRow['review_status'] ?? 'complete_pending_native_review',
            ];
        }, $this->languageService->allLanguages());

        $browseLocale = is_string($request->query('browse_locale')) && in_array($request->query('browse_locale'), LanguageService::allCodes(), true)
            ? (string) $request->query('browse_locale')
            : 'vi';
        $browseModule = is_string($request->query('browse_module')) ? trim((string) $request->query('browse_module')) : 'all';
        $browseSearch = is_string($request->query('browse_search')) ? trim((string) $request->query('browse_search')) : '';
        $browseStatus = is_string($request->query('browse_status')) && in_array($request->query('browse_status'), ['all', 'translated', 'missing', 'empty', 'placeholder_error', 'overridden'], true)
            ? (string) $request->query('browse_status')
            : 'all';

        $keyBrowser = $this->auditService->browseKeys(
            locale: $browseLocale,
            module: $browseModule,
            search: $browseSearch !== '' ? $browseSearch : null,
            statusFilter: $browseStatus,
            limit: 60,
        );

        $revisions = TranslationRevision::query()
            ->with(['actor:id,username'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (TranslationRevision $rev): array => [
                'id' => $rev->id,
                'language_code' => $rev->language_code,
                'action' => $rev->action,
                'keys_count' => $rev->keys_count,
                'summary' => $rev->summary,
                'actor_username' => $rev->actor?->username,
                'created_at' => $rev->created_at->toIso8601String(),
            ])->values()->all();

        return Inertia::render('admin/Languages/Index', [
            'languages' => $languages,
            'auditSummary' => [
                'default_locale' => $audit['default_locale'],
                'fallback_locale' => $audit['fallback_locale'],
                'frontend_total_keys' => $audit['frontend_total_keys'],
                'backend_total_keys' => $audit['backend_total_keys'],
                'php_lang_total_keys' => $audit['php_lang_total_keys'],
                'total_missing_keys' => $audit['summary']['total_missing_keys'],
                'total_empty_keys' => $audit['summary']['total_empty_keys'],
                'total_duplicate_keys' => $audit['summary']['total_duplicate_keys'],
                'total_placeholder_errors' => $audit['summary']['total_placeholder_errors'],
            ],
            'keyBrowser' => $keyBrowser,
            'filters' => [
                'browse_locale' => $browseLocale,
                'browse_module' => $browseModule,
                'browse_search' => $browseSearch,
                'browse_status' => $browseStatus,
            ],
            'revisions' => $revisions,
        ]);
    }

    public function exportReport(Request $request): HttpResponse|JsonResponse
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        $report = $this->auditService->audit(includeSourceScan: false);

        if ($format === 'json') {
            return response()->json($report, 200, [
                'Content-Disposition' => 'attachment; filename="syncviet-localization-report.json"',
            ]);
        }

        return response($this->auditService->toCsv($report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="syncviet-localization-report.csv"',
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $this->languageService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        /** @var Language $language */
        $language = Language::query()->where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:1', 'max:999'],
        ]);

        $this->languageService->updateLanguage(
            language: $language,
            isEnabled: (bool) $validated['is_enabled'],
            isDefault: (bool) ($validated['is_default'] ?? $language->is_default),
            sortOrder: (int) ($validated['sort_order'] ?? $language->sort_order),
            actor: $actor,
            auditLogger: $this->auditLogger,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Language updated.')]);

        return back();
    }

    public function export(string $code): JsonResponse
    {
        $path = $this->languageService->resolveFrontendJsonPath($code);
        abort_if($path === null || ! file_exists($path), 404);

        /** @var array<string, mixed> $base */
        $base = json_decode((string) file_get_contents($path), true) ?? [];
        $flat = $this->languageService->flattenKeys($base);
        $overrides = $this->languageService->overridesForLocale($code);

        return response()
            ->json(array_merge($flat, $overrides), 200, [
                'Content-Disposition' => "attachment; filename=\"syncviet-locale-{$code}.json\"",
            ]);
    }

    public function import(Request $request, string $code): RedirectResponse
    {
        $this->languageService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        /** @var Language $language */
        $language = Language::query()->where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'json_payload' => ['required', 'string', 'max:250000'],
        ]);

        $decoded = json_decode((string) $validated['json_payload'], true);

        if (! is_array($decoded)) {
            return back()->withErrors(['json_payload' => __('Invalid JSON payload.')]);
        }

        $flatMap = $this->languageService->flattenKeys($decoded);
        $this->languageService->importOverrides($language, $flatMap, $actor, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Translation overrides imported.')]);

        return back();
    }

    public function rollback(Request $request, string $code): RedirectResponse
    {
        $this->languageService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        /** @var Language $language */
        $language = Language::query()->where('code', $code)->firstOrFail();

        $removed = $this->languageService->rollbackOverrides($language, $actor, $this->auditLogger);

        Inertia::flash('toast', [
            'type' => $removed > 0 ? 'success' : 'info',
            'message' => $removed > 0
                ? __('Rolled back :count custom translation overrides.', ['count' => $removed])
                : __('No custom overrides exist for this language.'),
        ]);

        return back();
    }
}
