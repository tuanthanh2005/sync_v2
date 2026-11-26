# Index Monitoring & URL Inspection Queue

## 1. Transparent Status Taxonomy

`seo_url_registry` enforces an explicit, unambiguous lifecycle taxonomy:

- `eligible` — Passes Quality Gate and awaiting sitemap/inspection.
- `excluded` — Excluded by privacy, moderation, spam, duplicate, deletion, or Admin forced noindex.
- `in_sitemap` — Included in a generated XML sitemap shard.
- `pending_sitemap_update` — Eligible entity created/updated; shard flagged dirty.
- `pending_inspection` — Prioritized for Google Search Console URL Inspection API.
- `indexed` — Verified `PASS` by Google Search Console URL Inspection API.
- `not_indexed` — Inspected (`FAIL`, `PARTIAL`, or `NEUTRAL`); `inspection_coverage_state` records exact Google reason.
- `unknown` — Verdict unspecified or not yet inspected.
- `error` — API error recorded in `seo_url_inspections`.

## 2. Quota Governance & Priority Queueing

- Daily budget controlled via `gsc_daily_inspection_limit` (default `1,800/day`, hard-capped below Google's 2,000/day per-property quota).
- Tracked per day in `seo_api_usage` (`provider=google_search_console`, `endpoint=url_inspection`).
- `GoogleSearchConsoleService::inspectPriorityBatch()` prioritizes eligible URLs by `priority_score DESC`, skipping URLs inspected within the last 7 days unless explicitly queued (`pending_inspection`), and stops immediately on HTTP `429`.
