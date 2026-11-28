<?php

namespace App\Jobs\Social;

use App\Services\Social\TrendingRankingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateTrendingScoresJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 25;

    public int $uniqueFor = 240;

    public function __construct(
        public int $batchSize = 250,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'recalculate-trending-scores';
    }

    public function handle(TrendingRankingService $rankingService): void
    {
        $rankingService->recalculateCandidateWindow($this->batchSize);
    }
}
