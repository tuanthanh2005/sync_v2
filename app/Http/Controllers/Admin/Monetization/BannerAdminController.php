<?php

namespace App\Http\Controllers\Admin\Monetization;

use App\Enums\BannerDeliveryMode;
use App\Http\Controllers\Controller;
use App\Models\BannerCampaign;
use App\Models\BannerPlacement;
use App\Models\User;
use App\Rules\ValidUploadedImage;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Security\AuditLogger;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BannerAdminController extends Controller
{
    public function __construct(
        private readonly BannerAdvertisingService $bannerService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        MonetizationSeeder::seedDefaultsIfEmpty();

        $status = $request->string('status', 'all')->toString();

        $placements = BannerPlacement::query()
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (BannerPlacement $p): array => BannerAdvertisingService::serializePlacement($p))
            ->values()
            ->all();

        $campaignsQuery = BannerCampaign::query()
            ->with(['owner:id,name,username', 'placement:id,code,name']);

        if ($status !== 'all') {
            $campaignsQuery->where('status', $status);
        }

        $paginator = $campaignsQuery->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('admin/Monetization/Banners', [
            'placements' => $placements,
            'campaigns' => [
                'data' => array_values(collect($paginator->items())->map(static fn (BannerCampaign $c): array => BannerAdvertisingService::serializeCampaign($c))->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => ['status' => $status],
        ])->withViewData([
            'seo' => ['title' => __('Banner Advertising Management').' - Admin', 'noindex' => true],
        ]);
    }

    public function updatePlacement(Request $request, BannerPlacement $bannerPlacement): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'delivery_mode' => ['required', 'string', Rule::in([BannerDeliveryMode::Exclusive->value, BannerDeliveryMode::Rotation->value])],
            'max_active_banners' => ['required', 'integer', 'min:1', 'max:10'],
            'price_7d_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'price_15d_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'price_30d_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'price_90d_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'price_7d_usd_cents' => ['required', 'integer', 'min:0', 'max:50000000'],
            'price_15d_usd_cents' => ['required', 'integer', 'min:0', 'max:50000000'],
            'price_30d_usd_cents' => ['required', 'integer', 'min:0', 'max:50000000'],
            'price_90d_usd_cents' => ['required', 'integer', 'min:0', 'max:50000000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validated['delivery_mode'] === BannerDeliveryMode::Exclusive->value) {
            $validated['max_active_banners'] = 1;
        }

        $bannerPlacement->update($validated);
        $this->auditLogger->log('admin.banner_placement.updated', $bannerPlacement, ['code' => $bannerPlacement->code], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Banner placement updated.')]);

        return back();
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'owner_username' => ['nullable', 'string', 'max:64'],
            'banner_placement_id' => ['required', 'integer', 'exists:banner_placements,id'],
            'name' => ['required', 'string', 'min:3', 'max:140'],
            'business_name' => ['nullable', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'target_url' => ['required', 'url', 'max:500'],
            'alt_text' => ['required', 'string', 'min:3', 'max:180'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'starts_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', Rule::in(['VND', 'USD'])],
            'payment_status' => ['required', 'string', Rule::in(['manual_paid', 'paid', 'promotional', 'pending'])],
            'auto_approve' => ['required', 'boolean'],
            'desktop_image' => ['nullable', 'file', new ValidUploadedImage('banner')],
            'mobile_image' => ['nullable', 'file', new ValidUploadedImage('banner')],
            'logo' => ['nullable', 'file', new ValidUploadedImage('branding')],
        ]);

        $owner = $actor;
        if (! empty($validated['owner_username'])) {
            $clean = ltrim(Str::lower(trim((string) $validated['owner_username'])), '@');
            /** @var User|null $found */
            $found = User::query()->where('username', $clean)->first();
            if ($found === null) {
                return back()->withErrors(['owner_username' => __('Advertiser username not found.')]);
            }
            $owner = $found;
        }

        $this->bannerService->createCampaign(
            owner: $owner,
            data: $validated,
            desktopImage: $request->file('desktop_image'),
            mobileImage: $request->file('mobile_image'),
            logoFile: $request->file('logo'),
            adminActor: $actor,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Banner campaign created.')]);

        return back();
    }

    public function reviewCampaign(Request $request, BannerCampaign $bannerCampaign): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->bannerService->reviewCampaign(
            reviewer: $actor,
            campaign: $bannerCampaign,
            decision: (string) $validated['decision'],
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Banner review recorded.')]);

        return back();
    }

    public function manageCampaign(Request $request, BannerCampaign $bannerCampaign): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['emergency_pause', 'pause', 'resume', 'cancel'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->bannerService->manageCampaignStatus(
            actor: $actor,
            campaign: $bannerCampaign,
            action: (string) $validated['action'],
            reason: (string) $validated['reason'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Banner status updated.')]);

        return back();
    }
}
