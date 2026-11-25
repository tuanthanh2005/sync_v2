<?php

namespace App\Http\Controllers\Billing;

use App\Enums\PaymentServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\TopUpRequest;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Billing\WalletService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Monetization\PlatformCapabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly HmacPaymentWebhookService $webhookService,
        private readonly MonetizationPaymentService $monetizationPaymentService,
        private readonly PlatformCapabilityService $platformService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $platform = $this->platformService->resolvePlatform($request);

        return Inertia::render('Wallet', [
            ...$this->walletService->walletPayload($user),
            'paymentMethods' => $this->monetizationPaymentService->availablePaymentMethods('VND', $platform),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
        ])->withViewData([
            'seo' => [
                'title' => __('Advertising Balance').' - '.config('app.name'),
                'noindex' => true,
            ],
        ]);
    }

    public function storeTopUp(TopUpRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $amount = (int) $request->integer('amount');
        $provider = $request->string('provider', 'sandbox')->toString();
        $idempotencyKey = $request->string('idempotency_key')->toString();
        $deferSettlement = $request->boolean('defer_settlement', false);

        if ($provider === 'sandbox') {
            abort_unless((bool) config('syncviet.sandbox_topup_enabled', false), 404);

            $payment = $this->walletService->createTopUpCheckout($user, $amount, $provider, $idempotencyKey);

            if ($deferSettlement) {
                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => __('Pending top-up order :code created.', ['code' => $payment->order_code ?? $payment->id]),
                ]);

                return to_route('payments.index', ['highlight' => $payment->order_code ?? $payment->id]);
            }

            // In non-production sandbox mode, simulate a cryptographically signed provider callback.
            $payloadArray = [
                'payment_id' => $payment->id,
                'provider_reference' => $payment->provider_reference ?? 'SBX-'.Str::upper(Str::random(12)),
                'amount' => $payment->amount,
            ];
            $rawPayload = json_encode($payloadArray, JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = $this->webhookService->signPayload($rawPayload, $timestamp);

            $this->webhookService->handleWebhook(
                $provider,
                $rawPayload,
                $payloadArray,
                $signature,
                (string) $timestamp,
            );

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Advertising balance credited successfully.'),
            ]);

            return back();
        }

        // Standard payment methods (VietQR, Bank Transfer, PayPal) create a verified pending order first.
        $platform = $this->platformService->resolvePlatform($request);
        $payment = $this->monetizationPaymentService->createOrder(
            user: $user,
            serviceType: PaymentServiceType::TopUp,
            amount: $amount,
            currency: 'VND',
            provider: $provider,
            idempotencyKey: $idempotencyKey,
            platform: $platform,
            metadata: ['channel' => $provider],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Top-up order :code created. Complete transfer to credit your Advertising Balance.', ['code' => $payment->order_code ?? $payment->id]),
        ]);

        return to_route('payments.index', ['highlight' => $payment->order_code ?? $payment->id]);
    }
}
