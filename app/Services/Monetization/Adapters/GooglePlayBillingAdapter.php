<?php

namespace App\Services\Monetization\Adapters;

use App\Models\User;
use App\Services\Monetization\Contracts\NativeBillingAdapterInterface;

class GooglePlayBillingAdapter implements NativeBillingAdapterInterface
{
    public function platform(): string
    {
        return 'android_play';
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
            'error' => __('Google Play Billing SDK integration is not yet configured.'),
        ];
    }
}
