# SEO Quality Policy & Anti-Spam Gate

## 1. Core Principle

Organic search trust is protected by indexing only high-value, public, moderated content. **SyncViet Premium membership and Boost Post NEVER bypass `SeoQualityGateService`.**

## 2. Index Eligibility Rules

- **Posts**: Must be `visibility = public`, `moderation_status = approved`, `is_spam = false`, `seo_noindex = false`, `deleted_at IS NULL`, active author, public active community (if posted in a community), not duplicate of an earlier `content_fingerprint`, not stuffed with excessive external URLs, and meet configurable minimum content depth (`min_post_words = 12` or `min_post_chars = 60`, or substantial media with >= 25 chars caption). Thin posts enter `pending_quality_review` with `noindex, follow`.
- **Topics (`/topics/{slug}`)**: Must not be restricted or spam, and must have at least `min_topic_posts` (default `2`) public approved posts to avoid empty doorway pages.
- **Profiles (`/u/{username}`)**: Must be active, non-private, and contain a bio (>= 15 chars) or at least 1 public post.
- **Policies**: Only `status = published` and `is_visible = true` policies are indexable; drafts return `404`.

## 3. UGC Link & Spam Protection

- All user-submitted external link previews render with `rel="ugc nofollow noopener noreferrer"`.
- Paid Banner & Boost ads strictly preserve sponsored separation and never manipulate organic search metadata or Trending scores.
- Account `trust_level` and post `is_ai_assisted` / `content_fingerprint` columns provide built-in anti-spam & future AI Content Center governance.
