<?php

namespace App\Http\Controllers\Monetization;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Services\Monetization\PlatformCapabilityService;
use App\Services\Monetization\PremiumSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PremiumController extends Controller
{
    public function __construct(
        private readonly PremiumSubscriptionService $premiumService,
        private readonly PlatformCapabilityService $platformService,
    ) {}

    public function account(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('monetization/AccountPremium', [
            ...$this->premiumService->accountPayload($user),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
        ])->withViewData([
            'seo' => [
                'title' => __('My SysNoxis Premium').' - '.config('app.name'),
                'noindex' => true,
            ],
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:premium_plans,id'],
            'currency' => ['required', 'string', Rule::in(['VND', 'USD'])],
            'provider' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'confirmed' => ['required', 'accepted'],
        ]);

        /** @var PremiumPlan $plan */
        $plan = PremiumPlan::query()->findOrFail((int) $validated['plan_id']);
        $platform = $this->platformService->resolvePlatform($request);

        $payment = $this->premiumService->createPurchaseOrder(
            user: $user,
            plan: $plan,
            currency: (string) $validated['currency'],
            provider: (string) $validated['provider'],
            idempotencyKey: (string) $validated['idempotency_key'],
            platform: $platform,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Premium payment order :code created. Complete payment to activate your badge.', ['code' => $payment->order_code ?? $payment->id]),
        ]);

        return to_route('payments.index', ['highlight' => $payment->order_code ?? $payment->id]);
    }
}
