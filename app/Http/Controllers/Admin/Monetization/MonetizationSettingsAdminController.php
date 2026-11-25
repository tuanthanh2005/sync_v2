<?php

namespace App\Http\Controllers\Admin\Monetization;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Monetization\PaymentHistoryController;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentWebhookLog;
use App\Models\PlatformComplianceRule;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonetizationSettingsAdminController extends Controller
{
    public function __construct(
        private readonly MonetizationPaymentService $paymentService,
        private readonly AdSenseConfigService $adSenseService,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        MonetizationSeeder::seedDefaultsIfEmpty();

        $tab = $request->string('tab', 'methods')->toString();

        $methods = PaymentMethod::query()
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (PaymentMethod $m): array => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'category' => $m->category,
                'supported_currencies' => $m->supported_currencies,
                'fee_percent_bps' => $m->fee_percent_bps,
                'fee_fixed_vnd' => $m->fee_fixed_vnd,
                'fee_fixed_usd_cents' => $m->fee_fixed_usd_cents,
                'is_enabled' => $m->is_enabled,
                'is_live_ready' => $m->is_live_ready,
                'requires_legal_clearance' => $m->requires_legal_clearance,
                'instructions' => $m->instructions ?? [],
            ])
            ->values()
            ->all();

        $pendingOrders = Payment::query()
            ->with('user:id,name,username,email')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (Payment $p): array {
                $row = PaymentHistoryController::serializePayment($p);
                $row['username'] = $p->user !== null ? $p->user->username : '-';
                $row['email'] = $p->user !== null ? $p->user->email : '-';

                return $row;
            })
            ->values()
            ->all();

        $complianceRules = PlatformComplianceRule::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (PlatformComplianceRule $r): array => [
                'id' => $r->id,
                'platform' => $r->platform,
                'allow_external_web_checkout' => $r->allow_external_web_checkout,
                'allow_premium_purchase' => $r->allow_premium_purchase,
                'allow_banner_display' => $r->allow_banner_display,
                'allow_boost_purchase' => $r->allow_boost_purchase,
                'require_native_iap_for_digital' => $r->require_native_iap_for_digital,
                'allowed_payment_methods' => $r->allowed_payment_methods,
                'policy_note' => $r->policy_note,
            ])
            ->values()
            ->all();

        $webhookLogs = PaymentWebhookLog::query()
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (PaymentWebhookLog $l): array => [
                'id' => $l->id,
                'provider' => $l->provider,
                'payment_id' => is_array($l->payload_summary) ? ($l->payload_summary['payment_id'] ?? null) : null,
                'provider_reference' => $l->event_id,
                'signature_valid' => $l->signature_valid,
                'http_status' => is_array($l->payload_summary) ? (int) ($l->payload_summary['http_status'] ?? 200) : 200,
                'outcome' => $l->status,
                'ip_address' => $l->ip_address,
                'error_message' => $l->error_message,
                'created_at' => $l->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Monetization/Settings', [
            'tab' => $tab,
            'paymentMethods' => $methods,
            'pendingOrders' => $pendingOrders,
            'adSense' => $this->adSenseService->adminPayload(),
            'complianceRules' => $complianceRules,
            'webhookLogs' => $webhookLogs,
            'monetizationConfig' => [
                'usd_to_vnd_rate' => $this->paymentService->exchangeRateVndPerUsd(),
                'max_pending_payments' => $this->paymentService->maxPendingPaymentsPerUser(),
            ],
        ])->withViewData([
            'seo' => ['title' => __('Monetization Settings & Compliance').' - Admin', 'noindex' => true],
        ]);
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'fee_percent_bps' => ['required', 'integer', 'min:0', 'max:2500'],
            'fee_fixed_vnd' => ['required', 'integer', 'min:0', 'max:1000000'],
            'fee_fixed_usd_cents' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_enabled' => ['required', 'boolean'],
            'is_live_ready' => ['required', 'boolean'],
            'instructions' => ['nullable', 'array'],
        ]);

        if ($paymentMethod->requires_legal_clearance && (bool) $validated['is_enabled'] && ! (bool) $validated['is_live_ready']) {
            throw ValidationException::withMessages([
                'is_enabled' => [__('Crypto payment adapters require legal clearance and live readiness before enabling.')],
            ]);
        }

        $paymentMethod->update($validated);
        $this->auditLogger->log('admin.payment_method.updated', $paymentMethod, [
            'code' => $paymentMethod->code,
            'is_enabled' => $paymentMethod->is_enabled,
        ], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment method updated.')]);

        return back();
    }

    public function confirmPayment(Request $request, Payment $payment): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'min:4', 'max:120'],
            'reason' => ['required', 'string', 'min:3', 'max:400'],
            'password' => ['required', 'string'],
        ]);

        if (! is_string($actor->password) || ! Hash::check((string) $validated['password'], $actor->password)) {
            throw ValidationException::withMessages([
                'password' => [__('Current password confirmation failed.')],
            ]);
        }

        $this->paymentService->settleOrder(
            payment: $payment,
            providerReference: trim((string) $validated['provider_reference']),
            actor: $actor,
            note: trim((string) $validated['reason']),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment manually reconciled and fulfilled.')]);

        return back();
    }

    public function refundPayment(Request $request, Payment $payment): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:400'],
            'password' => ['required', 'string'],
        ]);

        if (! is_string($actor->password) || ! Hash::check((string) $validated['password'], $actor->password)) {
            throw ValidationException::withMessages([
                'password' => [__('Current password confirmation failed.')],
            ]);
        }

        $this->paymentService->adminRefundOrder(
            actor: $actor,
            payment: $payment,
            amount: (int) $validated['amount'],
            reason: (string) $validated['reason'],
            idempotencyKey: 'admin-refund-'.$payment->id.'-'.Str::random(12),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund recorded successfully.')]);

        return back();
    }

    public function updateAdSense(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $this->adSenseService->updateConfig($request->all(), $actor, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google AdSense settings updated safely.')]);

        return back();
    }

    public function updateCompliance(Request $request, PlatformComplianceRule $platformComplianceRule): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'allow_external_web_checkout' => ['required', 'boolean'],
            'allow_premium_purchase' => ['required', 'boolean'],
            'allow_banner_display' => ['required', 'boolean'],
            'allow_boost_purchase' => ['required', 'boolean'],
            'require_native_iap_for_digital' => ['required', 'boolean'],
            'allowed_payment_methods' => ['required', 'array'],
            'allowed_payment_methods.*' => ['string', 'max:40'],
            'policy_note' => ['nullable', 'string', 'max:500'],
        ]);

        $platformComplianceRule->fill($validated);
        $platformComplianceRule->updated_by = $actor->id;
        $platformComplianceRule->save();

        $this->auditLogger->log('admin.platform_compliance.updated', $platformComplianceRule, [
            'platform' => $platformComplianceRule->platform,
        ], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Platform compliance rule saved.')]);

        return back();
    }

    public function updateGeneralMonetization(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'monetization_usd_to_vnd_rate' => ['required', 'integer', 'min:1000', 'max:200000'],
            'monetization_max_pending_payments' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $this->settings->updateMany($validated, $actor, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Monetization guardrails updated.')]);

        return back();
    }
}
