<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ReportResolveRequest;
use App\Models\Report;
use App\Services\Moderation\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ModerationController extends Controller
{
    public function __construct(
        private readonly ModerationService $moderationService,
    ) {}

    public function index(): Response
    {
        Gate::authorize('access-moderation');

        return Inertia::render('admin/Moderation', $this->moderationService->queuePayload());
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
}
