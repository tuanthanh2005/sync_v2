<?php

namespace App\Jobs\Seo;

use App\Enums\SeoEntityType;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\SeoJobHistory;
use App\Models\User;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\SeoRegistryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSeoEntityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 30;

    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly bool $removed = false,
        public readonly int $removedHttpStatus = 410,
    ) {
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return sprintf('seo:%s:%d:%d', $this->entityType, $this->entityId, $this->removed ? 1 : 0);
    }

    public function handle(SeoRegistryService $registry, IndexNowService $indexNow): void
    {
        $started = microtime(true);
        $history = SeoJobHistory::query()->create([
            'job_name' => 'ProcessSeoEntityJob',
            'queue' => $this->queue ?: 'default',
            'status' => 'running',
            'context' => [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'removed' => $this->removed,
            ],
            'started_at' => now(),
        ]);

        try {
            $type = SeoEntityType::tryFrom($this->entityType);
            if ($type === null) {
                $this->finish($history, $started, 0, 0);

                return;
            }

            if ($this->removed) {
                $count = $registry->markEntityRemoved($type, $this->entityId, $this->removedHttpStatus);
                $this->finish($history, $started, $count, 0);

                return;
            }

            $entry = match ($type) {
                SeoEntityType::Post => ($post = Post::withTrashed()->find($this->entityId)) !== null
                    ? $registry->syncPost($post)
                    : null,
                SeoEntityType::Translation => ($tr = SeoContentTranslation::query()->find($this->entityId)) !== null
                    ? $registry->syncTranslation($tr)
                    : null,
                SeoEntityType::Community => ($comm = Community::withTrashed()->find($this->entityId)) !== null
                    ? $registry->syncCommunity($comm)
                    : null,
                SeoEntityType::Profile => ($user = User::withTrashed()->find($this->entityId)) !== null
                    ? $registry->syncProfile($user)
                    : null,
                SeoEntityType::Topic => ($tag = Hashtag::query()->find($this->entityId)) !== null
                    ? $registry->syncTopic($tag)
                    : null,
                SeoEntityType::Policy => ($policy = PolicyPage::query()->find($this->entityId)) !== null
                    ? $registry->syncPolicy($policy)
                    : null,
                default => null,
            };

            if ($entry !== null) {
                $indexNow->queueUrl($entry);
            }

            $this->finish($history, $started, $entry !== null ? 1 : 0, 0);
        } catch (\Throwable $e) {
            $this->finish($history, $started, 0, 1, $e->getMessage());
            throw $e;
        }
    }

    private function finish(SeoJobHistory $history, float $started, int $processed, int $errors, ?string $errorMessage = null): void
    {
        $history->forceFill([
            'status' => $errors > 0 ? 'failed' : 'completed',
            'processed_count' => $processed,
            'error_count' => $errors,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ])->save();
    }
}
