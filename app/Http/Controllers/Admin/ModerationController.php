<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ReportResolveRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ModerationController extends Controller
{
    public function __construct(
        private readonly ModerationService $moderationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        Gate::authorize('access-moderation');

        $payload = $this->moderationService->queuePayload();
        $payload['moderators'] = User::query()
            ->whereIn('role', ['admin', 'moderator'])
            ->orderBy('username')
            ->get(['id', 'name', 'username'])
            ->all();

        return Inertia::render('admin/Moderation', $payload);
    }

    public function resolve(ReportResolveRequest $request, Report $report): RedirectResponse
    {
        Gate::authorize('access-moderation');

        $moderator = $request->user();
        abort_if($moderator === null, 403);

        $this->moderationService->resolveReport(
            $moderator,
            $report,
            $request->string('decision')->toString(),
            $request->string('note')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Moderation decision recorded.'),
        ]);

        return back();
    }

    public function triage(Request $request, Report $report): RedirectResponse
    {
        Gate::authorize('access-moderation');

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'priority' => ['sometimes', 'string', 'in:low,normal,high,critical'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'string', 'in:pending,under_review'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $report->forceFill([
            'priority' => $validated['priority'] ?? $report->priority,
            'assigned_to' => array_key_exists('assigned_to', $validated) ? $validated['assigned_to'] : $report->assigned_to,
            'status' => isset($validated['status']) ? ReportStatus::from((string) $validated['status']) : $report->status,
            'internal_note' => array_key_exists('internal_note', $validated) ? $validated['internal_note'] : $report->internal_note,
        ])->save();

        $this->auditLogger->log('moderation.report_triaged', $report, ['priority' => $report->priority, 'assigned_to' => $report->assigned_to], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moderation decision recorded.')]);

        return back();
    }
}
