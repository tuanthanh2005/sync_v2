<?php

namespace App\Services\Ai;

use App\Models\AiCredential;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiQuotaTracking;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\Ai\Dto\AiGenerationResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCredentialAndQuotaService
{
    public function __construct(
        private readonly AiAutomationControlService $control,
    ) {}

    /**
     * Store a new encrypted API credential (unlimited credentials supported; never exposes full key).
     *
     * @param  array{
     *     provider_id: int,
     *     name: string,
     *     project_id: string,
     *     api_key: string,
     *     billing_tier?: string,
     *     allowed_models?: list<string>|null,
     *     rpm_limit?: int,
     *     tpm_limit?: int,
     *     rpd_limit?: int,
     *     monthly_budget_cents?: int,
     *     priority?: int
     * }  $data
     */
    public function storeCredential(array $data, User $actor): AiCredential
    {
        $rawKey = trim($data['api_key']);
        if (strlen($rawKey) < 12) {
            throw ValidationException::withMessages([
                'api_key' => [__('API key appears invalid or too short.')],
            ]);
        }

        $fingerprint = hash('sha256', $rawKey);
        if (AiCredential::query()->where('key_fingerprint', $fingerprint)->exists()) {
            throw ValidationException::withMessages([
                'api_key' => [__('This API key is already registered in AI Credentials.')],
            ]);
        }

        $credential = new AiCredential([
            'provider_id' => $data['provider_id'],
            'name' => trim($data['name']),
            'project_id' => trim($data['project_id']) ?: 'default-project',
            'key_fingerprint' => $fingerprint,
            'key_last4' => substr($rawKey, -4),
            'allowed_models' => $data['allowed_models'] ?? null,
            'billing_tier' => in_array($data['billing_tier'] ?? 'free', ['free', 'paid'], true) ? ($data['billing_tier'] ?? 'free') : 'free',
            'rpm_limit' => max(1, (int) ($data['rpm_limit'] ?? 10)),
            'tpm_limit' => max(1000, (int) ($data['tpm_limit'] ?? 250000)),
            'rpd_limit' => max(1, (int) ($data['rpd_limit'] ?? 250)),
            'monthly_budget_cents' => max(0, (int) ($data['monthly_budget_cents'] ?? 0)),
            'priority' => max(1, min(1000, (int) ($data['priority'] ?? 10))),
            'is_enabled' => true,
            'health_status' => 'untested',
            'created_by' => $actor->id,
        ]);
        $credential->api_key_encrypted = Crypt::encryptString($rawKey);
        $credential->save();

        $this->control->logActivity('ai.credential.created', $actor, null, AiCredential::class, $credential->id, [
            'name' => $credential->name,
            'project_id' => $credential->project_id,
            'key_last4' => $credential->key_last4,
            'billing_tier' => $credential->billing_tier,
        ]);

        return $credential;
    }

    /**
     * @param  array{
     *     name?: string,
     *     project_id?: string,
     *     api_key?: string|null,
     *     billing_tier?: string,
     *     allowed_models?: list<string>|null,
     *     rpm_limit?: int,
     *     tpm_limit?: int,
     *     rpd_limit?: int,
     *     monthly_budget_cents?: int,
     *     priority?: int,
     *     is_enabled?: bool
     * }  $data
     */
    public function updateCredential(AiCredential $credential, array $data, User $actor): AiCredential
    {
        $updates = [
            'name' => isset($data['name']) ? trim($data['name']) : $credential->name,
            'project_id' => isset($data['project_id']) ? (trim($data['project_id']) ?: 'default-project') : $credential->project_id,
            'billing_tier' => isset($data['billing_tier']) && in_array($data['billing_tier'], ['free', 'paid'], true) ? $data['billing_tier'] : $credential->billing_tier,
            'allowed_models' => array_key_exists('allowed_models', $data) ? $data['allowed_models'] : $credential->allowed_models,
            'rpm_limit' => isset($data['rpm_limit']) ? max(1, (int) $data['rpm_limit']) : $credential->rpm_limit,
            'tpm_limit' => isset($data['tpm_limit']) ? max(1000, (int) $data['tpm_limit']) : $credential->tpm_limit,
            'rpd_limit' => isset($data['rpd_limit']) ? max(1, (int) $data['rpd_limit']) : $credential->rpd_limit,
            'monthly_budget_cents' => isset($data['monthly_budget_cents']) ? max(0, (int) $data['monthly_budget_cents']) : $credential->monthly_budget_cents,
            'priority' => isset($data['priority']) ? max(1, min(1000, (int) $data['priority'])) : $credential->priority,
            'is_enabled' => isset($data['is_enabled']) ? (bool) $data['is_enabled'] : $credential->is_enabled,
        ];

        $credential->forceFill($updates);

        if (isset($data['api_key']) && trim($data['api_key']) !== '') {
            $rawKey = trim($data['api_key']);
            $fingerprint = hash('sha256', $rawKey);
            $duplicate = AiCredential::query()
                ->where('key_fingerprint', $fingerprint)
                ->where('id', '!=', $credential->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'api_key' => [__('Replacement API key is already registered on another credential.')],
                ]);
            }
            $credential->api_key_encrypted = Crypt::encryptString($rawKey);
            $credential->key_fingerprint = $fingerprint;
            $credential->key_last4 = substr($rawKey, -4);
            $credential->health_status = 'untested';
            $credential->last_error = null;
            $credential->cooldown_until = null;
        }

        $credential->save();

        $this->control->logActivity('ai.credential.updated', $actor, null, AiCredential::class, $credential->id, [
            'name' => $credential->name,
            'project_id' => $credential->project_id,
            'is_enabled' => $credential->is_enabled,
        ]);

        return $credential;
    }

    public function decryptApiKey(AiCredential $credential): string
    {
        return Crypt::decryptString($credential->api_key_encrypted);
    }

    /**
     * Select an eligible credential for the given provider + model respecting:
     * - Billing tier compliance (never silently route free model requests to paid-only model without explicit permission)
     * - Project + Model shared quota (keys sharing the same project_id share quota and NEVER rotate to bypass rate limits!)
     * - Cooldown & health status.
     *
     * @return array{credential: AiCredential|null, waiting_for_quota: bool, retry_at: CarbonInterface|null, reason: string|null}
     */
    public function selectCredentialForModel(AiProvider $provider, AiModel $model): array
    {
        $candidates = AiCredential::query()
            ->where('provider_id', $provider->id)
            ->where('is_enabled', true)
            ->whereNotIn('health_status', ['auth_error'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'credential' => null,
                'waiting_for_quota' => false,
                'retry_at' => null,
                'reason' => 'No enabled AI credentials configured for '.$provider->name.'.',
            ];
        }

        foreach ($candidates as $cred) {
            if ($model->is_paid_only && $cred->billing_tier !== 'paid') {
                continue;
            }

            if (is_array($cred->allowed_models) && $cred->allowed_models !== [] && ! in_array($model->model_id, $cred->allowed_models, true)) {
                continue;
            }

            if ($cred->isInCooldown()) {
                continue;
            }

            // Keys in the same Project share a single Project+Model quota bucket; do NOT bypass by checking another key when exhausted.
            $quotaStatus = $this->checkProjectModelQuota($cred, $model);

            if (! $quotaStatus['allowed']) {
                return [
                    'credential' => null,
                    'waiting_for_quota' => true,
                    'retry_at' => $quotaStatus['retry_at'] ?? now()->addMinutes(2),
                    'reason' => $quotaStatus['reason'],
                ];
            }

            return [
                'credential' => $cred,
                'waiting_for_quota' => false,
                'retry_at' => null,
                'reason' => null,
            ];
        }

        return [
            'credential' => null,
            'waiting_for_quota' => false,
            'retry_at' => null,
            'reason' => 'No compatible credential available for model '.$model->model_id.'.',
        ];
    }

    /**
     * Check shared Project + Model RPM/TPM/RPD windows.
     *
     * @return array{allowed: bool, retry_at: CarbonInterface|null, reason: string|null}
     */
    public function checkProjectModelQuota(AiCredential $credential, AiModel $model): array
    {
        $minuteKey = now()->format('Y-m-d H:i');
        $dayKey = now()->format('Y-m-d');

        $minuteRow = AiQuotaTracking::query()
            ->where('provider_id', $credential->provider_id)
            ->where('project_id', $credential->project_id)
            ->where('model_id', $model->model_id)
            ->where('window_type', 'minute')
            ->where('window_key', $minuteKey)
            ->first();

        if ($minuteRow !== null) {
            if ($minuteRow->waiting_for_quota_until !== null && $minuteRow->waiting_for_quota_until->isFuture()) {
                return [
                    'allowed' => false,
                    'retry_at' => $minuteRow->waiting_for_quota_until,
                    'reason' => "Project {$credential->project_id} is in rate-limit backoff for {$model->model_id}.",
                ];
            }

            if ($minuteRow->requests_count >= $credential->rpm_limit || $minuteRow->tokens_count >= $credential->tpm_limit) {
                return [
                    'allowed' => false,
                    'retry_at' => now()->addMinute()->startOfMinute(),
                    'reason' => "Project {$credential->project_id} reached configured per-minute quota for {$model->model_id}.",
                ];
            }
        }

        $dayRow = AiQuotaTracking::query()
            ->where('provider_id', $credential->provider_id)
            ->where('project_id', $credential->project_id)
            ->where('model_id', $model->model_id)
            ->where('window_type', 'day')
            ->where('window_key', $dayKey)
            ->first();

        if ($dayRow !== null) {
            if ($dayRow->waiting_for_quota_until !== null && $dayRow->waiting_for_quota_until->isFuture()) {
                return [
                    'allowed' => false,
                    'retry_at' => $dayRow->waiting_for_quota_until,
                    'reason' => "Project {$credential->project_id} waiting for quota reset on {$model->model_id}.",
                ];
            }

            if ($dayRow->requests_count >= $credential->rpd_limit) {
                return [
                    'allowed' => false,
                    'retry_at' => now()->addDay()->startOfDay(),
                    'reason' => "Project {$credential->project_id} reached configured daily request limit ({$credential->rpd_limit} RPD) for {$model->model_id}.",
                ];
            }
        }

        return ['allowed' => true, 'retry_at' => null, 'reason' => null];
    }

    public function recordInvocation(
        AiCredential $credential,
        AiModel $model,
        string $taskType,
        AiGenerationResult $result,
        ?int $agentId = null,
        ?string $languageCode = null,
    ): AiUsageRecord {
        $totalTokens = $result->inputTokens + $result->outputTokens + $result->thinkingTokens;
        $estimatedMicro = $model->estimateCostMicroUsd($result->inputTokens, $result->outputTokens + $result->thinkingTokens);

        $usage = AiUsageRecord::query()->create([
            'provider_id' => $credential->provider_id,
            'credential_id' => $credential->id,
            'model_id' => $model->id,
            'agent_id' => $agentId,
            'project_id' => $credential->project_id,
            'task_type' => $taskType,
            'language_code' => $languageCode,
            'status' => $result->ok ? 'success' : ($result->isRateLimited ? 'rate_limited' : 'failed'),
            'http_status' => $result->httpStatus,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'thinking_tokens' => $result->thinkingTokens,
            'cached_tokens' => $result->cachedTokens,
            'duration_ms' => $result->durationMs,
            'estimated_cost_micro_usd' => $estimatedMicro,
            'pricing_verified' => $model->pricing_verified,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage !== null ? Str::limit($result->errorMessage, 500) : null,
            'created_at' => now(),
        ]);

        // Update shared Project + Model quota counters
        $this->incrementQuotaWindow($credential, $model, 'minute', now()->format('Y-m-d H:i'), $totalTokens, $result);
        $this->incrementQuotaWindow($credential, $model, 'day', now()->format('Y-m-d'), $totalTokens, $result);

        // Update credential health & circuit breaker
        $health = $credential->health_status;
        $cooldown = $credential->cooldown_until;

        if ($result->ok) {
            $health = 'healthy';
            $cooldown = null;
        } elseif (in_array($result->httpStatus, [401, 403], true)) {
            $health = 'auth_error';
        } elseif ($result->httpStatus === 429) {
            $health = 'rate_limited';
            $cooldown = now()->addSeconds(max(60, (int) ($result->retryAfterSeconds ?? 120)));
        } elseif ($result->httpStatus >= 500 || $result->httpStatus === 0) {
            $health = 'degraded';
            $cooldown = now()->addSeconds(60);
        }

        $credential->forceFill([
            'health_status' => $health,
            'last_used_at' => now(),
            'last_error' => $result->ok ? null : Str::limit((string) ($result->errorMessage ?? 'HTTP '.$result->httpStatus), 400),
            'cooldown_until' => $cooldown,
        ])->save();

        return $usage;
    }

    private function incrementQuotaWindow(
        AiCredential $credential,
        AiModel $model,
        string $windowType,
        string $windowKey,
        int $tokens,
        AiGenerationResult $result,
    ): void {
        $row = AiQuotaTracking::query()->firstOrCreate(
            [
                'provider_id' => $credential->provider_id,
                'project_id' => $credential->project_id,
                'model_id' => $model->model_id,
                'window_type' => $windowType,
                'window_key' => $windowKey,
            ],
            [
                'requests_count' => 0,
                'tokens_count' => 0,
            ],
        );

        $row->increment('requests_count');
        if ($tokens > 0) {
            $row->increment('tokens_count', $tokens);
        }

        if ($result->isRateLimited) {
            $retrySeconds = max(60, (int) ($result->retryAfterSeconds ?? 120));
            $row->forceFill([
                'waiting_for_quota_until' => now()->addSeconds($retrySeconds),
                'last_rate_limited_at' => now(),
            ])->save();
        }
    }
}
