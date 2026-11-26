<?php

namespace App\Services\Ai\Dto;

readonly class AiGenerationResult
{
    /**
     * @param  array<string, mixed>|null  $structuredData
     */
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public string $text = '',
        public ?array $structuredData = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $thinkingTokens = 0,
        public int $cachedTokens = 0,
        public int $durationMs = 0,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $retryAfterSeconds = null,
        public bool $isRateLimited = false,
        public bool $isWaitingForQuota = false,
    ) {}
}
