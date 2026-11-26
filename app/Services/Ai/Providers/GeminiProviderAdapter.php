<?php

namespace App\Services\Ai\Providers;

use App\Models\AiCredential;
use App\Models\AiModel;
use App\Services\Ai\AiCredentialAndQuotaService;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiGenerationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeminiProviderAdapter implements AiProviderInterface
{
    public function __construct(
        private readonly AiCredentialAndQuotaService $credentials,
    ) {}

    public function driver(): string
    {
        return 'gemini';
    }

    /**
     * Live connection test via GET /v1beta/models using x-goog-api-key header (NEVER query param).
     *
     * @return array{ok: bool, http_status: int, models_count: int, message: string, error_code: string|null}
     */
    public function testConnection(AiCredential $credential): array
    {
        $res = $this->listAvailableModels($credential);

        $credential->forceFill([
            'last_tested_at' => now(),
            'health_status' => $res['ok'] ? 'healthy' : ($res['http_status'] === 401 || $res['http_status'] === 403 ? 'auth_error' : 'degraded'),
            'last_error' => $res['ok'] ? null : Str::limit($res['message'], 400),
        ])->save();

        return [
            'ok' => $res['ok'],
            'http_status' => $res['http_status'],
            'models_count' => count($res['models']),
            'message' => $res['message'],
            'error_code' => $res['ok'] ? null : $this->classifyHttpError($res['http_status']),
        ];
    }

    /**
     * @return array{ok: bool, http_status: int, models: list<string>, message: string}
     */
    public function listAvailableModels(AiCredential $credential): array
    {
        $apiKey = $this->credentials->decryptApiKey($credential);
        $baseUrl = rtrim($credential->provider !== null ? $credential->provider->base_url : (string) config('syncviet.ai.gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->get($baseUrl.'/models');

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'http_status' => $response->status(),
                    'models' => [],
                    'message' => $this->sanitizeError('Gemini API returned HTTP '.$response->status().' ('.$this->classifyHttpError($response->status()).').', $apiKey),
                ];
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $modelsList = is_array($json['models'] ?? null) ? $json['models'] : [];
            $names = [];

            foreach ($modelsList as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null)) {
                    $names[] = str_replace('models/', '', $entry['name']);
                }
            }

            return [
                'ok' => true,
                'http_status' => $response->status(),
                'models' => $names,
                'message' => __('Connected to Gemini API successfully (:count models discovered).', ['count' => count($names)]),
            ];
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'http_status' => 0,
                'models' => [],
                'message' => $this->sanitizeError('Network timeout connecting to Gemini API: '.$e->getMessage(), $apiKey),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'http_status' => 0,
                'models' => [],
                'message' => $this->sanitizeError($e->getMessage(), $apiKey),
            ];
        }
    }

    /**
     * Invoke Gemini generateContent via x-goog-api-key header with optional Structured Output JSON Schema.
     *
     * @param  array{
     *     system_instruction?: string|null,
     *     user_prompt: string,
     *     temperature?: float|null,
     *     max_output_tokens?: int|null,
     *     json_schema?: array<string, mixed>|null
     * }  $payload
     */
    public function generate(AiCredential $credential, AiModel $model, array $payload): AiGenerationResult
    {
        $apiKey = $this->credentials->decryptApiKey($credential);
        $baseUrl = rtrim($credential->provider !== null ? $credential->provider->base_url : (string) config('syncviet.ai.gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $endpoint = sprintf('%s/models/%s:generateContent', $baseUrl, rawurlencode($model->model_id));
        $timeout = max(10, (int) config('syncviet.ai.request_timeout_seconds', 35));

        $generationConfig = [
            'temperature' => $payload['temperature'] ?? $model->default_temperature,
            'maxOutputTokens' => min($model->max_output_tokens, (int) ($payload['max_output_tokens'] ?? 2048)),
        ];

        if ($model->supports_structured_output && isset($payload['json_schema'])) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseJsonSchema'] = $payload['json_schema'];
        }

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $payload['user_prompt']],
                    ],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        if (isset($payload['system_instruction']) && trim($payload['system_instruction']) !== '') {
            $body['systemInstruction'] = [
                'parts' => [
                    ['text' => trim($payload['system_instruction'])],
                ],
            ];
        }

        $started = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($endpoint, $body);

            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $status = $response->status();

            if (! $response->successful()) {
                $retryAfterHeader = $response->header('Retry-After');
                $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : ($status === 429 ? 120 : null);
                $errorCode = $this->classifyHttpError($status);

                /** @var array<string, mixed> $errJson */
                $errJson = $response->json() ?? [];
                $providerMsg = is_string($errJson['error']['message'] ?? null)
                    ? $errJson['error']['message']
                    : 'HTTP '.$status.' ('.$errorCode.')';

                return new AiGenerationResult(
                    ok: false,
                    httpStatus: $status,
                    durationMs: $durationMs,
                    errorCode: $errorCode,
                    errorMessage: $this->sanitizeError($providerMsg, $apiKey),
                    retryAfterSeconds: $retryAfter,
                    isRateLimited: $status === 429,
                    isWaitingForQuota: $status === 429,
                );
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $text = $this->extractCandidateText($json);
            $usage = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];

            $structured = null;
            if (isset($payload['json_schema']) && $text !== '') {
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    $structured = $decoded;
                }
            }

            return new AiGenerationResult(
                ok: $text !== '',
                httpStatus: $status,
                text: $text,
                structuredData: $structured,
                inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
                outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
                thinkingTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
                cachedTokens: (int) ($usage['cachedContentTokenCount'] ?? 0),
                durationMs: $durationMs,
                errorCode: $text === '' ? 'EMPTY_CANDIDATE' : null,
                errorMessage: $text === '' ? 'Gemini returned an empty candidate response.' : null,
            );
        } catch (ConnectionException $e) {
            return new AiGenerationResult(
                ok: false,
                httpStatus: 0,
                durationMs: (int) round((microtime(true) - $started) * 1000),
                errorCode: 'NETWORK_TIMEOUT',
                errorMessage: $this->sanitizeError($e->getMessage(), $apiKey),
            );
        } catch (\Throwable $e) {
            return new AiGenerationResult(
                ok: false,
                httpStatus: 0,
                durationMs: (int) round((microtime(true) - $started) * 1000),
                errorCode: 'PROVIDER_EXCEPTION',
                errorMessage: $this->sanitizeError($e->getMessage(), $apiKey),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractCandidateText(array $json): string
    {
        $candidates = $json['candidates'] ?? null;
        if (! is_array($candidates) || $candidates === []) {
            return '';
        }

        $parts = $candidates[0]['content']['parts'] ?? null;
        if (! is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $chunks[] = $part['text'];
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function classifyHttpError(int $status): string
    {
        return match ($status) {
            400 => 'INVALID_REQUEST',
            401 => 'AUTHENTICATION_ERROR',
            403 => 'PERMISSION_DENIED',
            404 => 'MODEL_NOT_AVAILABLE',
            429 => 'RESOURCE_EXHAUSTED',
            500 => 'PROVIDER_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status === 0 ? 'NETWORK_TIMEOUT' : 'HTTP_'.$status,
        };
    }

    private function sanitizeError(string $message, string $apiKey): string
    {
        $cleaned = str_replace($apiKey, '[REDACTED]', $message);

        return Str::limit($cleaned, 450);
    }
}
