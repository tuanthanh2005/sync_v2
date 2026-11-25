<?php

namespace App\Services\Monetization\Contracts;

use App\Models\User;

interface NativeBillingAdapterInterface
{
    public function platform(): string;

    public function isConfigured(): bool;

    /**
     * Verify a native store receipt/purchase token on the backend.
     *
     * @param  array<string, mixed>  $payload
     * @return array{valid: bool, external_reference: string|null, error: string|null}
     */
    public function verifyPurchaseReceipt(User $user, array $payload): array;
}
