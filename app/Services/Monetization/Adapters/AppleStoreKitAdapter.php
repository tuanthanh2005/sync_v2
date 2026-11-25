<?php

namespace App\Services\Monetization\Adapters;

use App\Models\User;
use App\Services\Monetization\Contracts\NativeBillingAdapterInterface;

class AppleStoreKitAdapter implements NativeBillingAdapterInterface
{
    public function platform(): string
    {
        return 'ios_appstore';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function verifyPurchaseReceipt(User $user, array $payload): array
    {
        return [
            'valid' => false,
            'external_reference' => null,
            'error' => __('Apple StoreKit In-App Purchase integration is not yet configured.'),
        ];
    }
}
