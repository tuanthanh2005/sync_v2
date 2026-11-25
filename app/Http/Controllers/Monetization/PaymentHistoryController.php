<?php

namespace App\Http\Controllers\Monetization;

use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\HmacPaymentWebhookService;
use App\Services\Monetization\MonetizationPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PaymentHistoryController extends Controller
{
    public function __construct(
        private readonly MonetizationPaymentService $paymentService,
        private readonly HmacPaymentWebhookService $webhookService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $serviceType = $request->string('service_type', 'all')->toString();
        $status = $request->string('status', 'all')->toString();
        $search = trim($request->string('search')->toString());
        $highlight = trim($request->string('highlight')->toString());

        $query = Payment::query()->where('user_id', $user->id);

        if ($serviceType !== 'all' && PaymentServiceType::tryFrom($serviceType) !== null) {
            $query->where('service_type', $serviceType);
        }

        if ($status !== 'all' && PaymentStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(static function (Builder $q) use ($pattern): void {
                $q->where('order_code', 'like', $pattern)
                    ->orWhere('provider_reference', 'like', $pattern)
                    ->orWhere('provider', 'like', $pattern);
            });
        }

        $paginator = $query->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('monetization/Payments', [
            'payments' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Payment $p): array => self::serializePayment($p))->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'service_type' => $serviceType,
                'status' => $status,
                'search' => $search,
                'highlight' => $highlight,
            ],
            'openPendingTotal' => $this->paymentService->openPendingCount($user),
            'openPendingTopUpCount' => $this->paymentService->openPendingCount($user, PaymentServiceType::TopUp),
            'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
            'sandboxEnabled' => (bool) config('syncviet.sandbox_topup_enabled', false),
        ])->withViewData([
            'seo' => [
                'title' => __('Payment History').' - '.config('app.name'),
                'noindex' => true,
            ],
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        if ($payment->user_id !== $user->id && ! $user->hasPermission('finance.view') && ! $user->isAdmin()) {
            abort(403);
        }

        return response()->json([
            'payment' => self::serializePayment($payment),
        ]);
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->paymentService->cancelPendingOrder($user, $payment);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Pending payment order cancelled.'),
        ]);

        return back();
    }

    /**
     * Non-production sandbox confirmation triggering a real HMAC-signed webhook callback.
     */
    public function sandboxConfirm(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless((bool) config('syncviet.sandbox_topup_enabled', false), 404);

        $user = $request->user();
        abort_if($user === null, 403);

        if ($payment->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $payloadArray = [
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference ?? 'SBX-'.Str::upper(Str::random(12)),
            'amount' => $payment->amount,
        ];
        $rawPayload = json_encode($payloadArray, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = $this->webhookService->signPayload($rawPayload, $timestamp);

        $this->webhookService->handleWebhook(
            $payment->provider,
            $rawPayload,
            $payloadArray,
            $signature,
            (string) $timestamp,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Sandbox webhook settled payment :code successfully.', ['code' => $payment->order_code ?? $payment->id]),
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializePayment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'order_code' => $p->order_code ?? ('PAY-'.$p->id),
            'service_type' => $p->service_type->value,
            'service_type_label' => $p->service_type->label(),
            'provider' => $p->provider,
            'provider_reference' => $p->provider_reference,
            'amount' => $p->amount,
            'fee_amount' => $p->fee_amount,
            'total_amount' => $p->totalAmount(),
            'refunded_amount' => $p->refunded_amount,
            'currency' => $p->currency,
            'exchange_rate_snapshot' => $p->exchange_rate_snapshot,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'platform' => $p->platform,
            'metadata' => $p->metadata ?? [],
            'can_cancel' => $p->status === PaymentStatus::Pending,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'expires_at' => $p->expires_at?->toIso8601String(),
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            'refunded_at' => $p->refunded_at?->toIso8601String(),
            'refund_reason' => $p->refund_reason,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
