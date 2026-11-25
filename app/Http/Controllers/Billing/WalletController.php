<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\TopUpRequest;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Billing\WalletService;
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
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Wallet', $this->walletService->walletPayload($user));
    }

    public function storeTopUp(TopUpRequest $request): RedirectResponse
    {
        abort_unless((bool) config('syncviet.sandbox_topup_enabled', false), 404);

        $user = $request->user();
        abort_if($user === null, 403);

        $amount = (int) $request->integer('amount');
        $provider = $request->string('provider', 'sandbox')->toString();
        $idempotencyKey = $request->string('idempotency_key')->toString();

        $payment = $this->walletService->createTopUpCheckout($user, $amount, $provider, $idempotencyKey);

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
            'message' => __('Wallet credited successfully.'),
        ]);

        return back();
    }
}
