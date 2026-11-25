<?php

namespace App\Http\Controllers\Ads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\AdCampaignStatusRequest;
use App\Http\Requests\Ads\AdCampaignStoreRequest;
use App\Models\AdCampaign;
use App\Services\Ads\AdCampaignService;
use App\Services\Ads\AdDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdCampaignController extends Controller
{
    public function __construct(
        private readonly AdCampaignService $adCampaignService,
        private readonly AdDeliveryService $adDeliveryService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AdCampaign::class);

        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Ads', $this->adCampaignService->dashboardPayload($user));
    }

    public function store(AdCampaignStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', AdCampaign::class);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{
         *     post_id: int,
         *     name?: string|null,
         *     objective: string,
         *     target_topics?: list<string>|null,
         *     target_url?: string|null,
         *     cta_label?: string|null,
         *     schedule_mode: string,
         *     starts_at?: string|null,
         *     ends_at?: string|null,
         *     budget_type: string,
         *     daily_budget?: int|null,
         *     duration_days?: int|null,
         *     total_budget?: int|null
         * } $validated
         */
        $validated = $request->validated();

        $this->adCampaignService->createCampaign($user, $validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Campaign submitted for review and budget reserved.'),
        ]);

        return back();
    }

    public function updateStatus(AdCampaignStatusRequest $request, AdCampaign $adCampaign): RedirectResponse
    {
        Gate::authorize('updateStatus', $adCampaign);

        $user = $request->user();
        abort_if($user === null, 403);

        $this->adCampaignService->updateCampaignStatus(
            $user,
            $adCampaign,
            $request->string('action')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Campaign status updated.'),
        ]);

        return back();
    }

    public function click(Request $request, AdCampaign $adCampaign): RedirectResponse
    {
        $destination = $this->adDeliveryService->recordClickAndResolveDestination(
            $adCampaign,
            $request->user(),
            $request,
        );

        return redirect()->away($destination);
    }
}
