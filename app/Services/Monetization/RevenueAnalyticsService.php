<?php

namespace App\Services\Monetization;

use App\Enums\BannerCampaignStatus;
use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdCampaign;
use App\Models\AdCreditAccount;
use App\Models\BannerCampaign;
use App\Models\BannerPlacement;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RevenueAnalyticsService
{
    /**
     * Produce dual-currency (VND & USD separated) financial analytics strictly separating
     * Prepaid Advertising Balance Deposits from Recognized Direct Service Revenue.
     *
     * @return array<string, mixed>
     */
    public function dashboardPayload(string $range = 'month', ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->resolveDateRange($range, $from, $to);

        $byCurrencyAndService = DB::table('payments')
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('currency, service_type, SUM(amount) as gross, SUM(fee_amount) as fees, SUM(refunded_amount) as refunded, COUNT(*) as orders')
            ->groupBy('currency', 'service_type')
            ->get();

        $summary = [
            'VND' => $this->emptyCurrencyBucket(),
            'USD' => $this->emptyCurrencyBucket(),
        ];

        foreach ($byCurrencyAndService as $row) {
            $curr = strtoupper((string) $row->currency);
            if (! isset($summary[$curr])) {
                continue;
            }
            $service = (string) $row->service_type;
            $gross = (int) $row->gross;
            $fees = (int) $row->fees;
            $refunded = (int) $row->refunded;
            $orders = (int) $row->orders;

            if ($service === PaymentServiceType::TopUp->value) {
                $summary[$curr]['prepaid_deposits_gross'] += $gross;
                $summary[$curr]['prepaid_deposits_refunded'] += $refunded;
                $summary[$curr]['prepaid_orders'] += $orders;
            } else {
                $summary[$curr]['recognized_service_gross'] += $gross;
                $summary[$curr]['recognized_service_refunded'] += $refunded;
                $summary[$curr]['service_orders'] += $orders;

                if (isset($summary[$curr]['by_service'][$service])) {
                    $summary[$curr]['by_service'][$service] += $gross;
                }
            }

            $summary[$curr]['total_fees'] += $fees;
            $summary[$curr]['total_refunded'] += $refunded;
            $summary[$curr]['net_cash_collected'] += max(0, $gross - $refunded - $fees);
        }

        // Daily chart series separated by currency
        $dailyRows = DB::table('payments')
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('DATE(paid_at) as day, currency, service_type, SUM(amount) as gross')
            ->groupBy('day', 'currency', 'service_type')
            ->orderBy('day')
            ->get();

        $chartByDay = [];
        foreach ($dailyRows as $r) {
            $day = (string) $r->day;
            $chartByDay[$day] ??= [
                'date' => $day,
                'vnd_service' => 0,
                'vnd_topup' => 0,
                'usd_cents_service' => 0,
                'usd_cents_topup' => 0,
            ];

            $isTopUp = (string) $r->service_type === PaymentServiceType::TopUp->value;
            if (strtoupper((string) $r->currency) === 'USD') {
                $chartByDay[$day][$isTopUp ? 'usd_cents_topup' : 'usd_cents_service'] += (int) $r->gross;
            } else {
                $chartByDay[$day][$isTopUp ? 'vnd_topup' : 'vnd_service'] += (int) $r->gross;
            }
        }

        $now = now();
        $activePremiumCount = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->count();
        $expiring7dPremium = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(7)])
            ->count();

        $totalPlacements = BannerPlacement::query()->where('is_active', true)->count();
        $occupiedPlacements = BannerCampaign::query()
            ->where('status', BannerCampaignStatus::Active->value)
            ->whereNull('emergency_paused_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->distinct('banner_placement_id')
            ->count('banner_placement_id');

        $pendingOrdersCount = Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->count();

        return [
            'filters' => [
                'range' => $range,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'currencies' => $summary,
            'dailySeries' => array_values($chartByDay),
            'operations' => [
                'active_premium_members' => $activePremiumCount,
                'expiring_7d_premium' => $expiring7dPremium,
                'total_banner_placements' => $totalPlacements,
                'occupied_banner_placements' => $occupiedPlacements,
                'vacant_banner_placements' => max(0, $totalPlacements - $occupiedPlacements),
                'pending_banner_reviews' => BannerCampaign::query()->where('status', BannerCampaignStatus::PendingReview->value)->count(),
                'active_boost_campaigns' => AdCampaign::query()->where('status', 'active')->count(),
                'pending_orders_count' => $pendingOrdersCount,
                'ad_balance_liability_vnd' => (int) AdCreditAccount::query()->sum('balance') + (int) AdCreditAccount::query()->sum('held_balance'),
                'ad_balance_promo_vnd' => (int) AdCreditAccount::query()->sum('promo_balance'),
                'ad_balance_recognized_spend_vnd' => (int) AdCreditAccount::query()->sum('total_spent'),
            ],
        ];
    }

    /**
     * Formula-safe RFC-4180 CSV export separating currency, service type, fees, and refunds.
     */
    public function exportMonetizationCsv(string $range = 'month', ?string $from = null, ?string $to = null): string
    {
        [$start, $end] = $this->resolveDateRange($range, $from, $to);

        $rows = Payment::query()
            ->with(['user:id,username,email'])
            ->whereBetween('created_at', [$start, $end])
            ->latest('id')
            ->limit(5000)
            ->get();

        $lines = ['OrderCode,ServiceType,Username,Email,Provider,Reference,Currency,AmountMinorUnits,FeeMinorUnits,RefundedMinorUnits,ExchangeRateSnapshot,Platform,Status,PaidAt,CreatedAt'];

        foreach ($rows as $p) {
            $fields = [
                $this->csvSafe((string) ($p->order_code ?? ('PAY-'.$p->id))),
                $p->service_type->value,
                $this->csvSafe($p->user !== null ? $p->user->username : ''),
                $this->csvSafe($p->user !== null ? $p->user->email : ''),
                $this->csvSafe($p->provider),
                $this->csvSafe((string) ($p->provider_reference ?? '')),
                $p->currency,
                (string) $p->amount,
                (string) $p->fee_amount,
                (string) $p->refunded_amount,
                (string) ($p->exchange_rate_snapshot ?? ''),
                $p->platform,
                $p->status->value,
                $p->paid_at?->toIso8601String() ?? '',
                $p->created_at?->toIso8601String() ?? '',
            ];
            $lines[] = implode(',', array_map(static fn (string $v): string => '"'.str_replace('"', '""', $v).'"', $fields));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDateRange(string $range, ?string $from, ?string $to): array
    {
        $now = CarbonImmutable::now();

        if ($range === 'custom' && $from !== null && $to !== null) {
            try {
                $start = CarbonImmutable::parse($from)->startOfDay();
                $end = CarbonImmutable::parse($to)->endOfDay();
                if ($start->lte($end)) {
                    return [$start, $end];
                }
            } catch (\Throwable) {
                // Fall back to default range.
            }
        }

        return match ($range) {
            'day' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'year' => [$now->subDays(364)->startOfDay(), $now->endOfDay()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCurrencyBucket(): array
    {
        return [
            'recognized_service_gross' => 0,
            'recognized_service_refunded' => 0,
            'prepaid_deposits_gross' => 0,
            'prepaid_deposits_refunded' => 0,
            'total_fees' => 0,
            'total_refunded' => 0,
            'net_cash_collected' => 0,
            'service_orders' => 0,
            'prepaid_orders' => 0,
            'by_service' => [
                PaymentServiceType::Premium->value => 0,
                PaymentServiceType::Banner->value => 0,
                PaymentServiceType::Boost->value => 0,
            ],
        ];
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
