<?php

namespace App\Services\Billing;

use App\Enums\AdCampaignStatus;
use App\Enums\CreditEntryType;
use App\Enums\PaymentStatus;
use App\Models\AdCampaign;
use App\Models\AdCreditAccount;
use App\Models\AdCreditEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SystemSettingService $settings,
    ) {}

    public function getOrCreateAccount(User $user): AdCreditAccount
    {
        /** @var AdCreditAccount|null $existing */
        $existing = AdCreditAccount::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = new AdCreditAccount(['currency' => 'VND']);
        $account->user_id = $user->id;
        $account->balance = 0;
        $account->promo_balance = 0;
        $account->held_balance = 0;
        $account->total_deposited = 0;
        $account->total_spent = 0;
        $account->save();

        return $account;
    }

    public function createTopUpCheckout(User $user, int $amountVnd, string $provider, string $idempotencyKey): Payment
    {
        if (! $this->settings->featureEnabled('feature_payments')) {
            throw ValidationException::withMessages([
                'amount' => [__('Wallet top-ups and payments are currently disabled.')],
            ]);
        }

        $minAmount = (int) $this->settings->get('ad_min_daily_budget_vnd', (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000));
        $maxAmount = (int) $this->settings->get('ad_max_total_budget_vnd', (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000));

        if ($amountVnd < $minAmount || $amountVnd > $maxAmount) {
            throw ValidationException::withMessages([
                'amount' => [__('Top-up amount is outside the allowed VND range.')],
            ]);
        }

        return DB::transaction(function () use ($user, $amountVnd, $provider, $idempotencyKey): Payment {
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

            $payment = new Payment([
                'provider' => $provider,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amountVnd,
                'currency' => 'VND',
                'metadata' => ['channel' => $provider],
            ]);
            $payment->user_id = $user->id;
            $payment->status = PaymentStatus::Pending;
            $payment->save();

            return $payment;
        });
    }

    public function settlePaymentCredit(
        Payment $payment,
        string $providerReference,
        ?string $description = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $providerReference, $description): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status === PaymentStatus::Paid) {
                return $lockedPayment;
            }

            $duplicateRef = Payment::query()
                ->where('provider_reference', $providerReference)
                ->where('id', '!=', $lockedPayment->id)
                ->exists();

            if ($duplicateRef) {
                throw ValidationException::withMessages([
                    'provider_reference' => [__('Duplicate payment provider reference.')],
                ]);
            }

            /** @var User $owner */
            $owner = User::query()->findOrFail($lockedPayment->user_id);
            $this->getOrCreateAccount($owner);

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $lockedPayment->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $entryKey = "deposit:{$lockedPayment->id}";

            if (AdCreditEntry::query()->where('idempotency_key', $entryKey)->exists()) {
                $lockedPayment->status = PaymentStatus::Paid;
                $lockedPayment->provider_reference ??= $providerReference;
                $lockedPayment->paid_at ??= now();
                $lockedPayment->save();

                return $lockedPayment;
            }

            $lockedAccount->balance += $lockedPayment->amount;
            $lockedAccount->total_deposited += $lockedPayment->amount;
            $lockedAccount->save();

            $lockedPayment->status = PaymentStatus::Paid;
            $lockedPayment->provider_reference = $providerReference;
            $lockedPayment->paid_at = now();
            $lockedPayment->save();

            $this->appendEntry(
                account: $lockedAccount,
                type: CreditEntryType::Deposit,
                amount: $lockedPayment->amount,
                idempotencyKey: $entryKey,
                description: $description ?? __('Ad credit deposit via :provider', ['provider' => Str::upper($lockedPayment->provider)]),
                paymentId: $lockedPayment->id,
            );

            $this->auditLogger->log(
                'wallet.deposit_settled',
                $lockedPayment,
                ['amount_vnd' => $lockedPayment->amount, 'provider' => $lockedPayment->provider],
                $owner,
            );

            return $lockedPayment;
        });
    }

    public function holdForCampaign(User $user, AdCampaign $campaign, int $amountVnd): void
    {
        if ($amountVnd <= 0) {
            throw ValidationException::withMessages([
                'budget' => [__('Campaign budget must be greater than zero.')],
            ]);
        }

        DB::transaction(function () use ($user, $campaign, $amountVnd): void {
            $this->getOrCreateAccount($user);

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAccount->balance < $amountVnd) {
                throw ValidationException::withMessages([
                    'budget' => [__('Insufficient ad wallet balance. Please top up your wallet first.')],
                ]);
            }

            $entryKey = "hold:campaign:{$campaign->id}";

            if (AdCreditEntry::query()->where('idempotency_key', $entryKey)->exists()) {
                return;
            }

            $lockedAccount->balance -= $amountVnd;
            $lockedAccount->held_balance += $amountVnd;
            $lockedAccount->save();

            $campaign->held_amount = $amountVnd;
            $campaign->save();

            $this->appendEntry(
                account: $lockedAccount,
                type: CreditEntryType::Hold,
                amount: $amountVnd,
                idempotencyKey: $entryKey,
                description: __('Reserved budget for campaign :name', ['name' => $campaign->name]),
                adCampaignId: $campaign->id,
            );
        });
    }

    public function releaseCampaignHold(AdCampaign $campaign, CreditEntryType $entryType = CreditEntryType::Refund, ?string $reason = null): void
    {
        DB::transaction(function () use ($campaign, $entryType, $reason): void {
            /** @var AdCampaign $lockedCampaign */
            $lockedCampaign = AdCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($lockedCampaign->held_amount <= 0) {
                return;
            }

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $lockedCampaign->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $refundAmount = min($lockedCampaign->held_amount, $lockedAccount->held_balance);

            if ($refundAmount <= 0) {
                $lockedCampaign->held_amount = 0;
                $lockedCampaign->save();

                return;
            }

            $entryKey = "{$entryType->value}:campaign:{$lockedCampaign->id}";

            if (AdCreditEntry::query()->where('idempotency_key', $entryKey)->exists()) {
                return;
            }

            $lockedAccount->held_balance -= $refundAmount;
            $lockedAccount->balance += $refundAmount;
            $lockedAccount->save();

            $lockedCampaign->held_amount = 0;
            $lockedCampaign->save();

            $this->appendEntry(
                account: $lockedAccount,
                type: $entryType,
                amount: $refundAmount,
                idempotencyKey: $entryKey,
                description: $reason ?? __('Released unspent budget for campaign :name', ['name' => $lockedCampaign->name]),
                adCampaignId: $lockedCampaign->id,
            );
        });
    }

    public function chargeCampaignSpend(AdCampaign $campaign, int $costVnd, string $eventKey): int
    {
        if ($costVnd <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($campaign, $costVnd, $eventKey): int {
            /** @var AdCampaign $lockedCampaign */
            $lockedCampaign = AdCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($lockedCampaign->status !== AdCampaignStatus::Active || $lockedCampaign->held_amount <= 0) {
                return 0;
            }

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $lockedCampaign->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $chargeable = min($costVnd, $lockedCampaign->held_amount, $lockedAccount->held_balance);

            if ($chargeable <= 0) {
                return 0;
            }

            $entryKey = "spend:{$lockedCampaign->id}:{$eventKey}";

            if (AdCreditEntry::query()->where('idempotency_key', $entryKey)->exists()) {
                return 0;
            }

            $lockedAccount->held_balance -= $chargeable;
            $lockedAccount->total_spent += $chargeable;
            $lockedAccount->save();

            $lockedCampaign->held_amount -= $chargeable;
            $lockedCampaign->spent_amount += $chargeable;

            $minEventCost = (int) config('syncviet.ad_pricing.impression_cost_vnd', 25);

            if ($lockedCampaign->held_amount < $minEventCost) {
                $dust = $lockedCampaign->held_amount;

                if ($dust > 0 && $lockedAccount->held_balance >= $dust) {
                    $lockedAccount->held_balance -= $dust;
                    $lockedAccount->balance += $dust;
                    $lockedAccount->save();

                    $lockedCampaign->held_amount = 0;

                    $this->appendEntry(
                        account: $lockedAccount,
                        type: CreditEntryType::Release,
                        amount: $dust,
                        idempotencyKey: "release:dust:{$lockedCampaign->id}",
                        description: __('Released remaining budget dust for campaign :name', ['name' => $lockedCampaign->name]),
                        adCampaignId: $lockedCampaign->id,
                    );
                }

                $lockedCampaign->status = AdCampaignStatus::Completed;
            }

            $lockedCampaign->save();

            $this->appendEntry(
                account: $lockedAccount,
                type: CreditEntryType::Spend,
                amount: $chargeable,
                idempotencyKey: $entryKey,
                description: __('Ad delivery spend for campaign :name', ['name' => $lockedCampaign->name]),
                adCampaignId: $lockedCampaign->id,
            );

            return $chargeable;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function walletPayload(User $user): array
    {
        $account = $this->getOrCreateAccount($user);

        $entries = $account->entries()
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (AdCreditEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'type_label' => $entry->type->label(),
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'held_after' => $entry->held_after,
                'description' => $entry->description,
                'ad_campaign_id' => $entry->ad_campaign_id,
                'created_at' => $entry->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $payments = Payment::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (Payment $payment): array => [
                'id' => $payment->id,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'account' => [
                'currency' => $account->currency,
                'balance' => $account->balance,
                'held_balance' => $account->held_balance,
                'total_deposited' => $account->total_deposited,
                'total_spent' => $account->total_spent,
            ],
            'entries' => $entries,
            'payments' => $payments,
            'sandboxEnabled' => (bool) config('syncviet.sandbox_topup_enabled', false),
            'minAmount' => (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000),
            'maxAmount' => (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000),
        ];
    }

    /**
     * Atomically adjust a user's paid or promotional credit bucket with full ledger + audit trail.
     * Enforces non-negative balance invariants and row locking (no direct DB balance overrides).
     */
    public function adminAdjustLedger(
        User $actor,
        User $target,
        int $signedAmountVnd,
        string $bucket,
        string $reason,
        string $idempotencyKey,
    ): AdCreditEntry {
        if ($signedAmountVnd === 0) {
            throw ValidationException::withMessages([
                'amount' => [__('Adjustment amount must be non-zero.')],
            ]);
        }

        $cleanBucket = $bucket === 'promo' ? 'promo' : 'paid';
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('An adjustment reason is required.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $signedAmountVnd, $cleanBucket, $cleanReason, $idempotencyKey): AdCreditEntry {
            $existing = AdCreditEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->getOrCreateAccount($target);

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($cleanBucket === 'promo') {
                $nextPromo = $lockedAccount->promo_balance + $signedAmountVnd;
                if ($nextPromo < 0) {
                    throw ValidationException::withMessages([
                        'amount' => [__('Promotional balance cannot become negative.')],
                    ]);
                }
                $lockedAccount->promo_balance = $nextPromo;
                $entryType = $signedAmountVnd > 0 ? CreditEntryType::PromoGrant : CreditEntryType::AdminAdjustment;
            } else {
                $nextBalance = $lockedAccount->balance + $signedAmountVnd;
                if ($nextBalance < 0) {
                    throw ValidationException::withMessages([
                        'amount' => [__('Account balance cannot become negative.')],
                    ]);
                }
                $lockedAccount->balance = $nextBalance;
                $entryType = CreditEntryType::AdminAdjustment;
            }

            $lockedAccount->save();

            $entry = $this->appendEntry(
                account: $lockedAccount,
                type: $entryType,
                amount: abs($signedAmountVnd),
                idempotencyKey: $idempotencyKey,
                description: ($signedAmountVnd > 0 ? '[+]' : '[-]')." {$cleanReason}",
                actorId: $actor->id,
                walletBucket: $cleanBucket,
            );

            $this->auditLogger->log(
                'finance.ledger_adjusted',
                $lockedAccount,
                [
                    'target_user_id' => $target->id,
                    'bucket' => $cleanBucket,
                    'signed_amount_vnd' => $signedAmountVnd,
                    'balance_after' => $lockedAccount->balance,
                    'promo_balance_after' => $lockedAccount->promo_balance,
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            return $entry;
        });
    }

    /**
     * Atomically record a full or partial refund against a settled payment.
     */
    public function adminRefundPayment(
        User $actor,
        Payment $payment,
        int $amountVnd,
        string $reason,
        string $idempotencyKey,
    ): Payment {
        if ($amountVnd <= 0) {
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

        return DB::transaction(function () use ($actor, $payment, $amountVnd, $cleanReason, $idempotencyKey): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'payment' => [__('Only settled paid transactions can be refunded.')],
                ]);
            }

            $remaining = $lockedPayment->amount - $lockedPayment->refunded_amount;

            if ($amountVnd > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => [__('Refund amount exceeds the remaining refundable payment amount.')],
                ]);
            }

            if (AdCreditEntry::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $lockedPayment;
            }

            /** @var User $owner */
            $owner = User::query()->findOrFail($lockedPayment->user_id);
            $this->getOrCreateAccount($owner);

            /** @var AdCreditAccount $lockedAccount */
            $lockedAccount = AdCreditAccount::query()
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAccount->balance < $amountVnd) {
                throw ValidationException::withMessages([
                    'amount' => [__('User available paid balance is lower than the requested refund amount.')],
                ]);
            }

            $lockedAccount->balance -= $amountVnd;
            $lockedAccount->save();

            $lockedPayment->refunded_amount += $amountVnd;
            $lockedPayment->refunded_at = now();
            $lockedPayment->refund_reason = $cleanReason;
            $lockedPayment->refunded_by = $actor->id;
            $lockedPayment->save();

            $this->appendEntry(
                account: $lockedAccount,
                type: CreditEntryType::AdminRefund,
                amount: $amountVnd,
                idempotencyKey: $idempotencyKey,
                description: __('Payment #:id refund: :reason', ['id' => $lockedPayment->id, 'reason' => $cleanReason]),
                paymentId: $lockedPayment->id,
                actorId: $actor->id,
                walletBucket: 'paid',
            );

            $this->auditLogger->log(
                'finance.payment_refunded',
                $lockedPayment,
                [
                    'target_user_id' => $owner->id,
                    'refund_amount_vnd' => $amountVnd,
                    'total_refunded_vnd' => $lockedPayment->refunded_amount,
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            return $lockedPayment;
        });
    }

    private function appendEntry(
        AdCreditAccount $account,
        CreditEntryType $type,
        int $amount,
        string $idempotencyKey,
        string $description,
        ?int $paymentId = null,
        ?int $adCampaignId = null,
        ?int $actorId = null,
        string $walletBucket = 'paid',
    ): AdCreditEntry {
        $entry = new AdCreditEntry([
            'type' => $type->value,
            'wallet_bucket' => $walletBucket,
            'amount' => $amount,
            'balance_after' => $account->balance,
            'held_after' => $account->held_balance,
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
        ]);
        $entry->account_id = $account->id;
        $entry->payment_id = $paymentId;
        $entry->ad_campaign_id = $adCampaignId;
        $entry->actor_id = $actorId;
        $entry->created_at = now();
        $entry->save();

        return $entry;
    }
}
