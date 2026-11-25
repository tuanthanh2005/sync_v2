<?php

namespace App\Http\Controllers\Monetization;

use App\Http\Controllers\Controller;
use App\Models\BoostPackage;
use App\Models\Post;
use App\Services\Monetization\BoostPostService;
use App\Services\Monetization\PlatformCapabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BoostPostController extends Controller
{
    public function __construct(
        private readonly BoostPostService $boostService,
        private readonly PlatformCapabilityService $platformService,
    ) {}

    public function store(Request $request, Post $post): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'boost_package_id' => ['required', 'integer', 'exists:boost_packages,id'],
            'payment_source' => ['required', 'string', Rule::in(['ad_balance', 'direct'])],
            'currency' => ['required', 'string', Rule::in(['VND', 'USD'])],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'confirmed' => ['required', 'accepted'],
        ]);

        /** @var BoostPackage $package */
        $package = BoostPackage::query()->findOrFail((int) $validated['boost_package_id']);
        $platform = $this->platformService->resolvePlatform($request);

        $result = $this->boostService->purchaseBoost(
            user: $user,
            post: $post,
            package: $package,
            paymentSource: (string) $validated['payment_source'],
            currency: (string) $validated['currency'],
            provider: (string) ($validated['provider'] ?? 'vietqr'),
            idempotencyKey: (string) $validated['idempotency_key'],
            platform: $platform,
        );

        if ($result['payment'] !== null) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Boost Post order :code created. Complete payment to send for review.', ['code' => $result['payment']->order_code ?? $result['payment']->id]),
            ]);

            return to_route('payments.index', ['highlight' => $result['payment']->order_code ?? $result['payment']->id]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post boost submitted for moderation review using your Advertising Balance.'),
        ]);

        return back();
    }
}
