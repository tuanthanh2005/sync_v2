<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingService $settingService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Settings/Index', [
            'settings' => $this->settingService->all(),
            'schema' => SystemSettingService::defaults(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        /** @var array<string, mixed> $values */
        $values = $validated['settings'];

        $this->settingService->updateMany($values, $actor, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('System settings saved.')]);

        return back();
    }
}
