<?php

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

class OrphanMediaCleanupService
{
    /**
     * Remove unreferenced image files stored inside the post media directory older than $minAgeHours.
     * Preserves all files referenced by active or recoverable soft-deleted PostMedia records.
     *
     * @return array{scanned: int, deleted: int, disk: string}
     */
    public function pruneOrphanPostMedia(int $minAgeHours = 24, string $directory = 'posts'): array
    {
        $diskName = (string) config('syncviet.media_disk', 'public');
        $disk = Storage::disk($diskName);
        $cleanDir = trim($directory, '/');

        if (! $disk->exists($cleanDir)) {
            return ['scanned' => 0, 'deleted' => 0, 'disk' => $diskName];
        }

        $referencedPaths = PostMedia::query()
            ->where('disk', $diskName)
            ->pluck('path')
            ->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')
            ->map(static fn (string $path): string => ltrim($path, '/'))
            ->flip()
            ->all();

        $cutoffTimestamp = now()->subHours(max(0, $minAgeHours))->getTimestamp();
        $files = $disk->allFiles($cleanDir);
        $scanned = 0;
        $deleted = 0;

        foreach ($files as $relativePath) {
            $normalized = ltrim((string) $relativePath, '/');
            $scanned++;

            if (isset($referencedPaths[$normalized])) {
                continue;
            }

            // Keep thumbnail twin if its canonical original PostMedia path is referenced.
            $parentOriginal = str_replace('/thumbs/', '/', $normalized);
            if ($parentOriginal !== $normalized && isset($referencedPaths[$parentOriginal])) {
                continue;
            }

            try {
                $lastModified = $disk->lastModified($normalized);
            } catch (\Throwable) {
                continue;
            }

            if ($minAgeHours > 0 && $lastModified > $cutoffTimestamp) {
                continue;
            }

            if ($disk->delete($normalized)) {
                $deleted++;
            }
        }

        return [
            'scanned' => $scanned,
            'deleted' => $deleted,
            'disk' => $diskName,
        ];
    }
}
