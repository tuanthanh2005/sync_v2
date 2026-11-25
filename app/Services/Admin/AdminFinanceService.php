<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\AdCreditAccount;
use App\Models\AdCreditEntry;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdminFinanceService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(string $tab = 'balances', string $search = ''): array
    {
        $cleanSearch = trim($search);

        $accountsQuery = AdCreditAccount::query()->with(['user:id,name,username,email']);

        if ($cleanSearch !== '') {
            $pattern = '%'.addcslashes($cleanSearch, '\\%_').'%';
            $accountsQuery->whereHas('user', static function (Builder $q) use ($pattern): void {
                $q->where('email', 'like', $pattern)->orWhere('username', 'like', $pattern)->orWhere('name', 'like', $pattern);
            });
        }

        $accounts = $accountsQuery->orderByDesc('balance')->paginate(20)->withQueryString();

        $paymentsQuery = Payment::query()->with(['user:id,name,username,email']);
        if ($cleanSearch !== '') {
            $pattern = '%'.addcslashes($cleanSearch, '\\%_').'%';
            $paymentsQuery->whereHas('user', static fn (Builder $q) => $q->where('email', 'like', $pattern)->orWhere('username', 'like', $pattern));
        }
        $payments = $paymentsQuery->latest('id')->paginate(20)->withQueryString();

        $entries = AdCreditEntry::query()
            ->with(['account.user:id,username', 'actor:id,username'])
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(static fn (AdCreditEntry $e): array => [
                'id' => $e->id,
                'username' => $e->account?->user?->username,
                'actor_username' => $e->actor?->username,
                'type' => $e->type->value,
                'type_label' => $e->type->label(),
                'wallet_bucket' => $e->wallet_bucket ?? 'paid',
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'held_after' => $e->held_after,
                'description' => $e->description,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values()->all();

        $dailyRevenue = DB::table('payments')
            ->where('status', PaymentStatus::Paid->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as day, SUM(amount) as gross, SUM(refunded_amount) as refunded')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'date' => (string) $row->day,
                'gross' => (int) $row->gross,
                'refunded' => (int) $row->refunded,
                'net' => max(0, (int) $row->gross - (int) $row->refunded),
            ])->values()->all();

        return [
            'tab' => $tab,
            'search' => $cleanSearch,
            'summary' => [
                'total_paid_balance' => (int) AdCreditAccount::query()->sum('balance'),
                'total_promo_balance' => (int) AdCreditAccount::query()->sum('promo_balance'),
                'total_held_balance' => (int) AdCreditAccount::query()->sum('held_balance'),
                'total_deposited' => (int) Payment::query()->where('status', PaymentStatus::Paid->value)->sum('amount'),
                'total_refunded' => (int) Payment::query()->sum('refunded_amount'),
                'total_spent' => (int) AdCreditAccount::query()->sum('total_spent'),
            ],
            'accounts' => [
                'data' => array_values(collect($accounts->items())->map(static fn (AdCreditAccount $a): array => [
                    'id' => $a->id,
                    'user_id' => $a->user_id,
                    'name' => $a->user !== null ? $a->user->name : 'User',
                    'username' => $a->user !== null ? $a->user->username : '-',
                    'email' => $a->user !== null ? $a->user->email : '-',
                    'balance' => $a->balance,
                    'promo_balance' => $a->promo_balance,
                    'held_balance' => $a->held_balance,
                    'total_deposited' => $a->total_deposited,
                    'total_spent' => $a->total_spent,
                ])->all()),
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'total' => $accounts->total(),
            ],
            'payments' => [
                'data' => array_values(collect($payments->items())->map(static fn (Payment $p): array => [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'username' => $p->user !== null ? $p->user->username : '-',
                    'email' => $p->user !== null ? $p->user->email : '-',
                    'provider' => $p->provider,
                    'provider_reference' => $p->provider_reference,
                    'amount' => $p->amount,
                    'refunded_amount' => $p->refunded_amount,
                    'refundable_remaining' => max(0, $p->amount - $p->refunded_amount),
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'refund_reason' => $p->refund_reason,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->all()),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
            'entries' => $entries,
            'dailyRevenue' => $dailyRevenue,
        ];
    }

    /**
     * Build RFC-4180 formula-safe CSV of settled payments for accounting reconciliation.
     */
    public function exportPaymentsCsv(): string
    {
        $rows = Payment::query()
            ->with(['user:id,username,email'])
            ->latest('id')
            ->limit(2000)
            ->get();

        $lines = ['ID,Username,Email,Provider,Reference,AmountVND,RefundedVND,Status,PaidAt,CreatedAt'];

        foreach ($rows as $p) {
            $fields = [
                (string) $p->id,
                $this->csvSafe($p->user !== null ? $p->user->username : ''),
                $this->csvSafe($p->user !== null ? $p->user->email : ''),
                $this->csvSafe($p->provider),
                $this->csvSafe((string) ($p->provider_reference ?? '')),
                (string) $p->amount,
                (string) $p->refunded_amount,
                $p->status->value,
                $p->paid_at?->toIso8601String() ?? '',
                $p->created_at?->toIso8601String() ?? '',
            ];
            $lines[] = implode(',', array_map(static fn (string $v): string => '"'.str_replace('"', '""', $v).'"', $fields));
        }

        return implode("\n", $lines)."\n";
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
