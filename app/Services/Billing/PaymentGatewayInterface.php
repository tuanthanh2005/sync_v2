<?php

namespace App\Services\Billing;

interface PaymentGatewayInterface
{
    public function verifyWebhookSignature(string $payload, ?string $signature, ?string $timestamp): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $provider, string $rawPayload, array $payload, ?string $signature, ?string $timestamp): void;
}
