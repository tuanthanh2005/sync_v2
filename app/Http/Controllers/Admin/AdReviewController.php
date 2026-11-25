<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\AdCampaignReviewRequest;
use App\Models\AdCampaign;
use App\Models\User;
use App\Services\Ads\AdCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdReviewController extends Controller
{
    public function __construct(
        private readonly AdCampaignService $adCampaignService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('review-ads');

        $payload = $this->adCampaignService->reviewQueuePayload();
        $statusFilter = (string) $request->input('status', 'all');

        if ($statusFilter !== 'all') {
            $payload['campaigns'] = array_values(array_filter(
                (array) $payload['campaigns'],
                static fn (array $item): bool => ($item['status'] ?? '') === $statusFilter,
            ));
        }

        // Detect suspicious ad click bursts (>10 clicks from same IP hash in 24h)
        $anomalies = DB::table('ad_events')
            ->where('event_type', 'click')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('ad_campaign_id, ip_hash, COUNT(*) as clicks')
            ->groupBy('ad_campaign_id', 'ip_hash')
            ->havingRaw('COUNT(*) >= 10')
            ->limit(20)
            ->get()
            ->map(static fn (object $row): array => [
                'ad_campaign_id' => (int) $row->ad_campaign_id,
                'clicks' => (int) $row->clicks,
            ])->values()->all();

        $payload['statusFilter'] = $statusFilter;
        $payload['anomalies'] = $anomalies;

        return Inertia::render('admin/Ads', $payload);
    }

    public function review(AdCampaignReviewRequest $request, AdCampaign $adCampaign): RedirectResponse
    {
        Gate::authorize('review-ads');

        /** @var User $reviewer */
        $reviewer = $request->user();

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

    public function manage(Request $request, AdCampaign $adCampaign): RedirectResponse
    {
        Gate::authorize('review-ads');

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:pause,resume,cancel'],
        ]);

        $this->adCampaignService->updateCampaignStatus($actor, $adCampaign, (string) $validated['action']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Campaign status updated.'),
        ]);

        return back();
    }
}
