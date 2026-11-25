<?php

namespace App\Http\Controllers\Moderation;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ReportStoreRequest;
use App\Services\Moderation\ModerationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(
        private readonly ModerationService $moderationService,
    ) {}

    public function store(ReportStoreRequest $request): RedirectResponse
    {
        $reporter = $request->user();
        abort_if($reporter === null, 403);

        $this->moderationService->submitReport(
            $reporter,
            $request->string('target_type')->toString(),
            (int) $request->validated('target_id'),
            ReportReason::from($request->string('reason')->toString()),
            $request->string('details')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your report has been submitted for moderation review.'),
        ]);

        return back();
    }
}
