# SyncViet International SEO Guide (15 Languages)

## 1. Dynamic 15-Language Support

SyncViet's International SEO Engine reads active languages dynamically from the `languages` table (`LanguageService::enabledCodes()`), supporting all 15 configured languages:
`en` (default/fallback), `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` (full RTL `dir="rtl"`), `tr`.

## 2. Clean URL Strategy & Legacy Compatibility

All existing legacy routes remain 100% active without breaking links:

- `/posts/{post}`
- `/communities/{community:slug}`
- `/u/{user:username}`
- `/topics`, `/topics/{slug}`

Localized international SEO routes provide distinct crawlable URLs per published language:

- `/{locale}/posts/{postId}-{slug}`
- `/{locale}/topics` and `/{locale}/topics/{slug}`
- `/{locale}/communities/{community:slug}`

## 3. Language Signals & Crawler Rules

- **Original content language vs UI locale**: `LanguageDetectionService` detects the natural language of each post (`posts.language_code`) using Unicode script ranges and diacritic/token heuristics so English, Vietnamese, Japanese, Arabic, etc. posts are bucketed into their true language sitemap (`sitemap-posts-{locale}-0001.xml`).
- **No IP-based bot redirection**: `SetLocale` respects explicit URL prefixes (`/{locale}/...`) and never redirects Googlebot or Bingbot by GeoIP.
- **No empty doorway locales**: Requesting `/{locale}/posts/{id}` for a locale that does **not** have an approved, published `seo_content_translations` row cleanly `301`-redirects to the original canonical post URL rather than creating duplicate thin pages across 15 locales.
