<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Services\Monetization\MonetizationPaymentService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HmacPaymentWebhookService implements PaymentGatewayInterface
{
    public function __construct(
        private readonly MonetizationPaymentService $monetizationPaymentService,
    ) {}

    public function signPayload(string $payload, int $timestamp): string
    {
        $secret = (string) config('syncviet.payment_webhook_secret', '');

        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    public function verifyWebhookSignature(string $payload, ?string $signature, ?string $timestamp): bool
    {
        $secret = (string) config('syncviet.payment_webhook_secret', '');

        if ($secret === '' || $signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;

        if (abs(time() - $ts) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$ts}.{$payload}", $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(
        string $provider,
        string $rawPayload,
        array $payload,
        ?string $signature,
        ?string $timestamp,
    ): void {
        $payloadHash = hash('sha256', $rawPayload);
        $paymentId = isset($payload['payment_id']) && is_numeric($payload['payment_id']) ? (int) $payload['payment_id'] : 0;
        $providerReference = isset($payload['provider_reference']) && is_string($payload['provider_reference'])
            ? trim($payload['provider_reference'])
            : '';

        if (! $this->verifyWebhookSignature($rawPayload, $signature, $timestamp)) {
            $this->logWebhook($provider, $paymentId > 0 ? $paymentId : null, $providerReference ?: null, false, 401, 'invalid_signature', $payloadHash, 'Invalid HMAC signature or expired timestamp.');

            throw new HttpException(401, __('Invalid payment webhook signature.'));
        }

        if ($paymentId <= 0 || $providerReference === '') {
            $this->logWebhook($provider, null, null, true, 422, 'invalid_payload', $payloadHash, 'Missing payment_id or provider_reference.');

            throw ValidationException::withMessages([
                'payment_id' => [__('Invalid webhook payload.')],
            ]);
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('provider', $provider)
            ->first();

        if ($payment === null) {
            $this->logWebhook($provider, null, $providerReference, true, 404, 'not_found', $payloadHash, 'Matching payment order not found.');

            abort(404);
        }

        if (isset($payload['amount']) && is_numeric($payload['amount']) && (int) $payload['amount'] !== $payment->amount) {
            $this->logWebhook($provider, $payment->id, $providerReference, true, 422, 'amount_mismatch', $payloadHash, 'Webhook amount does not match server order amount.');

            throw new HttpException(422, __('Payment amount mismatch.'));
        }

        $wasAlreadyPaid = $payment->status === PaymentStatus::Paid;

        $this->monetizationPaymentService->settleOrder($payment, $providerReference);

        $this->logWebhook(
            $provider,
            $payment->id,
            $providerReference,
            true,
            200,
            $wasAlreadyPaid ? 'duplicate' : 'settled',
            $payloadHash,
            null,
        );
    }

    private function logWebhook(
        string $provider,
        ?int $paymentId,
        ?string $providerReference,
        bool $signatureValid,
        int $httpStatus,
        string $outcome,
        string $payloadHash,
        ?string $errorMessage,
    ): void {
        if (! Schema::hasTable('payment_webhook_logs')) {
            return;
        }

        PaymentWebhookLog::query()->create([
            'provider' => mb_substr($provider, 0, 32),
            'event_id' => $providerReference !== null ? mb_substr($providerReference, 0, 120) : null,
            'status' => mb_substr($outcome, 0, 24),
            'signature_valid' => $signatureValid,
            'ip_address' => request()->ip(),
            'payload_summary' => [
                'payment_id' => $paymentId,
                'http_status' => $httpStatus,
                'payload_hash' => $payloadHash,
            ],
            'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 255) : null,
        ]);
    }
}
