<?php

namespace App\Services\Ai;

use App\Enums\AiModelGroup;
use App\Models\AiCredential;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiGenerationResult;
use App\Services\Ai\Providers\GeminiProviderAdapter;

class AiProviderManager
{
    public function __construct(
        private readonly GeminiProviderAdapter $geminiAdapter,
        private readonly AiCredentialAndQuotaService $credentials,
        private readonly AiAutomationControlService $control,
    ) {}

    public function adapterFor(AiProvider $provider): AiProviderInterface
    {
        return match ($provider->driver) {
            'gemini' => $this->geminiAdapter,
            default => $this->geminiAdapter,
        };
    }

    public function resolveModel(?int $preferredModelId, AiModelGroup $fallbackGroup = AiModelGroup::Fast): ?AiModel
    {
        if ($preferredModelId !== null) {
            $preferred = AiModel::query()->with('provider')->whereKey($preferredModelId)->where('is_enabled', true)->first();
            if ($preferred !== null) {
                return $preferred;
            }
        }

        $allowPaidFallback = $this->control->bool('allow_paid_model_fallback', false);

        $query = AiModel::query()
            ->with('provider')
            ->where('is_enabled', true)
            ->whereHas('provider', static fn ($q) => $q->where('is_enabled', true));

        if (! $allowPaidFallback) {
            $query->where('is_paid_only', false);
        }

        $byGroup = (clone $query)->where('role_group', $fallbackGroup->value)->first();
        if ($byGroup !== null) {
            return $byGroup;
        }

        return $query->orderBy('is_paid_only')->orderBy('id')->first();
    }

    /**
     * Execute a governed AI request through Emergency Stop, Hard Budget Gate, Project+Model Quota Gate, and Usage Ledger.
     *
     * @param  array{
     *     system_instruction?: string|null,
     *     user_prompt: string,
     *     temperature?: float|null,
     *     max_output_tokens?: int|null,
     *     json_schema?: array<string, mixed>|null
     * }  $payload
     * @return array{result: AiGenerationResult, credential: AiCredential|null, model: AiModel|null}
     */
    public function execute(
        string $taskType,
        array $payload,
        ?int $preferredModelId = null,
        AiModelGroup $fallbackGroup = AiModelGroup::Fast,
        ?int $agentId = null,
        ?string $languageCode = null,
        bool $bypassEmergencyStop = false,
    ): array {
        if (! $bypassEmergencyStop && $this->control->isEmergencyStopped()) {
            return [
                'result' => new AiGenerationResult(
                    ok: false,
                    httpStatus: 423,
                    errorCode: 'EMERGENCY_STOP',
                    errorMessage: 'AI Emergency Stop is active.',
                ),
                'credential' => null,
                'model' => null,
            ];
        }

        $budget = $this->control->checkBudgetGate();
        if (! $budget['allowed']) {
            return [
                'result' => new AiGenerationResult(
                    ok: false,
                    httpStatus: 429,
                    errorCode: 'BUDGET_LIMIT_REACHED',
                    errorMessage: $budget['reason'] ?? 'AI budget limit reached.',
                    isWaitingForQuota: true,
                ),
                'credential' => null,
                'model' => null,
            ];
        }

        $model = $this->resolveModel($preferredModelId, $fallbackGroup);
        if ($model === null || $model->provider === null) {
            return [
                'result' => new AiGenerationResult(
                    ok: false,
                    httpStatus: 404,
                    errorCode: 'MODEL_NOT_AVAILABLE',
                    errorMessage: 'No enabled AI model is configured.',
                ),
                'credential' => null,
                'model' => null,
            ];
        }

        // Strict safeguard: never silently escalate to a paid-only model without explicit Admin enablement
        if ($model->is_paid_only && ! $this->control->bool('allow_paid_model_fallback', false) && $preferredModelId !== $model->id) {
            return [
                'result' => new AiGenerationResult(
                    ok: false,
                    httpStatus: 403,
                    errorCode: 'PAID_MODEL_BLOCKED',
                    errorMessage: 'Automatic escalation to paid-only AI models is disabled.',
                ),
                'credential' => null,
                'model' => $model,
            ];
        }

        $selection = $this->credentials->selectCredentialForModel($model->provider, $model);
        if ($selection['credential'] === null) {
            return [
                'result' => new AiGenerationResult(
                    ok: false,
                    httpStatus: $selection['waiting_for_quota'] ? 429 : 503,
                    errorCode: $selection['waiting_for_quota'] ? 'WAITING_FOR_QUOTA' : 'NO_CREDENTIAL',
                    errorMessage: $selection['reason'] ?? 'No AI credential available.',
                    retryAfterSeconds: $selection['retry_at'] !== null ? max(60, (int) now()->diffInSeconds($selection['retry_at'], false)) : null,
                    isRateLimited: $selection['waiting_for_quota'],
                    isWaitingForQuota: $selection['waiting_for_quota'],
                ),
                'credential' => null,
                'model' => $model,
            ];
        }

        $credential = $selection['credential'];
        $adapter = $this->adapterFor($model->provider);
        $result = $adapter->generate($credential, $model, $payload);

        $this->credentials->recordInvocation($credential, $model, $taskType, $result, $agentId, $languageCode);

        return [
            'result' => $result,
            'credential' => $credential,
            'model' => $model,
        ];
    }
}
