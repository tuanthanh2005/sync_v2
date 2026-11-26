# SyncViet Controlled Local Load Benchmark & Capacity Notes (PHẦN 32 & 33)

> **HONEST MEASUREMENT DISCLAIMER**: All benchmarks below were executed strictly in a **Local Development Lab** (`Windows`, single-process `php artisan serve` on PHP `8.4.28` **without PHP-FPM / without OPcache**, connected to local MySQL `8.4.3` with `config:cache`, `route:cache`, and `view:cache` enabled). **Production Hostinger Shared Hosting was NOT stress-tested** (per PHẦN 2 & PHẦN 32 safety rules). Do NOT equate these single-worker Windows lab metrics with production multi-worker Hostinger capacity.

## 1. Single-Request Local Baseline (After AUD-016 Optimization)

| Endpoint             | HTTP Status | Content-Type                     | Response Size |       Local CLI Latency (No OPcache)        |
| :------------------- | :---------: | :------------------------------- | :-----------: | :-----------------------------------------: |
| `/`                  |    `200`    | `text/html; charset=utf-8`       |  `25,739 B`   |                 `465.3 ms`                  |
| `/services`          |    `200`    | `text/html; charset=utf-8`       |  `31,024 B`   |                 `497.1 ms`                  |
| `/premium`           |    `200`    | `text/html; charset=utf-8`       |  `25,489 B`   |                 `419.1 ms`                  |
| `/communities`       |    `200`    | `text/html; charset=utf-8`       |  `22,796 B`   |                 `426.1 ms`                  |
| `/search?q=syncviet` |    `200`    | `text/html; charset=utf-8`       |  `22,605 B`   |    `465.4 ms` (down from `1,030.7 ms`!)     |
| `/robots.txt`        |    `200`    | `text/plain; charset=UTF-8`      |    `78 B`     |        `389.5 ms` (`12.1 ms` in-app)        |
| `/sitemap.xml`       |    `200`    | `application/xml; charset=UTF-8` |    `251 B`    |        `425.0 ms` (`22.8 ms` in-app)        |
| `/up`                |    `200`    | `text/html; charset=utf-8`       |   `1,830 B`   | `266.8 ms` (framework cold bootstrap floor) |

## 2. Progressive Concurrency Tiers (Single-Worker Windows Lab)

Request Mix: Round-robin across all 8 public endpoints above (`/` through `/up`), 6s sustained per tier:

| Virtual Users (VUs) | Total Requests | Measured RPS (1 PHP Worker, No OPcache) | 5xx / Connection Errors | Error Rate | Avg Latency  |     P50      |      P95      |      P99      |
| :-----------------: | :------------: | :-------------------------------------: | :---------------------: | :--------: | :----------: | :----------: | :-----------: | :-----------: |
|      **5 VUs**      |       19       |              `2.39 req/s`               |          **0**          | **0.00%**  | `1,877.6 ms` | `1,975.2 ms` | `2,375.6 ms`  | `2,375.6 ms`  |
|     **10 VUs**      |       24       |              `2.46 req/s`               |          **0**          | **0.00%**  | `3,349.6 ms` | `3,993.3 ms` | `4,183.7 ms`  | `4,193.2 ms`  |
|     **25 VUs**      |       39       |              `2.40 req/s`               |          **0**          | **0.00%**  | `7,203.5 ms` | `8,338.3 ms` | `10,346.5 ms` | `10,405.9 ms` |

## 3. Bottleneck Analysis & Safe Operating Interpretation

1. **Why 5/10/25 VUs queued linearly in the local lab**: Windows `php artisan serve` executes **1 single PHP worker without OPcache** (~`267 ms` per cold request bootstrap even on `/up`). Therefore throughput saturates at ~`2.4 RPS` on 1 non-OPcache worker, and 25 concurrent requests queue behind that single process — yet produced **0.00% errors (`0` 5xx, `0` failed connections, `0` DB deadlocks)**.
2. **Remaining Optimization Opportunities Identified**: `/services` (`54` queries), `/premium` (`45` queries), and `/advertising` (`47` queries) call `MonetizationSeeder::seedDefaultsIfEmpty()` / per-placement active counts on each visit; adding short-TTL (60s) caching on `publicPlans()` and `publicPlacements()` is a clean Stage-A optimization if monetization traffic grows.
3. **Production Staging Load Test Plan (Not Yet Run on Hostinger)**:
    - On Linux Hostinger LiteSpeed/PHP-FPM with **OPcache enabled** and multiple PHP workers, per-request PHP bootstrap drops dramatically (`~15–40 ms`), and Cloudflare CDN absorbs static assets (`/build/assets/*`, fonts, images).
    - **Recommended Verification**: Run a bounded k6 test (`5 -> 10 -> 25 VUs`, stopping immediately if hPanel CPU > 70% or PHP Workers > 50% or 5xx > 1%) ONLY on an approved staging copy so the co-hosted AI store website is never impacted.
    - **Capacity Claim**: We do **NOT** claim 10,000 concurrent users; verified safe baseline is **0% errors under 25 concurrent requests on a single un-cached worker**, suitable for the target initial launch cohort (**1–1,000 real members**) when paired with Cloudflare caching and hPanel monitoring.
