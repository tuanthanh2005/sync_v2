<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SafeLinkPreviewService
{
    private const MAX_HTML_BYTES = 524_288;

    public function __construct(
        private readonly HostIpResolver $ipResolver,
    ) {}

    public function isSafePublicUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '' || strlen($trimmed) > 500) {
            return false;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($host === '') {
            return false;
        }

        $lowerHost = strtolower($host);

        if (
            $lowerHost === 'localhost'
            || str_ends_with($lowerHost, '.localhost')
            || str_ends_with($lowerHost, '.local')
            || str_ends_with($lowerHost, '.internal')
        ) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $resolvedIps = $this->ipResolver->resolve($host);

        if ($resolvedIps === []) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch sanitized metadata for a safe public URL without SSRF or HTML injection risk.
     *
     * @return array{
     *     url: string,
     *     domain: string,
     *     title: string|null,
     *     description: string|null,
     *     image: string|null
     * }|null
     */
    public function fetch(string $url): ?array
    {
        if (! $this->isSafePublicUrl($url)) {
            return null;
        }

        $domain = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout(2)
                ->timeout(4)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => config('app.name').'-LinkPreview/1.0',
                ])
                ->get($url);
        } catch (\Throwable) {
            return [
                'url' => $url,
                'domain' => $domain,
                'title' => $domain !== '' ? $domain : null,
                'description' => null,
                'image' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'url' => $url,
                'domain' => $domain,
                'title' => $domain !== '' ? $domain : null,
                'description' => null,
                'image' => null,
            ];
        }

        $html = substr($response->body(), 0, self::MAX_HTML_BYTES);

        if ($html === '') {
            return [
                'url' => $url,
                'domain' => $domain,
                'title' => $domain !== '' ? $domain : null,
                'description' => null,
                'image' => null,
            ];
        }

        $meta = $this->parseHtmlMetadata($html);

        $image = $meta['image'];

        if ($image !== null && ! $this->isSafeImageReference($image)) {
            $image = null;
        }

        return [
            'url' => $url,
            'domain' => $domain,
            'title' => $meta['title'] ?: ($domain !== '' ? $domain : null),
            'description' => $meta['description'],
            'image' => $image,
        ];
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Explicitly block CGNAT 100.64.0.0/10 and cloud metadata IPv4/IPv6 ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long === false) {
                return false;
            }

            $cgnatStart = ip2long('100.64.0.0');
            $cgnatEnd = ip2long('100.127.255.255');

            if ($cgnatStart !== false && $cgnatEnd !== false && $long >= $cgnatStart && $long <= $cgnatEnd) {
                return false;
            }
        }

        return true;
    }

    private function isSafeImageReference(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && $this->isSafePublicUrl($url);
    }

    /**
     * @return array{title: string|null, description: string|null, image: string|null}
     */
    private function parseHtmlMetadata(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return ['title' => null, 'description' => null, 'image' => null];
        }

        $title = null;
        $description = null;
        $image = null;

        $metaNodes = $dom->getElementsByTagName('meta');

        foreach ($metaNodes as $node) {
            $property = strtolower($node->getAttribute('property'));
            $name = strtolower($node->getAttribute('name'));
            $content = $this->cleanText($node->getAttribute('content'));

            if ($content === null) {
                continue;
            }

            if ($property === 'og:title' && $title === null) {
                $title = Str::limit($content, 160, '...');
            } elseif (($property === 'og:description' || $name === 'description') && $description === null) {
                $description = Str::limit($content, 240, '...');
            } elseif ($property === 'og:image' && $image === null) {
                $image = Str::limit($content, 500, '');
            }
        }

        if ($title === null) {
            $titleTags = $dom->getElementsByTagName('title');

            if ($titleTags->length > 0) {
                $titleNode = $titleTags->item(0);
                $rawTitle = $this->cleanText($titleNode !== null ? $titleNode->textContent : '');

                if ($rawTitle !== null) {
                    $title = Str::limit($rawTitle, 160, '...');
                }
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
        ];
    }

    private function cleanText(string $value): ?string
    {
        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = trim((string) preg_replace('/\s+/', ' ', $decoded));

        return $normalized === '' ? null : $normalized;
    }
}
