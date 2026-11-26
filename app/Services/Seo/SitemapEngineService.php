<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Enums\SeoUrlStatus;
use App\Models\Post;
use App\Models\SeoSitemap;
use App\Models\SeoSitemapShard;
use App\Models\SeoUrlRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class SitemapEngineService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
    ) {}

    /**
     * Rebuild only dirty sitemaps (fast incremental Hostinger-friendly sync).
     *
     * @return array{sitemaps: int, shards: int, urls: int}
     */
    public function rebuildDirty(): array
    {
        $dirty = SeoSitemap::query()->where('is_dirty', true)->get();

        if ($dirty->isEmpty()) {
            return ['sitemaps' => 0, 'shards' => 0, 'urls' => 0];
        }

        $sitemaps = 0;
        $shards = 0;
        $urls = 0;

        foreach ($dirty as $sitemap) {
            $res = $this->rebuildSitemap($sitemap);
            $sitemaps++;
            $shards += $res['shards'];
            $urls += $res['urls'];
        }

        $this->writeSitemapIndexFile();

        return ['sitemaps' => $sitemaps, 'shards' => $shards, 'urls' => $urls];
    }

    /**
     * Rebuild all sitemap definitions dynamically from enabled platform languages + global entities.
     *
     * @return array{sitemaps: int, shards: int, urls: int}
     */
    public function rebuildAll(): array
    {
        $enabledLocales = $this->config->enabledLocales();

        $definitions = [
            ['name' => 'sitemap-pages', 'type' => 'pages', 'locale' => null],
            ['name' => 'sitemap-communities', 'type' => 'communities', 'locale' => null],
            ['name' => 'sitemap-profiles', 'type' => 'profiles', 'locale' => null],
            ['name' => 'sitemap-images', 'type' => 'images', 'locale' => null],
        ];

        foreach ($enabledLocales as $locale) {
            $definitions[] = ['name' => "sitemap-posts-{$locale}", 'type' => 'posts', 'locale' => $locale];
            $definitions[] = ['name' => "sitemap-topics-{$locale}", 'type' => 'topics', 'locale' => $locale];
        }

        $sitemaps = 0;
        $shards = 0;
        $urls = 0;

        foreach ($definitions as $def) {
            $sitemap = SeoSitemap::query()->updateOrCreate(
                ['name' => $def['name']],
                ['type' => $def['type'], 'locale' => $def['locale'], 'is_dirty' => true],
            );

            $res = $this->rebuildSitemap($sitemap);
            $sitemaps++;
            $shards += $res['shards'];
            $urls += $res['urls'];
        }

        $this->writeSitemapIndexFile();

        return ['sitemaps' => $sitemaps, 'shards' => $shards, 'urls' => $urls];
    }

    /**
     * @return array{shards: int, urls: int}
     */
    public function rebuildSitemap(SeoSitemap $sitemap): array
    {
        if ($sitemap->type === 'images') {
            return $this->rebuildImageSitemap($sitemap);
        }

        $shardSize = $this->config->sitemapShardSize();
        $query = $this->registryQueryForSitemap($sitemap);

        $shardIndex = 1;
        /** @var list<array{id: int, loc: string, lastmod: CarbonInterface|null}> $currentItems */
        $currentItems = [];
        /** @var list<string> $activeShardKeys */
        $activeShardKeys = [];
        $totalUrls = 0;

        $query->orderBy('id')->chunkById(500, function ($rows) use ($sitemap, $shardSize, &$currentItems, &$shardIndex, &$activeShardKeys, &$totalUrls): void {
            foreach ($rows as $row) {
                $currentItems[] = [
                    'id' => $row->id,
                    'loc' => $row->canonical_url ?: $row->url,
                    'lastmod' => $row->lastmod_at ?? $row->updated_at,
                ];

                if (count($currentItems) >= $shardSize) {
                    $activeShardKeys[] = $this->persistUrlShard($sitemap, $shardIndex, $currentItems);
                    $totalUrls += count($currentItems);
                    $currentItems = [];
                    $shardIndex++;
                }
            }
        });

        if ($currentItems !== []) {
            $activeShardKeys[] = $this->persistUrlShard($sitemap, $shardIndex, $currentItems);
            $totalUrls += count($currentItems);
        }

        $this->cleanupObsoleteShards($sitemap, $activeShardKeys);

        $sitemap->forceFill([
            'total_urls' => $totalUrls,
            'total_shards' => count($activeShardKeys),
            'status' => 'ready',
            'is_dirty' => false,
            'etag' => hash('sha256', implode('|', $activeShardKeys).':'.$totalUrls.':'.now()->timestamp),
            'last_generated_at' => now(),
            'last_error' => null,
        ])->save();

        return ['shards' => count($activeShardKeys), 'urls' => $totalUrls];
    }

    /**
     * Render `/sitemap.xml` (Sitemap Index) with ETag & Last-Modified.
     *
     * @return array{xml: string, etag: string, lastmod: string}
     */
    public function renderIndexXml(): array
    {
        $indexPath = $this->storageDir().'/sitemap-index.xml';
        if (! Storage::disk('local')->exists($indexPath)) {
            $this->writeSitemapIndexFile();
        }

        $xml = (string) Storage::disk('local')->get($indexPath);
        if (trim($xml) === '') {
            $xml = $this->buildSitemapIndexXml();
        }

        $latest = SeoSitemapShard::query()->max('lastmod_at');
        $ts = is_string($latest) && $latest !== '' ? strtotime($latest) : false;
        $lastmod = gmdate('D, d M Y H:i:s', is_int($ts) ? $ts : time()).' GMT';

        return [
            'xml' => $xml,
            'etag' => '"'.hash('sha256', $xml).'"',
            'lastmod' => $lastmod,
        ];
    }

    /**
     * Render an individual sitemap shard `/sitemap-{key}.xml` safely.
     *
     * @return array{xml: string, etag: string, lastmod: string}|null
     */
    public function renderShardXml(string $shardKey): ?array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{3,100}$/', $shardKey) !== 1) {
            return null;
        }

        /** @var SeoSitemapShard|null $shard */
        $shard = SeoSitemapShard::query()->where('shard_key', $shardKey)->first();
        if ($shard === null) {
            return null;
        }

        if ($shard->is_dirty || ! Storage::disk('local')->exists($shard->storage_path)) {
            $sitemap = $shard->sitemap;
            if ($sitemap !== null) {
                $this->rebuildSitemap($sitemap);
                $shard = SeoSitemapShard::query()->where('shard_key', $shardKey)->first();
            }
        }

        if ($shard === null || ! Storage::disk('local')->exists($shard->storage_path)) {
            return null;
        }

        $xml = (string) Storage::disk('local')->get($shard->storage_path);
        $lastmod = ($shard->lastmod_at ?? $shard->generated_at ?? now())->toRfc7231String();

        return [
            'xml' => $xml,
            'etag' => '"'.($shard->etag ?: hash('sha256', $xml)).'"',
            'lastmod' => $lastmod,
        ];
    }

    /**
     * @param  list<array{id: int, loc: string, lastmod: CarbonInterface|null}>  $items
     */
    private function persistUrlShard(SeoSitemap $sitemap, int $shardIndex, array $items): string
    {
        $shardKey = $this->formatShardKey($sitemap, $shardIndex);
        $xml = $this->buildUrlSetXml($items);
        $storagePath = $this->storageDir().'/'.$shardKey.'.xml';
        $this->atomicWrite($storagePath, $xml);

        $etag = hash('sha256', $xml);
        $latestMod = null;
        $ids = [];

        foreach ($items as $row) {
            $ids[] = $row['id'];
            if ($row['lastmod'] !== null && ($latestMod === null || $row['lastmod']->greaterThan($latestMod))) {
                $latestMod = $row['lastmod'];
            }
        }

        SeoSitemapShard::query()->updateOrCreate(
            ['shard_key' => $shardKey],
            [
                'sitemap_id' => $sitemap->id,
                'shard_index' => $shardIndex,
                'storage_path' => $storagePath,
                'url_count' => count($items),
                'byte_size' => strlen($xml),
                'etag' => $etag,
                'is_dirty' => false,
                'generated_at' => now(),
                'lastmod_at' => $latestMod ?? now(),
            ],
        );

        SeoUrlRegistry::query()
            ->whereIn('id', $ids)
            ->update([
                'is_in_sitemap' => true,
                'sitemap_shard_key' => $shardKey,
            ]);

        SeoUrlRegistry::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [SeoUrlStatus::Eligible->value, SeoUrlStatus::PendingSitemapUpdate->value])
            ->update(['status' => SeoUrlStatus::InSitemap->value]);

        return $shardKey;
    }

    /**
     * @param  list<array{loc: string, images: list<array{loc: string, title: string}>}>  $entries
     */
    private function persistImageShard(SeoSitemap $sitemap, int $shardIndex, array $entries): string
    {
        $shardKey = $this->formatShardKey($sitemap, $shardIndex);
        $xml = $this->buildImageSetXml($entries);
        $storagePath = $this->storageDir().'/'.$shardKey.'.xml';
        $this->atomicWrite($storagePath, $xml);

        SeoSitemapShard::query()->updateOrCreate(
            ['shard_key' => $shardKey],
            [
                'sitemap_id' => $sitemap->id,
                'shard_index' => $shardIndex,
                'storage_path' => $storagePath,
                'url_count' => count($entries),
                'byte_size' => strlen($xml),
                'etag' => hash('sha256', $xml),
                'is_dirty' => false,
                'generated_at' => now(),
                'lastmod_at' => now(),
            ],
        );

        return $shardKey;
    }

    /**
     * @param  list<string>  $activeShardKeys
     */
    private function cleanupObsoleteShards(SeoSitemap $sitemap, array $activeShardKeys): void
    {
        $obsoleteShards = SeoSitemapShard::query()
            ->where('sitemap_id', $sitemap->id)
            ->when($activeShardKeys !== [], static fn ($q) => $q->whereNotIn('shard_key', $activeShardKeys))
            ->get();

        foreach ($obsoleteShards as $obsolete) {
            Storage::disk('local')->delete($obsolete->storage_path);
            $obsolete->delete();
        }
    }

    /**
     * @return array{shards: int, urls: int}
     */
    private function rebuildImageSitemap(SeoSitemap $sitemap): array
    {
        $shardSize = $this->config->sitemapShardSize();
        $shardIndex = 1;
        /** @var list<array{loc: string, images: list<array{loc: string, title: string}>}> $entries */
        $entries = [];
        /** @var list<string> $activeShardKeys */
        $activeShardKeys = [];
        $totalUrls = 0;

        SeoUrlRegistry::query()
            ->eligibleForSitemap()
            ->where('entity_type', SeoEntityType::Post->value)
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($sitemap, $shardSize, &$entries, &$shardIndex, &$activeShardKeys, &$totalUrls): void {
                $postIds = $rows->pluck('entity_id')->filter()->map(static fn (mixed $id): int => (int) $id)->all();
                if ($postIds === []) {
                    return;
                }

                $posts = Post::query()->with('media')->whereIn('id', $postIds)->get()->keyBy('id');

                foreach ($rows as $row) {
                    $post = $posts->get($row->entity_id);
                    if ($post === null || $post->media->isEmpty()) {
                        continue;
                    }

                    $images = [];
                    foreach ($post->media as $media) {
                        $url = $media->url();
                        if ($url !== '') {
                            $images[] = [
                                'loc' => str_starts_with($url, 'http') ? $url : $this->canonicalUrls->absoluteUrl($url),
                                'title' => $post->title ?: 'Post image',
                            ];
                        }
                    }

                    if ($images !== []) {
                        $entries[] = [
                            'loc' => $row->canonical_url ?: $row->url,
                            'images' => $images,
                        ];
                    }

                    if (count($entries) >= $shardSize) {
                        $activeShardKeys[] = $this->persistImageShard($sitemap, $shardIndex, $entries);
                        $totalUrls += count($entries);
                        $entries = [];
                        $shardIndex++;
                    }
                }
            });

        if ($entries !== []) {
            $activeShardKeys[] = $this->persistImageShard($sitemap, $shardIndex, $entries);
            $totalUrls += count($entries);
        }

        $this->cleanupObsoleteShards($sitemap, $activeShardKeys);

        $sitemap->forceFill([
            'total_urls' => $totalUrls,
            'total_shards' => count($activeShardKeys),
            'status' => 'ready',
            'is_dirty' => false,
            'last_generated_at' => now(),
            'last_error' => null,
        ])->save();

        return ['shards' => count($activeShardKeys), 'urls' => $totalUrls];
    }

    /**
     * @return Builder<SeoUrlRegistry>
     */
    private function registryQueryForSitemap(SeoSitemap $sitemap): Builder
    {
        $query = SeoUrlRegistry::query()->eligibleForSitemap();

        return match ($sitemap->type) {
            'pages' => $query->whereIn('entity_type', [SeoEntityType::Page->value, SeoEntityType::Policy->value]),
            'posts' => $query
                ->whereIn('entity_type', [SeoEntityType::Post->value, SeoEntityType::Translation->value])
                ->where('locale', $sitemap->locale ?: $this->config->defaultLocale()),
            'communities' => $query->where('entity_type', SeoEntityType::Community->value),
            'profiles' => $query->where('entity_type', SeoEntityType::Profile->value),
            'topics' => $query
                ->where('entity_type', SeoEntityType::Topic->value)
                ->where('locale', $sitemap->locale ?: $this->config->defaultLocale()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function formatShardKey(SeoSitemap $sitemap, int $index): string
    {
        $suffix = str_pad((string) $index, 4, '0', STR_PAD_LEFT);

        if ($sitemap->type === 'pages' && $index === 1) {
            return 'sitemap-pages';
        }

        return "{$sitemap->name}-{$suffix}";
    }

    private function writeSitemapIndexFile(): void
    {
        $xml = $this->buildSitemapIndexXml();
        $this->atomicWrite($this->storageDir().'/sitemap-index.xml', $xml);
    }

    private function buildSitemapIndexXml(): string
    {
        $shards = SeoSitemapShard::query()
            ->where('url_count', '>', 0)
            ->orderBy('shard_key')
            ->get();

        $baseUrl = $this->config->canonicalBaseUrl();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($shards as $shard) {
            $loc = htmlspecialchars($baseUrl.'/'.$shard->shard_key.'.xml', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lastmod = ($shard->lastmod_at ?? $shard->generated_at ?? now())->toAtomString();
            $lines[] = '  <sitemap>';
            $lines[] = "    <loc>{$loc}</loc>";
            $lines[] = "    <lastmod>{$lastmod}</lastmod>";
            $lines[] = '  </sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{id: int, loc: string, lastmod: CarbonInterface|null}>  $items
     */
    private function buildUrlSetXml(array $items): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($items as $item) {
            $loc = htmlspecialchars($item['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lastmod = ($item['lastmod'] ?? now())->toAtomString();
            $lines[] = '  <url>';
            $lines[] = "    <loc>{$loc}</loc>";
            $lines[] = "    <lastmod>{$lastmod}</lastmod>";
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{loc: string, images: list<array{loc: string, title: string}>}>  $entries
     */
    private function buildImageSetXml(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
        ];

        foreach ($entries as $entry) {
            $loc = htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lines[] = '  <url>';
            $lines[] = "    <loc>{$loc}</loc>";
            foreach ($entry['images'] as $image) {
                $imgLoc = htmlspecialchars($image['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $imgTitle = htmlspecialchars($image['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $lines[] = '    <image:image>';
                $lines[] = "      <image:loc>{$imgLoc}</image:loc>";
                $lines[] = "      <image:title>{$imgTitle}</image:title>";
                $lines[] = '    </image:image>';
            }
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    private function storageDir(): string
    {
        return trim((string) config('syncviet.seo.sitemap_storage_dir', 'seo/sitemaps'), '/');
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $disk = Storage::disk('local');
        $tmpPath = $path.'.tmp.'.bin2hex(random_bytes(4));
        $disk->put($tmpPath, $contents);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        $disk->move($tmpPath, $path);
    }
}
