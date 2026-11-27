<?php

namespace App\Services\Seo;

use App\Models\Community;
use App\Models\Hashtag;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CanonicalUrlService
{
    /**
     * Tracking and share parameters stripped from canonical URLs.
     *
     * @var list<string>
     */
    public const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'dclid', 'msclkid', 'yclid', 'mc_cid', 'mc_eid',
        'ref', 'referrer', 'share', 'source', '_ga', '_gl', 's',
    ];

    public function __construct(
        private readonly SeoConfigService $config,
    ) {}

    public function absoluteUrl(string $path): string
    {
        $cleanPath = $this->normalizePath($path);

        return $cleanPath === '/'
            ? $this->config->canonicalBaseUrl().'/'
            : $this->config->canonicalBaseUrl().$cleanPath;
    }

    public function normalizePath(string $path): string
    {
        $parsedPath = parse_url($path, PHP_URL_PATH);
        $raw = is_string($parsedPath) ? $parsedPath : $path;
        $trimmed = '/'.ltrim(trim($raw), '/');
        $collapsed = preg_replace('#/+#', '/', $trimmed) ?? '/';

        if ($collapsed !== '/' && str_ends_with($collapsed, '/')) {
            $collapsed = rtrim($collapsed, '/');
        }

        return $collapsed === '' ? '/' : $collapsed;
    }

    public function urlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    public function pathHash(string $path): string
    {
        return hash('sha256', $this->normalizePath($path));
    }

    public function buildPostSlug(Post $post): string
    {
        if (is_string($post->slug) && trim($post->slug) !== '') {
            return Str::limit(Str::slug($post->slug), 90, '');
        }

        $source = is_string($post->title) && trim($post->title) !== ''
            ? $post->title
            : Str::limit(strip_tags($post->content), 80, '');

        $slug = Str::slug($source);

        return $slug !== '' ? Str::limit($slug, 90, '') : 'post-'.$post->id;
    }

    public function postPath(Post $post, ?string $locale = null, ?string $localizedSlug = null): string
    {
        $slug = $localizedSlug !== null && trim($localizedSlug) !== ''
            ? Str::slug($localizedSlug)
            : $this->buildPostSlug($post);

        if ($locale !== null && $locale !== '') {
            return '/'.$locale.'/posts/'.$post->id.($slug !== '' ? '-'.$slug : '');
        }

        return '/posts/'.$post->id;
    }

    public function postCanonicalUrl(Post $post, ?string $locale = null, ?SeoContentTranslation $translation = null): string
    {
        $effectiveLocale = $locale ?: ($post->language_code ?: $this->config->defaultLocale());
        $slug = $translation !== null ? $translation->localized_slug : $this->buildPostSlug($post);

        return $this->absoluteUrl($this->postPath($post, $effectiveLocale, $slug));
    }

    public function communityPath(Community $community, ?string $locale = null): string
    {
        if ($locale !== null && $locale !== '' && $locale !== $this->config->defaultLocale()) {
            return '/'.$locale.'/communities/'.$community->slug;
        }

        return '/communities/'.$community->slug;
    }

    public function communityCanonicalUrl(Community $community, ?string $locale = null): string
    {
        return $this->absoluteUrl($this->communityPath($community, $locale));
    }

    public function profilePath(User $user): string
    {
        return '/u/'.$user->username;
    }

    public function profileCanonicalUrl(User $user): string
    {
        return $this->absoluteUrl($this->profilePath($user));
    }

    public function topicPath(Hashtag|string $topic, ?string $locale = null): string
    {
        $slug = $topic instanceof Hashtag ? $topic->slug : Str::slug($topic);

        if ($locale !== null && $locale !== '' && $locale !== $this->config->defaultLocale()) {
            return '/'.$locale.'/topics/'.$slug;
        }

        return '/topics/'.$slug;
    }

    public function topicCanonicalUrl(Hashtag|string $topic, ?string $locale = null, int $page = 1): string
    {
        $base = $this->absoluteUrl($this->topicPath($topic, $locale));

        return $page > 1 ? $base.'?page='.$page : $base;
    }

    public function policyPath(PolicyPage $policy, ?string $locale = null): string
    {
        if ($locale !== null && $locale !== '' && $locale !== $this->config->defaultLocale()) {
            return '/'.$locale.'/policies/'.$policy->slug;
        }

        return '/policies/'.$policy->slug;
    }

    public function policyCanonicalUrl(PolicyPage $policy, ?string $locale = null): string
    {
        return $this->absoluteUrl($this->policyPath($policy, $locale));
    }

    public function fromRequest(Request $request, bool $allowPageParam = false): string
    {
        $path = $this->normalizePath($request->getPathInfo());
        $url = $this->absoluteUrl($path);

        if ($allowPageParam) {
            $page = (int) $request->query('page', 1);
            if ($page > 1) {
                return $url.'?page='.$page;
            }
        }

        return $url;
    }

    /**
     * Validate redirect target to block open redirects to untrusted external origins.
     */
    public function isSafeRedirectTarget(string $target): bool
    {
        $trimmed = trim($target);

        if ($trimmed === '' || str_contains($trimmed, "\r") || str_contains($trimmed, "\n")) {
            return false;
        }

        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '//')) {
            return true;
        }

        $parsed = parse_url($trimmed);
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return strtolower($parsed['host']) === $this->config->canonicalHost();
    }

    /**
     * Strip query parameters safely and return whether the request contained tracking params.
     */
    public function hasOnlyTrackingParams(Request $request): bool
    {
        $query = $request->query();
        if ($query === []) {
            return false;
        }

        foreach (array_keys($query) as $param) {
            if (! in_array(strtolower((string) $param), self::TRACKING_PARAMS, true)) {
                return false;
            }
        }

        return true;
    }
}
