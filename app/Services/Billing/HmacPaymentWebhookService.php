<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HmacPaymentWebhookService implements PaymentGatewayInterface
{
    public function __construct(
        private readonly WalletService $walletService,
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
        if (! $this->verifyWebhookSignature($rawPayload, $signature, $timestamp)) {
            throw new HttpException(401, __('Invalid payment webhook signature.'));
        }

        $paymentId = isset($payload['payment_id']) && is_numeric($payload['payment_id']) ? (int) $payload['payment_id'] : 0;
        $providerReference = isset($payload['provider_reference']) && is_string($payload['provider_reference'])
            ? trim($payload['provider_reference'])
            : '';

        if ($paymentId <= 0 || $providerReference === '') {
            throw ValidationException::withMessages([
                'payment_id' => [__('Invalid webhook payload.')],
            ]);
        }

        /** @var Payment $payment */
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('provider', $provider)
            ->firstOrFail();

        if (isset($payload['amount']) && is_numeric($payload['amount']) && (int) $payload['amount'] !== $payment->amount) {
            throw new HttpException(422, __('Payment amount mismatch.'));
        }

        $this->walletService->settlePaymentCredit($payment, $providerReference);
    }
}
