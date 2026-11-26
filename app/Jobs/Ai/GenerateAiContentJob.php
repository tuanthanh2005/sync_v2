<?php

namespace App\Jobs\Ai;

use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiContentGeneratorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180, 600];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /**
     * @param  array{
     *     agent_id: int,
     *     topic: string,
     *     language_code?: string,
     *     content_type?: string,
     *     category?: string,
     *     community_id?: int|null,
     *     plan_id?: int|null,
     *     source_id?: int|null,
     *     prompt_template_id?: int|null,
     *     seo_context?: string|null,
     *     manual_trigger?: bool
     * }  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {
        $this->onQueue((string) config('syncviet.ai.queue_name', 'ai'));
    }

    public function uniqueId(): string
    {
        return 'ai:gen:'.hash('sha256', ($this->payload['agent_id']).'|'.$this->payload['topic'].'|'.($this->payload['language_code'] ?? 'en'));
    }

    public function handle(AiAutomationControlService $control, AiContentGeneratorService $generator): void
    {
        if ($control->isEmergencyStopped()) {
            return;
        }

        $generator->generateDraft($this->payload, null);
    }
}
