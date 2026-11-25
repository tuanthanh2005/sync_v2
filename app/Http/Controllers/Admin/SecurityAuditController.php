<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRecord;
use App\Models\User;
use App\Services\Admin\AdminSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityAuditController extends Controller
{
    public function __construct(
        private readonly AdminSecurityService $securityService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/Security/Index', $this->securityService->securityPayload([
            'tab' => (string) $request->input('tab', 'audits'),
            'search' => (string) $request->input('search', ''),
            'action' => (string) $request->input('action', ''),
        ]));
    }

    public function revokeSession(Request $request, string $sessionId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->securityService->revokeSession($actor, $sessionId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Active sessions revoked.')]);

        return back();
    }

    public function backups(): Response
    {
        return Inertia::render('admin/Backups/Index', $this->securityService->backupsPayload());
    }

    public function createBackup(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->securityService->createSnapshot($actor, isset($validated['notes']) ? (string) $validated['notes'] : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Snapshot backup created.')]);

        return back();
    }

    public function downloadBackup(BackupRecord $backupRecord): StreamedResponse
    {
        abort_unless(Storage::disk($backupRecord->disk)->exists($backupRecord->path), 404);

        return Storage::disk($backupRecord->disk)->download($backupRecord->path, basename($backupRecord->path));
    }
}
