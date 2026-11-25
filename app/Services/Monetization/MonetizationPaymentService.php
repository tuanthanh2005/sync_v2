<?php

namespace App\Services\Monetization;

use App\Enums\BannerCampaignStatus;
use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use App\Models\AdCampaign;
use App\Models\BannerCampaign;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonetizationPaymentService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly PlatformCapabilityService $platformCapabilityService,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function maxPendingPaymentsPerUser(): int
    {
        return max(1, min(10, (int) $this->settings->get(
            'monetization_max_pending_payments',
            (int) config('syncviet.monetization.max_pending_payments_per_user', 5),
        )));
    }

    public function exchangeRateVndPerUsd(): int
    {
        return max(1_000, (int) $this->settings->get(
            'monetization_usd_to_vnd_rate',
            (int) config('syncviet.monetization.default_usd_to_vnd_rate', 25_500),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availablePaymentMethods(?string $currency = null, string $platform = 'web'): array
    {
        if (! Schema::hasTable('payment_methods')) {
            return [];
        }

        MonetizationSeeder::seedDefaultsIfEmpty();

        $rule = $this->platformCapabilityService->ruleForPlatform($platform);
        $sandboxAllowed = (bool) config('syncviet.sandbox_topup_enabled', false);

        return array_values(
            PaymentMethod::query()
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->filter(static function (PaymentMethod $method) use ($currency, $rule, $sandboxAllowed): bool {
                    if ($method->code === 'sandbox' && ! $sandboxAllowed) {
                        return false;
                    }

                    if (! in_array($method->code, $rule->allowed_payment_methods, true)) {
                        return false;
                    }

                    if ($currency !== null && ! $method->supportsCurrency($currency)) {
                        return false;
                    }

                    return true;
                })
                ->map(static fn (PaymentMethod $method): array => [
                    'code' => $method->code,
                    'name' => $method->name,
                    'category' => $method->category,
                    'supported_currencies' => $method->supported_currencies,
                    'fee_percent_bps' => $method->fee_percent_bps,
                    'fee_fixed_vnd' => $method->fee_fixed_vnd,
                    'fee_fixed_usd_cents' => $method->fee_fixed_usd_cents,
                    'is_live_ready' => $method->is_live_ready,
                    'instructions' => $method->instructions ?? [],
                ])
                ->all(),
        );
    }

    public function openPendingCount(User $user, ?PaymentServiceType $serviceType = null): int
    {
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->where(static function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($serviceType !== null) {
            $query->where('service_type', $serviceType->value);
        }

        return $query->count();
    }

    /**
     * Create an auditable pending payment order enforcing idempotency, method eligibility,
     * platform compliance, integer fee computation, and strict max-5 open pending order limit.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createOrder(
        User $user,
        PaymentServiceType $serviceType,
        int $amount,
        string $currency,
        string $provider,
        string $idempotencyKey,
        string $platform = 'web',
        ?string $payableType = null,
        ?int $payableId = null,
        array $metadata = [],
    ): Payment {
        if (! $this->settings->featureEnabled('feature_payments')) {
            throw ValidationException::withMessages([
                'payment' => [__('Payments are currently disabled by the administrator.')],
            ]);
        }

        $cleanCurrency = strtoupper(trim($currency));
        if (! in_array($cleanCurrency, ['VND', 'USD'], true)) {
            throw ValidationException::withMessages([
                'currency' => [__('Unsupported payment currency.')],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('Payment amount must be greater than zero.')],
            ]);
        }

        $cleanProvider = strtolower(trim($provider));
        $this->platformCapabilityService->assertPurchaseAllowed($platform, $serviceType->value, $cleanProvider);

        $method = $this->resolveEligibleMethod($cleanProvider, $cleanCurrency);
        $feeAmount = $method->calculateFee($amount, $cleanCurrency);
        $maxPending = $this->maxPendingPaymentsPerUser();
        $expiryHours = max(1, (int) config('syncviet.monetization.payment_order_expiry_hours', 24));
        $exchangeRate = $this->exchangeRateVndPerUsd();

        return DB::transaction(function () use (
            $user,
            $serviceType,
            $amount,
            $feeAmount,
            $cleanCurrency,
            $cleanProvider,
            $idempotencyKey,
            $platform,
            $payableType,
            $payableId,
            $metadata,
            $maxPending,
            $expiryHours,
            $exchangeRate,
            $method,
        ): Payment {
            // Serialize concurrent checkout attempts for the same user to strictly enforce max pending limit.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            /** @var Payment|null $existing */
            $existing = Payment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->user_id !== $user->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => [__('Invalid idempotency key.')],
                    ]);
                }

                return $existing;
            }

            // Expire elapsed pending orders first so stale unpaid checkouts do not permanently block the user.
            Payment::query()
                ->where('user_id', $user->id)
                ->where('status', PaymentStatus::Pending->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->update(['status' => PaymentStatus::Expired->value]);

            $openPendingCount = Payment::query()
                ->where('user_id', $user->id)
                ->where('service_type', $serviceType->value)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                ->count();

            if ($openPendingCount >= $maxPending) {
                throw ValidationException::withMessages([
                    'pending_limit' => [
                        __(
                            'You have reached the maximum of :max pending payment orders. Please complete or cancel an existing pending order before creating a new one.',
                            ['max' => $maxPending],
                        ),
                    ],
                ]);
            }

            $orderCode = 'SV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));

            $payment = new Payment([
                'provider' => $cleanProvider,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => $cleanCurrency,
                'metadata' => array_merge($metadata, [
                    'method_name' => $method->name,
                    'instructions' => $method->instructions ?? [],
                ]),
            ]);
            $payment->order_code = $orderCode;
            $payment->user_id = $user->id;
            $payment->service_type = $serviceType;
            $payment->payable_type = $payableType;
            $payment->payable_id = $payableId;
            $payment->fee_amount = $feeAmount;
            $payment->exchange_rate_snapshot = $exchangeRate;
            $payment->status = PaymentStatus::Pending;
            $payment->platform = $platform;
            $payment->expires_at = now()->addHours($expiryHours);
            $payment->save();

            $this->auditLogger->log(
                'payment.order_created',
                $payment,
                [
                    'order_code' => $payment->order_code,
                    'service_type' => $serviceType->value,
                    'provider' => $cleanProvider,
                    'amount' => $amount,
                    'fee_amount' => $feeAmount,
                    'currency' => $cleanCurrency,
                    'platform' => $platform,
                ],
                $user,
            );

            return $payment;
        });
    }

    public function cancelPendingOrder(User $actor, Payment $payment): Payment
    {
        return DB::transaction(function () use ($actor, $payment): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== $actor->id && ! $actor->hasPermission('finance.manage') && ! $actor->isAdmin()) {
                abort(403);
            }

            if ($locked->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment' => [__('Only pending payment orders can be cancelled.')],
                ]);
            }

            $locked->status = PaymentStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            // Cancel unpaid draft banner or boost records tied to this payment.
            if ($locked->service_type === PaymentServiceType::Banner) {
                BannerCampaign::query()
                    ->where('payment_id', $locked->id)
                    ->where('status', BannerCampaignStatus::PendingPayment->value)
                    ->update([
                        'status' => BannerCampaignStatus::Cancelled->value,
                        'payment_status' => 'cancelled',
                    ]);
            } elseif ($locked->service_type === PaymentServiceType::Boost) {
                AdCampaign::query()
                    ->where('payment_id', $locked->id)
                    ->where('status', 'pending_payment')
                    ->update(['status' => 'cancelled']);
            }

            $this->auditLogger->log(
                'payment.order_cancelled',
                $locked,
                ['order_code' => $locked->order_code, 'service_type' => $locked->service_type->value],
                $actor,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Atomically settle a verified payment order (via signed webhook or authorized Admin reconciliation)
     * and trigger domain fulfillment strictly once per payment.
     */
    public function settleOrder(
        Payment $payment,
        string $providerReference,
        ?User $actor = null,
        ?string $note = null,
    ): Payment {
        if ($payment->service_type === PaymentServiceType::TopUp) {
            return $this->walletService->settlePaymentCredit($payment, $providerReference, $note);
        }

        $settled = DB::transaction(function () use ($payment, $providerReference, $actor, $note): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Paid) {
                return $locked;
            }

            if (in_array($locked->status, [PaymentStatus::Cancelled, PaymentStatus::Expired, PaymentStatus::Refunded], true)) {
                throw ValidationException::withMessages([
                    'payment' => [__('Closed or cancelled payment orders cannot be settled.')],
                ]);
            }

            $duplicateRef = Payment::query()
                ->where('provider_reference', $providerReference)
                ->where('id', '!=', $locked->id)
                ->exists();

            if ($duplicateRef) {
                throw ValidationException::withMessages([
                    'provider_reference' => [__('Duplicate payment provider reference.')],
                ]);
            }

            $locked->status = PaymentStatus::Paid;
            $locked->provider_reference = $providerReference;
            $locked->paid_at = now();
            if ($note !== null && trim($note) !== '') {
                $meta = $locked->metadata ?? [];
                $meta['settlement_note'] = trim($note);
                $locked->metadata = $meta;
            }
            $locked->save();

            if ($locked->service_type === PaymentServiceType::Premium) {
                app(PremiumSubscriptionService::class)->activateFromPayment($locked, $actor);
            } elseif ($locked->service_type === PaymentServiceType::Banner) {
                app(BannerAdvertisingService::class)->markPaymentSettled($locked);
            } elseif ($locked->service_type === PaymentServiceType::Boost) {
                app(BoostPostService::class)->markPaymentSettled($locked);
            }

            $this->auditLogger->log(
                'payment.order_settled',
                $locked,
                [
                    'order_code' => $locked->order_code,
                    'service_type' => $locked->service_type->value,
                    'provider' => $locked->provider,
                    'provider_reference' => $providerReference,
                    'amount' => $locked->amount,
                    'currency' => $locked->currency,
                ],
                $actor ?? $locked->user,
            );

            return $locked->fresh() ?? $locked;
        });

        return $settled;
    }

    /**
     * Refund a direct service payment (Premium / Banner / Boost) or delegate Advertising Balance top-up refund to WalletService.
     */
    public function adminRefundOrder(
        User $actor,
        Payment $payment,
        int $amount,
        string $reason,
        string $idempotencyKey,
    ): Payment {
        if ($payment->service_type === PaymentServiceType::TopUp) {
            return $this->walletService->adminRefundPayment($actor, $payment, $amount, $reason, $idempotencyKey);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('Refund amount must be greater than zero.')],
            ]);
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('A refund reason is required.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $payment, $amount, $cleanReason): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'payment' => [__('Only paid transactions can be refunded.')],
                ]);
            }

            $remaining = $locked->amount - $locked->refunded_amount;
            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => [__('Refund amount exceeds the remaining refundable amount.')],
                ]);
            }

            $locked->refunded_amount += $amount;
            $locked->refunded_at = now();
            $locked->refund_reason = $cleanReason;
            $locked->refunded_by = $actor->id;

            if ($locked->refunded_amount >= $locked->amount) {
                $locked->status = PaymentStatus::Refunded;
            }

            $locked->save();

            $this->auditLogger->log(
                'monetization.payment_refunded',
                $locked,
                [
                    'service_type' => $locked->service_type->value,
                    'currency' => $locked->currency,
                    'refund_amount' => $amount,
                    'total_refunded' => $locked->refunded_amount,
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function expireStalePendingOrders(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentStatus::Expired->value]);
    }

    private function resolveEligibleMethod(string $code, string $currency): PaymentMethod
    {
        if (Schema::hasTable('payment_methods')) {
            MonetizationSeeder::seedDefaultsIfEmpty();

            /** @var PaymentMethod|null $method */
            $method = PaymentMethod::query()->where('code', $code)->first();

            if ($method === null) {
                throw ValidationException::withMessages([
                    'provider' => [__('Selected payment method does not exist.')],
                ]);
            }

            if ($method->code === 'sandbox' && ! (bool) config('syncviet.sandbox_topup_enabled', false)) {
                throw ValidationException::withMessages([
                    'provider' => [__('Sandbox payment mode is disabled in production.')],
                ]);
            }

            if (! $method->is_enabled) {
                throw ValidationException::withMessages([
                    'provider' => [__('Selected payment method is currently disabled.')],
                ]);
            }

            if (! $method->supportsCurrency($currency)) {
                throw ValidationException::withMessages([
                    'currency' => [__('Selected payment method does not support :currency.', ['currency' => $currency])],
                ]);
            }

            return $method;
        }

        return new PaymentMethod([
            'code' => $code,
            'name' => strtoupper($code),
            'category' => 'fiat',
            'supported_currencies' => ['VND', 'USD'],
            'fee_percent_bps' => 0,
            'fee_fixed_vnd' => 0,
            'fee_fixed_usd_cents' => 0,
            'is_enabled' => true,
        ]);
    }
}
