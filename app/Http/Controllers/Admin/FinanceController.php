<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminFinanceService;
use App\Services\Billing\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(
        private readonly AdminFinanceService $financeService,
        private readonly WalletService $walletService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render(
            'admin/Finance/Index',
            $this->financeService->payload(
                tab: (string) $request->input('tab', 'balances'),
                search: (string) $request->input('search', ''),
            ),
        );
    }

    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $validated['current_password'], (string) $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $this->walletService->adminRefundPayment(
            actor: $actor,
            payment: $payment,
            amountVnd: (int) $validated['amount'],
            reason: (string) $validated['reason'],
            idempotencyKey: 'admin-refund:'.$payment->id.':'.Str::uuid()->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment refund recorded.')]);

        return back();
    }

    public function adjustWallet(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-50000000', 'max:50000000'],
            'bucket' => ['required', 'string', 'in:paid,promo'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $validated['current_password'], (string) $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $this->walletService->adminAdjustLedger(
            actor: $actor,
            target: $user,
            signedAmountVnd: (int) $validated['amount'],
            bucket: (string) $validated['bucket'],
            reason: (string) $validated['reason'],
            idempotencyKey: 'admin-adjust:'.$user->id.':'.Str::uuid()->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ledger adjustment recorded.')]);

        return back();
    }

    public function exportCsv(): HttpResponse
    {
        $csv = $this->financeService->exportPaymentsCsv();

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sysnoxis-payments-'.now()->format('Ymd').'.csv"',
        ]);
    }
}
