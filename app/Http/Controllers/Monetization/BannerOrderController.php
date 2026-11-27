<?php

namespace App\Http\Controllers\Monetization;

use App\Http\Controllers\Controller;
use App\Rules\ValidUploadedImage;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Monetization\PlatformCapabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BannerOrderController extends Controller
{
    public function __construct(
        private readonly BannerAdvertisingService $bannerService,
        private readonly PlatformCapabilityService $platformService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
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
            'provider' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'confirmed' => ['required', 'accepted'],
            'desktop_image' => ['nullable', 'file', new ValidUploadedImage('banner')],
            'mobile_image' => ['nullable', 'file', new ValidUploadedImage('banner')],
            'logo' => ['nullable', 'file', new ValidUploadedImage('branding')],
        ]);

        $platform = $this->platformService->resolvePlatform($request);

        /** @var array<string, mixed> $payload */
        $payload = [
            ...$validated,
            'platform' => $platform,
        ];

        $result = $this->bannerService->createCampaign(
            owner: $user,
            data: $payload,
            desktopImage: $request->file('desktop_image'),
            mobileImage: $request->file('mobile_image'),
            logoFile: $request->file('logo'),
        );

        $payment = $result['payment'];
        $orderCode = $payment !== null ? ($payment->order_code ?? $payment->id) : null;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Banner booking order :code created. Complete payment for moderation review.', ['code' => $orderCode]),
        ]);

        return to_route('payments.index', ['highlight' => $orderCode]);
    }
}
