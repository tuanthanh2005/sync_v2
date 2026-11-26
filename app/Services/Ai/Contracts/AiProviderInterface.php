<?php

namespace App\Services\Ai\Contracts;

use App\Models\AiCredential;
use App\Models\AiModel;
use App\Services\Ai\Dto\AiGenerationResult;

interface AiProviderInterface
{
    public function driver(): string;

    /**
     * @return array{ok: bool, http_status: int, models_count: int, message: string, error_code: string|null}
     */
    public function testConnection(AiCredential $credential): array;

    /**
     * @return array{ok: bool, http_status: int, models: list<string>, message: string}
     */
    public function listAvailableModels(AiCredential $credential): array;

    /**
     * @param  array{
     *     system_instruction?: string|null,
     *     user_prompt: string,
     *     temperature?: float|null,
     *     max_output_tokens?: int|null,
     *     json_schema?: array<string, mixed>|null
     * }  $payload
     */
    public function generate(AiCredential $credential, AiModel $model, array $payload): AiGenerationResult;
}
