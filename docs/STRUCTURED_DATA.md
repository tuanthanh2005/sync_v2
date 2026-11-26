# Structured Data (JSON-LD) Policy & Schemas

## 1. Strictly Factual Schema Policy

SyncViet generates JSON-LD via `App\Services\Seo\StructuredDataService` and encodes it XSS-safely (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`) in `resources/views/app.blade.php`.

**Strictly Forbidden:**

- Fake `AggregateRating` or fabricated `Review` markup.
- Fabricated authors, dates, or engagement counts.
- `JobPosting` or `BroadcastEvent` abuse to gain Indexing API access.
- Exposing private user fields (`email`, phone numbers, private profiles, unpublished drafts).

## 2. Implemented Schema Graphs

| Page Type                                 | Schema Types                                           | Key Verified Fields                                                                                                                                                                                         |
| ----------------------------------------- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Home**                                  | `Organization`, `WebSite`                              | `name`, `url`, `logo` (`ImageObject` `1200x630`), `inLanguage`, `SearchAction` (`/search?q={search_term_string}`).                                                                                          |
| **Post (Short/Discussion)**               | `DiscussionForumPosting`, `BreadcrumbList`             | `headline`, `text`, `inLanguage`, `datePublished`, `dateModified`, `author` (`Person`), `publisher`, `InteractionCounter` (`LikeAction`, `CommentAction`), `ImageObject[]`, up to 5 public `Comment` nodes. |
| **Post (Long-form ≥ 450 chars w/ title)** | `Article`, `BreadcrumbList`                            | Same factual author, publisher, media, and engagement signals.                                                                                                                                              |
| **Community**                             | `CollectionPage`, `BreadcrumbList`                     | `name`, `description`, `url`, `inLanguage`, `isPartOf`.                                                                                                                                                     |
| **Public Profile**                        | `ProfilePage` (`mainEntity: Person`), `BreadcrumbList` | Public `name`, `@username`, optional bio/avatar — strictly zero email/private attributes.                                                                                                                   |
| **Topic Cluster**                         | `CollectionPage`, `BreadcrumbList`                     | `#topic`, `url`, `inLanguage`, `isPartOf`.                                                                                                                                                                  |
| **Published Policy**                      | `WebPage`, `BreadcrumbList`                            | Localized `title`, `url`, `dateModified`.                                                                                                                                                                   |
