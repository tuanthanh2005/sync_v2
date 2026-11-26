<?php

namespace App\Jobs\Ai;

use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiPublishingAndSchedulerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishScheduledAiContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue((string) config('syncviet.ai.queue_name', 'ai'));
    }

    public function uniqueId(): string
    {
        return 'ai:publish_scheduled_batch';
    }

    public function handle(AiAutomationControlService $control, AiPublishingAndSchedulerService $publisher): void
    {
        if ($control->isEmergencyStopped()) {
            return;
        }

        $publisher->processDueSchedules(2);
    }
}
