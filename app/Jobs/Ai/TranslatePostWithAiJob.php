<?php

namespace App\Jobs\Ai;

use App\Models\Post;
use App\Models\User;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiTranslationWorkflowService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslatePostWithAiJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180, 600];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $postId,
        public readonly string $targetLocale,
        public readonly int $actorId,
    ) {
        $this->onQueue((string) config('syncviet.ai.queue_name', 'ai'));
    }

    public function uniqueId(): string
    {
        return "ai:translate:{$this->postId}:{$this->targetLocale}";
    }

    public function handle(AiAutomationControlService $control, AiTranslationWorkflowService $translator): void
    {
        if ($control->isEmergencyStopped()) {
            return;
        }

        $post = Post::query()->find($this->postId);
        $actor = User::query()->find($this->actorId);

        if ($post === null || $actor === null) {
            return;
        }

        $translator->translatePostToLocale($post, $this->targetLocale, $actor, false);
    }
}
