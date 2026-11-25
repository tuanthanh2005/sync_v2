<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\TranslationRevision;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\LanguageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LanguageManagementController extends Controller
{
    public function __construct(
        private readonly LanguageService $languageService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        $this->languageService->ensureSeeded();

        $languages = array_map(function (array $lang): array {
            $health = $this->languageService->translationHealth($lang['code']);

            return [
                ...$lang,
                'total_keys' => $health['total_keys'],
                'translated_keys' => $health['translated_keys'],
                'completion_percent' => $health['completion_percent'],
                'missing_keys' => array_slice((array) $health['missing_keys'], 0, 25),
                'missing_keys_count' => count((array) $health['missing_keys']),
            ];
        }, $this->languageService->allLanguages());

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
            'revisions' => $revisions,
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
}
