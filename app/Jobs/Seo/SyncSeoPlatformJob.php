<?php

namespace App\Jobs\Seo;

use App\Models\SeoJobHistory;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\SitemapEngineService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSeoPlatformJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly bool $forceRebuildAll = false,
    ) {}

    public function uniqueId(): string
    {
        return 'seo:platform_sync:'.($this->forceRebuildAll ? 'all' : 'dirty');
    }

    public function handle(SitemapEngineService $sitemapEngine, IndexNowService $indexNow): void
    {
        $started = microtime(true);
        $history = SeoJobHistory::query()->create([
            'job_name' => 'SyncSeoPlatformJob',
            'queue' => $this->queue ?: 'default',
            'status' => 'running',
            'context' => ['force_rebuild_all' => $this->forceRebuildAll],
            'started_at' => now(),
        ]);

        try {
            $sitemapResult = $this->forceRebuildAll
                ? $sitemapEngine->rebuildAll()
                : $sitemapEngine->rebuildDirty();

            $indexNowResult = $indexNow->submitQueuedBatch(100);

            $history->forceFill([
                'status' => 'completed',
                'processed_count' => $sitemapResult['urls'] + $indexNowResult['submitted'],
                'error_count' => $indexNowResult['failed'],
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'context' => [
                    'sitemaps' => $sitemapResult,
                    'indexnow' => $indexNowResult,
                ],
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $history->forceFill([
                'status' => 'failed',
                'error_count' => 1,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
