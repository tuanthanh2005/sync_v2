<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Enums\SeoIndexEligibility;
use App\Enums\SeoUrlStatus;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\SeoSitemap;
use App\Models\SeoUrlRegistry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SeoRegistryService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly SeoQualityGateService $qualityGate,
        private readonly LanguageDetectionService $languageDetection,
    ) {}

    public function syncPost(Post $post): SeoUrlRegistry
    {
        $post->loadMissing(['author', 'community', 'media']);

        $plainText = trim(strip_tags(trim((string) ($post->title ?? '')).' '.$post->content));
        $fingerprint = $this->qualityGate->contentFingerprint($plainText);
        $slug = $this->canonicalUrls->buildPostSlug($post);
        $locale = trim($post->language_code) !== ''
            ? $post->language_code
            : $this->languageDetection->detect($plainText, app()->getLocale());

        $previousFingerprint = $post->content_fingerprint;

        $eval = $this->qualityGate->evaluatePost($post);

        // Persist computed SEO columns silently without firing infinite model events
        if (
            $post->slug !== $slug
            || $post->language_code !== $locale
            || $post->content_fingerprint !== $fingerprint
            || $post->seo_quality_score !== $eval['quality_score']
        ) {
            Post::withoutEvents(static function () use ($post, $slug, $locale, $fingerprint, $eval): void {
                $post->forceFill([
                    'slug' => $slug,
                    'language_code' => $locale,
                    'content_fingerprint' => $fingerprint,
                    'seo_quality_score' => $eval['quality_score'],
                ])->saveQuietly();
            });
        }

        // Mark existing translations stale when source post content changes
        if ($previousFingerprint !== null && $previousFingerprint !== $fingerprint) {
            SeoContentTranslation::query()
                ->where('entity_type', SeoEntityType::Post->value)
                ->where('entity_id', $post->id)
                ->where('source_content_hash', '!=', $fingerprint)
                ->update(['is_stale' => true]);
        }

        $path = $this->canonicalUrls->postPath($post, $locale, $slug);
        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);
        $isEligible = $eval['eligibility'] === SeoIndexEligibility::Eligible;

        $httpStatus = $post->deleted_at !== null
            ? 410
            : ($eval['reason'] === 'non_public_visibility' || $eval['reason'] === 'private_or_inactive_community' ? 403 : 200);

        $priority = $isEligible ? min(100, max(30, $eval['quality_score'])) : 10;

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Post,
            'entity_id' => $post->id,
            'locale' => $locale,
            'source_locale' => $locale,
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => $httpStatus,
            'priority_score' => $priority,
            'quality_score' => $eval['quality_score'],
            'content_hash' => $fingerprint,
            'lastmod_at' => $post->updated_at ?? $post->published_at ?? now(),
            'is_noindex_forced' => (bool) $post->seo_noindex,
            'metadata_snapshot' => [
                'title' => $post->title,
                'media_count' => $post->media->count(),
                'community_id' => $post->community_id,
            ],
        ]);

        // Also re-evaluate published translations if post became ineligible or deleted
        if (! $isEligible) {
            $translations = SeoContentTranslation::query()
                ->where('entity_type', SeoEntityType::Post->value)
                ->where('entity_id', $post->id)
                ->get();
            foreach ($translations as $translation) {
                $this->syncTranslation($translation, $post);
            }
        }

        $this->markSitemapDirty('posts', $locale);
        if ($post->media->isNotEmpty()) {
            $this->markSitemapDirty('images', null);
        }

        return $registry;
    }

    public function syncTranslation(SeoContentTranslation $translation, ?Post $sourcePost = null): SeoUrlRegistry
    {
        if ($sourcePost === null && $translation->entity_type === SeoEntityType::Post->value) {
            $sourcePost = Post::withTrashed()->find($translation->entity_id);
        }

        $eval = $this->qualityGate->evaluateTranslation($translation, $sourcePost);
        $path = $sourcePost !== null
            ? $this->canonicalUrls->postPath($sourcePost, $translation->target_locale, $translation->localized_slug)
            : '/'.$translation->target_locale.'/posts/'.$translation->entity_id.'-'.$translation->localized_slug;

        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);
        $hash = $this->qualityGate->contentFingerprint(
            trim((string) ($translation->translated_title ?? '')).' '.$translation->translated_body,
        );

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Translation,
            'entity_id' => $translation->id,
            'locale' => $translation->target_locale,
            'source_locale' => $translation->original_language,
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => $sourcePost !== null && $sourcePost->deleted_at !== null ? 410 : 200,
            'priority_score' => $eval['eligibility'] === SeoIndexEligibility::Eligible ? 75 : 10,
            'quality_score' => $eval['quality_score'],
            'content_hash' => $hash,
            'lastmod_at' => $translation->updated_at ?? $translation->published_at ?? now(),
            'is_noindex_forced' => false,
            'metadata_snapshot' => [
                'source_entity_type' => $translation->entity_type,
                'source_entity_id' => $translation->entity_id,
                'title' => $translation->translated_title,
            ],
        ]);

        $this->markSitemapDirty('posts', $translation->target_locale);

        return $registry;
    }

    public function syncCommunity(Community $community): SeoUrlRegistry
    {
        $eval = $this->qualityGate->evaluateCommunity($community);
        $locale = $community->language_code ?: $this->config->defaultLocale();
        $path = $this->canonicalUrls->communityPath($community);
        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Community,
            'entity_id' => $community->id,
            'locale' => $locale,
            'source_locale' => $locale,
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => $community->deleted_at !== null ? 410 : 200,
            'priority_score' => $eval['eligibility'] === SeoIndexEligibility::Eligible ? 80 : 10,
            'quality_score' => $eval['quality_score'],
            'content_hash' => $this->qualityGate->contentFingerprint($community->name.' '.(string) ($community->description ?? '')),
            'lastmod_at' => $community->updated_at ?? now(),
            'is_noindex_forced' => (bool) $community->seo_noindex,
            'metadata_snapshot' => ['name' => $community->name, 'slug' => $community->slug],
        ]);

        $this->markSitemapDirty('communities', null);

        return $registry;
    }

    public function syncProfile(User $user): SeoUrlRegistry
    {
        $eval = $this->qualityGate->evaluateProfile($user);
        $path = $this->canonicalUrls->profilePath($user);
        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Profile,
            'entity_id' => $user->id,
            'locale' => $user->preferred_locale ?: $this->config->defaultLocale(),
            'source_locale' => $user->preferred_locale ?: $this->config->defaultLocale(),
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => $user->deleted_at !== null ? 410 : 200,
            'priority_score' => $eval['eligibility'] === SeoIndexEligibility::Eligible ? 55 : 10,
            'quality_score' => $eval['quality_score'],
            'content_hash' => $this->qualityGate->contentFingerprint(
                $user->name.' '.$user->username.' '.($user->profile !== null ? (string) ($user->profile->bio ?? '') : ''),
            ),
            'lastmod_at' => $user->updated_at ?? now(),
            'is_noindex_forced' => false,
            'metadata_snapshot' => ['username' => $user->username, 'name' => $user->name],
        ]);

        $this->markSitemapDirty('profiles', null);

        return $registry;
    }

    public function syncTopic(Hashtag $hashtag, ?string $locale = null): SeoUrlRegistry
    {
        $lang = $locale ?: $this->config->defaultLocale();
        $eval = $this->qualityGate->evaluateTopic($hashtag);
        $path = $this->canonicalUrls->topicPath($hashtag, $lang);
        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Topic,
            'entity_id' => $hashtag->id,
            'locale' => $lang,
            'source_locale' => $lang,
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => 200,
            'priority_score' => $eval['eligibility'] === SeoIndexEligibility::Eligible ? 70 : 10,
            'quality_score' => $eval['quality_score'],
            'content_hash' => hash('sha256', $hashtag->slug.':'.$hashtag->posts_count),
            'lastmod_at' => $hashtag->updated_at ?? now(),
            'is_noindex_forced' => false,
            'metadata_snapshot' => ['name' => $hashtag->name, 'posts_count' => $hashtag->posts_count],
        ]);

        $this->markSitemapDirty('topics', $lang);

        return $registry;
    }

    public function syncPolicy(PolicyPage $policy): SeoUrlRegistry
    {
        $eval = $this->qualityGate->evaluatePolicy($policy);
        $path = '/policies/'.$policy->slug;
        $canonicalUrl = $this->canonicalUrls->absoluteUrl($path);
        $titleEn = $policy->titleForLocale('en');
        $contentEn = (string) ($policy->contentForLocale('en') ?? '');

        $registry = $this->upsertEntry([
            'url' => $canonicalUrl,
            'path' => $path,
            'entity_type' => SeoEntityType::Policy,
            'entity_id' => $policy->id,
            'locale' => $this->config->defaultLocale(),
            'source_locale' => $this->config->defaultLocale(),
            'canonical_url' => $canonicalUrl,
            'index_eligibility' => $eval['eligibility'],
            'exclusion_reason' => $eval['reason'],
            'http_status' => $policy->isPubliclyAccessible() ? 200 : 404,
            'priority_score' => 65,
            'quality_score' => $eval['quality_score'],
            'content_hash' => $this->qualityGate->contentFingerprint($titleEn.' '.$contentEn),
            'lastmod_at' => $policy->updated_at ?? now(),
            'is_noindex_forced' => false,
            'metadata_snapshot' => ['slug' => $policy->slug, 'title' => $titleEn],
        ]);

        $this->markSitemapDirty('pages', null);

        return $registry;
    }

    public function syncStaticPages(): int
    {
        $pages = [
            ['path' => '/', 'priority' => 100],
            ['path' => '/communities', 'priority' => 90],
            ['path' => '/topics', 'priority' => 85],
            ['path' => '/services', 'priority' => 75],
            ['path' => '/premium', 'priority' => 75],
        ];

        $allowed = $this->config->isIndexingAllowed();
        $count = 0;

        foreach ($pages as $page) {
            $url = $this->canonicalUrls->absoluteUrl($page['path']);
            $this->upsertEntry([
                'url' => $url,
                'path' => $page['path'],
                'entity_type' => SeoEntityType::Page,
                'entity_id' => null,
                'locale' => $this->config->defaultLocale(),
                'source_locale' => $this->config->defaultLocale(),
                'canonical_url' => $url,
                'index_eligibility' => $allowed ? SeoIndexEligibility::Eligible : SeoIndexEligibility::Excluded,
                'exclusion_reason' => $allowed ? null : 'indexing_disabled_in_environment',
                'http_status' => 200,
                'priority_score' => $page['priority'],
                'quality_score' => 95,
                'content_hash' => hash('sha256', 'static:'.$page['path']),
                'lastmod_at' => now(),
                'is_noindex_forced' => false,
                'metadata_snapshot' => ['static_page' => true],
            ]);
            $count++;
        }

        $this->markSitemapDirty('pages', null);

        return $count;
    }

    public function markEntityRemoved(SeoEntityType $entityType, int $entityId, int $httpStatus = 410, string $reason = 'deleted'): int
    {
        $entries = SeoUrlRegistry::query()
            ->where('entity_type', $entityType->value)
            ->where('entity_id', $entityId)
            ->get();

        $updated = 0;
        foreach ($entries as $entry) {
            $entry->forceFill([
                'index_eligibility' => SeoIndexEligibility::Excluded,
                'status' => SeoUrlStatus::Excluded,
                'exclusion_reason' => $reason,
                'http_status' => $httpStatus,
                'is_in_sitemap' => false,
                'lastmod_at' => now(),
            ])->save();

            $this->markSitemapDirtyForEntity($entityType, $entry->locale);
            $updated++;
        }

        return $updated;
    }

    /**
     * Backfill all existing public entities into seo_url_registry in low-RAM chunks (Hostinger safe).
     *
     * @return array{processed: int, eligible: int}
     */
    public function backfillAll(): array
    {
        $processed = $this->syncStaticPages();

        PolicyPage::query()->chunkById(100, function ($policies) use (&$processed): void {
            foreach ($policies as $policy) {
                $this->syncPolicy($policy);
                $processed++;
            }
        });

        Community::query()->chunkById(100, function ($communities) use (&$processed): void {
            foreach ($communities as $community) {
                $this->syncCommunity($community);
                $processed++;
            }
        });

        Hashtag::query()->chunkById(200, function ($hashtags) use (&$processed): void {
            foreach ($hashtags as $hashtag) {
                $this->syncTopic($hashtag, $this->config->defaultLocale());
                $processed++;
            }
        });

        Post::query()->with(['author', 'community', 'media'])->chunkById(200, function ($posts) use (&$processed): void {
            foreach ($posts as $post) {
                $this->syncPost($post);
                $processed++;
            }
        });

        SeoContentTranslation::query()->publishedApproved()->chunkById(200, function ($translations) use (&$processed): void {
            foreach ($translations as $translation) {
                $this->syncTranslation($translation);
                $processed++;
            }
        });

        User::query()->with('profile')->chunkById(200, function ($users) use (&$processed): void {
            foreach ($users as $user) {
                $this->syncProfile($user);
                $processed++;
            }
        });

        $eligible = SeoUrlRegistry::query()->eligibleForSitemap()->count();

        return ['processed' => $processed, 'eligible' => $eligible];
    }

    public function markSitemapDirty(string $type, ?string $locale): void
    {
        if (! Schema::hasTable('seo_sitemaps')) {
            return;
        }

        $name = $locale !== null && $locale !== ''
            ? "sitemap-{$type}-{$locale}"
            : "sitemap-{$type}";

        SeoSitemap::query()->updateOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'locale' => $locale,
                'is_dirty' => true,
            ],
        );
    }

    private function markSitemapDirtyForEntity(SeoEntityType $entityType, ?string $locale): void
    {
        match ($entityType) {
            SeoEntityType::Post, SeoEntityType::Translation => $this->markSitemapDirty('posts', $locale ?: $this->config->defaultLocale()),
            SeoEntityType::Community => $this->markSitemapDirty('communities', null),
            SeoEntityType::Profile => $this->markSitemapDirty('profiles', null),
            SeoEntityType::Topic => $this->markSitemapDirty('topics', $locale ?: $this->config->defaultLocale()),
            SeoEntityType::Page, SeoEntityType::Policy => $this->markSitemapDirty('pages', null),
        };
    }

    /**
     * @param  array{
     *     url: string,
     *     path: string,
     *     entity_type: SeoEntityType,
     *     entity_id: int|null,
     *     locale: string,
     *     source_locale: string|null,
     *     canonical_url: string,
     *     index_eligibility: SeoIndexEligibility,
     *     exclusion_reason: string|null,
     *     http_status: int,
     *     priority_score: int,
     *     quality_score: int,
     *     content_hash: string|null,
     *     lastmod_at: Carbon|CarbonInterface|null,
     *     is_noindex_forced: bool,
     *     metadata_snapshot: array<string, mixed>|null
     * }  $data
     */
    private function upsertEntry(array $data): SeoUrlRegistry
    {
        $urlHash = $this->canonicalUrls->urlHash($data['url']);
        $existing = SeoUrlRegistry::query()->where('url_hash', $urlHash)->first();

        // Also handle slug/URL rename for the same entity+locale cleanly
        if ($existing === null && $data['entity_id'] !== null) {
            $existing = SeoUrlRegistry::query()
                ->where('entity_type', $data['entity_type']->value)
                ->where('entity_id', $data['entity_id'])
                ->where('locale', $data['locale'])
                ->first();
        }

        $isEligible = $data['index_eligibility'] === SeoIndexEligibility::Eligible && ! $data['is_noindex_forced'] && $data['http_status'] === 200;

        $nextStatus = $isEligible
            ? ($existing !== null && $existing->status === SeoUrlStatus::Indexed
                ? SeoUrlStatus::Indexed
                : SeoUrlStatus::PendingSitemapUpdate)
            : SeoUrlStatus::Excluded;

        $payload = [
            'url_hash' => $urlHash,
            'url' => $data['url'],
            'path' => $data['path'],
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'locale' => $data['locale'],
            'source_locale' => $data['source_locale'],
            'canonical_url' => $data['canonical_url'],
            'index_eligibility' => $data['index_eligibility'],
            'status' => $nextStatus,
            'exclusion_reason' => $data['exclusion_reason'],
            'http_status' => $data['http_status'],
            'priority_score' => $data['priority_score'],
            'quality_score' => $data['quality_score'],
            'is_in_sitemap' => $isEligible ? ($existing->is_in_sitemap ?? false) : false,
            'content_hash' => $data['content_hash'],
            'lastmod_at' => $data['lastmod_at'],
            'is_noindex_forced' => $data['is_noindex_forced'],
            'metadata_snapshot' => $data['metadata_snapshot'],
        ];

        if ($existing !== null) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return SeoUrlRegistry::query()->create($payload);
    }
}
