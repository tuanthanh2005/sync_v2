# SyncViet Sharded Dynamic XML Sitemap Engine

## 1. Architecture & Memory Safety

Designed to scale to millions of URLs while running comfortably inside Hostinger Shared Hosting memory limits (`3 GB RAM`):

- Reads `seo_url_registry` strictly via `eligibleForSitemap()` (`index_eligibility = eligible`, `http_status = 200`, `is_noindex_forced = false`).
- Streams rows with `chunkById(500)` into `5,000`-URL shards (`SEO_SITEMAP_SHARD_SIZE`).
- Writes XML files atomically to `storage/app/seo/sitemaps/` (write temp file + atomic move) so crawlers never read truncated XML.
- Serves `ETag` and `Last-Modified` headers with full `304 Not Modified` handling and `Cache-Control: public, max-age=900, stale-while-revalidate=3600`.

## 2. Sitemap Index & Shards

`GET /sitemap.xml` serves a dynamic `<sitemapindex>` referencing only active non-empty shards:

- `/sitemap-pages.xml` — Core static public pages and published policy pages.
- `/sitemap-posts-{locale}-0001.xml` — Original posts and approved published translations per enabled locale.
- `/sitemap-communities-0001.xml` — Active public communities.
- `/sitemap-profiles-0001.xml` — Active, non-private user profiles with bio or public posts.
- `/sitemap-topics-{locale}-0001.xml` — Topic clusters meeting the minimum public post threshold.
- `/sitemap-images-0001.xml` — Google Image Sitemap (`xmlns:image`) for eligible public posts containing media.

## 3. Incremental Dirty-Shard Rebuilding

When a post/community/policy/translation changes, `SeoRegistryService` marks only the affected sitemap group `is_dirty = true`. Every 5 minutes, `php artisan app:seo:sync` rebuilds only dirty shards and updates `/sitemap.xml`. Full rebuilds can also be triggered anytime via `php artisan app:seo:backfill` or Admin → SEO Center → URLs & Sitemaps.
