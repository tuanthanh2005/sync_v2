<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayInterface $paymentGateway,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $rawPayload = $request->getContent();
        $decoded = json_decode($rawPayload, true);

        if (! is_array($decoded)) {
            return response()->json([
                'error_code' => 'MALFORMED_JSON_PAYLOAD',
                'message' => __('Malformed JSON payload.'),
            ], 400);
        }

        $signature = $request->header('X-SyncViet-Signature');
        $timestamp = $request->header('X-SyncViet-Timestamp');

        /** @var array<string, mixed> $payload */
        $payload = $decoded;

        $this->paymentGateway->handleWebhook($provider, $rawPayload, $payload, $signature, $timestamp);

        return response()->json(['status' => 'ok']);
    }
}
