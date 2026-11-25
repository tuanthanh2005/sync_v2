<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\AdCampaignReviewRequest;
use App\Models\AdCampaign;
use App\Services\Ads\AdCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdReviewController extends Controller
{
    public function __construct(
        private readonly AdCampaignService $adCampaignService,
    ) {}

    public function index(): Response
    {
        Gate::authorize('review-ads');

        return Inertia::render('admin/Ads', $this->adCampaignService->reviewQueuePayload());
    }

    public function review(AdCampaignReviewRequest $request, AdCampaign $adCampaign): RedirectResponse
    {
        Gate::authorize('review-ads');

        $reviewer = $request->user();
        abort_if($reviewer === null, 403);

        $this->adCampaignService->reviewCampaign(
            $reviewer,
            $adCampaign,
            $request->string('decision')->toString(),
            $request->string('reason')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ad campaign review completed.'),
        ]);

        return back();
    }
}
