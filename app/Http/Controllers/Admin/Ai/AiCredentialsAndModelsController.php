<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\AiModelGroup;
use App\Http\Controllers\Controller;
use App\Models\AiCredential;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiQuotaTracking;
use App\Models\User;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiCredentialAndQuotaService;
use App\Services\Ai\AiProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiCredentialsAndModelsController extends Controller
{
    public function __construct(
        private readonly AiCredentialAndQuotaService $credentialService,
        private readonly AiProviderManager $providerManager,
        private readonly AiAutomationControlService $control,
    ) {}

    public function index(): Response
    {
        $providers = AiProvider::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (AiProvider $p): array => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'driver' => $p->driver,
                'base_url' => $p->base_url,
                'is_enabled' => $p->is_enabled,
                'compliance_status' => $p->compliance_status,
                'compliance_notes' => $p->compliance_notes,
            ])
            ->values()
            ->all();

        // Strictly NEVER return api_key_encrypted or plaintext key in Inertia payloads!
        $credentials = AiCredential::query()
            ->with('provider:id,name,driver')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(static fn (AiCredential $c): array => [
                'id' => $c->id,
                'provider_id' => $c->provider_id,
                'provider_name' => $c->provider !== null ? $c->provider->name : 'Unknown',
                'name' => $c->name,
                'project_id' => $c->project_id,
                'masked_key' => $c->maskedKey(),
                'key_last4' => $c->key_last4,
                'billing_tier' => $c->billing_tier,
                'allowed_models' => $c->allowed_models ?? [],
                'rpm_limit' => $c->rpm_limit,
                'tpm_limit' => $c->tpm_limit,
                'rpd_limit' => $c->rpd_limit,
                'monthly_budget_cents' => $c->monthly_budget_cents,
                'priority' => $c->priority,
                'is_enabled' => $c->is_enabled,
                'health_status' => $c->health_status,
                'last_tested_at' => $c->last_tested_at?->toIso8601String(),
                'last_used_at' => $c->last_used_at?->toIso8601String(),
                'cooldown_until' => $c->cooldown_until?->toIso8601String(),
                'last_error' => $c->last_error,
            ])
            ->values()
            ->all();

        $models = AiModel::query()
            ->with('provider:id,name')
            ->orderBy('is_paid_only')
            ->orderBy('id')
            ->get()
            ->map(static fn (AiModel $m): array => [
                'id' => $m->id,
                'provider_id' => $m->provider_id,
                'provider_name' => $m->provider !== null ? $m->provider->name : 'Unknown',
                'model_id' => $m->model_id,
                'display_name' => $m->display_name,
                'role_group' => $m->role_group->value,
                'max_input_tokens' => $m->max_input_tokens,
                'max_output_tokens' => $m->max_output_tokens,
                'default_temperature' => $m->default_temperature,
                'supports_structured_output' => $m->supports_structured_output,
                'supports_thinking' => $m->supports_thinking,
                'is_paid_only' => $m->is_paid_only,
                'is_enabled' => $m->is_enabled,
                'input_price_per_million_cents' => $m->input_price_per_million_cents,
                'output_price_per_million_cents' => $m->output_price_per_million_cents,
                'pricing_verified' => $m->pricing_verified,
                'updated_at' => $m->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $quotaTracking = AiQuotaTracking::query()
            ->with('provider:id,name')
            ->latest('updated_at')
            ->limit(40)
            ->get()
            ->map(static fn (AiQuotaTracking $q): array => [
                'id' => $q->id,
                'provider_name' => $q->provider !== null ? $q->provider->name : 'Gemini',
                'project_id' => $q->project_id,
                'model_id' => $q->model_id,
                'window_type' => $q->window_type,
                'window_key' => $q->window_key,
                'requests_count' => $q->requests_count,
                'tokens_count' => $q->tokens_count,
                'last_rate_limited_at' => $q->last_rate_limited_at?->toIso8601String(),
                'waiting_for_quota_until' => $q->waiting_for_quota_until?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Ai/CredentialsAndModels', [
            'providers' => $providers,
            'credentials' => $credentials,
            'models' => $models,
            'quotaTracking' => $quotaTracking,
            'roleGroups' => array_map(static fn (AiModelGroup $g): array => [
                'value' => $g->value,
                'label' => $g->label(),
            ], AiModelGroup::cases()),
        ]);
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:ai_providers,id'],
            'name' => ['required', 'string', 'max:120'],
            'project_id' => ['required', 'string', 'max:120'],
            'api_key' => ['required', 'string', 'min:12', 'max:255'],
            'billing_tier' => ['required', Rule::in(['free', 'paid'])],
            'allowed_models' => ['nullable', 'array'],
            'allowed_models.*' => ['string', 'max:100'],
            'rpm_limit' => ['required', 'integer', 'min:1', 'max:5000'],
            'tpm_limit' => ['required', 'integer', 'min:1000', 'max:50000000'],
            'rpd_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'monthly_budget_cents' => ['required', 'integer', 'min:0', 'max:5000000'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->credentialService->storeCredential($validated, $actor);

        return back()->with('status', __('Encrypted AI API credential stored securely. Click "Test Connection" to verify live access.'));
    }

    public function updateCredential(Request $request, AiCredential $credential): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'project_id' => ['sometimes', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'min:12', 'max:255'],
            'billing_tier' => ['sometimes', Rule::in(['free', 'paid'])],
            'rpm_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'tpm_limit' => ['sometimes', 'integer', 'min:1000', 'max:50000000'],
            'rpd_limit' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'monthly_budget_cents' => ['sometimes', 'integer', 'min:0', 'max:5000000'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->credentialService->updateCredential($credential, $validated, $actor);

        return back()->with('status', __('AI API credential updated.'));
    }

    public function destroyCredential(Request $request, AiCredential $credential): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $meta = ['name' => $credential->name, 'project_id' => $credential->project_id, 'key_last4' => $credential->key_last4];
        $credentialId = $credential->id;
        $credential->delete();

        $this->control->logActivity('ai.credential.deleted', $actor, null, AiCredential::class, $credentialId, $meta);

        return back()->with('status', __('AI API credential deleted.'));
    }

    public function testCredential(Request $request, AiCredential $credential): RedirectResponse
    {
        $credential->loadMissing('provider');
        if ($credential->provider === null) {
            return back()->withErrors(['credential' => __('Provider not found for this credential.')]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $result = $this->providerManager->adapterFor($credential->provider)->testConnection($credential);

        $this->control->logActivity('ai.credential.tested', $actor, null, AiCredential::class, $credential->id, [
            'ok' => $result['ok'],
            'http_status' => $result['http_status'],
            'models_count' => $result['models_count'],
        ]);

        if (! $result['ok']) {
            return back()->withErrors(['credential' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    public function syncModels(Request $request, AiCredential $credential): RedirectResponse
    {
        $credential->loadMissing('provider');
        if ($credential->provider === null) {
            return back()->withErrors(['credential' => __('Provider not found.')]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $res = $this->providerManager->adapterFor($credential->provider)->listAvailableModels($credential);

        if (! $res['ok']) {
            return back()->withErrors(['credential' => $res['message']]);
        }

        $synced = 0;
        foreach ($res['models'] as $modelId) {
            $existing = AiModel::query()
                ->where('provider_id', $credential->provider_id)
                ->where('model_id', $modelId)
                ->first();

            if ($existing !== null) {
                $existing->touch();
                $synced++;
            }
        }

        $this->control->logActivity('ai.models.synced', $actor, null, AiCredential::class, $credential->id, [
            'discovered' => count($res['models']),
            'matched_registry' => $synced,
        ]);

        return back()->with('status', __('Model registry synchronized (:matched registered models verified out of :discovered discovered).', [
            'matched' => $synced,
            'discovered' => count($res['models']),
        ]));
    }

    public function updateModel(Request $request, AiModel $model): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'is_paid_only' => ['required', 'boolean'],
            'role_group' => ['required', Rule::in(array_map(static fn (AiModelGroup $g): string => $g->value, AiModelGroup::cases()))],
            'default_temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'input_price_per_million_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'output_price_per_million_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'pricing_verified' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $model->forceFill([
            'is_enabled' => (bool) $validated['is_enabled'],
            'is_paid_only' => (bool) $validated['is_paid_only'],
            'role_group' => AiModelGroup::from($validated['role_group']),
            'default_temperature' => (float) $validated['default_temperature'],
            'input_price_per_million_cents' => (int) $validated['input_price_per_million_cents'],
            'output_price_per_million_cents' => (int) $validated['output_price_per_million_cents'],
            'pricing_verified' => (bool) $validated['pricing_verified'],
        ])->save();

        $this->control->logActivity('ai.model.updated', $actor, null, AiModel::class, $model->id, [
            'model_id' => $model->model_id,
            'is_enabled' => $model->is_enabled,
            'is_paid_only' => $model->is_paid_only,
        ]);

        return back()->with('status', __('AI Model configuration updated.'));
    }
}
