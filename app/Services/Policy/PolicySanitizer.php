<?php

namespace App\Services\Policy;

use DOMDocument;
use DOMElement;
use DOMNode;

class PolicySanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p',
        'h2',
        'h3',
        'h4',
        'ul',
        'ol',
        'li',
        'strong',
        'em',
        'br',
        'a',
        'blockquote',
        'hr',
        'img',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
    ];

    /**
     * Sanitize rich policy/CMS content server-side so stored HTML cannot execute JavaScript
     * or hotlink untrusted external image URLs.
     */
    public function sanitize(?string $rawContent): ?string
    {
        if ($rawContent === null) {
            return null;
        }

        $trimmed = trim($rawContent);

        if ($trimmed === '') {
            return null;
        }

        // Convert plain line-separated paragraphs if no HTML tags are present.
        if (! str_contains($trimmed, '<')) {
            $paragraphs = preg_split("/\R{2,}/", $trimmed) ?: [$trimmed];

            return implode('', array_map(
                static fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph))).'</p>',
                array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''),
            ));
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="syncviet-policy-root">'.$trimmed.'</div>';
        $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('syncviet-policy-root');

        if (! $root instanceof DOMElement) {
            return '<p>'.e(strip_tags($trimmed)).'</p>';
        }

        $this->sanitizeNode($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= (string) $document->saveHTML($child);
        }

        $cleaned = trim($output);

        return $cleaned === '' ? null : $cleaned;
    }

    private function sanitizeNode(DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            // Drop dangerous elements completely along with their content.
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'textarea', 'button', 'video', 'audio', 'source'], true)) {
                $node->removeChild($child);

                continue;
            }

            $this->sanitizeNode($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            if ($tag === 'img') {
                if (! $this->sanitizeImageElement($child)) {
                    $node->removeChild($child);
                }

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowedHref = null;

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $candidate = trim($element->getAttribute('href'));
            $allowedHref = $this->sanitizeUrl($candidate);
        }

        while ($element->attributes->length > 0) {
            $attr = $element->attributes->item(0);

            if ($attr !== null) {
                $element->removeAttributeNode($attr);
            }
        }

        if ($allowedHref !== null) {
            $element->setAttribute('href', $allowedHref);
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    /**
     * Allow <img> ONLY for internal SyncViet storage or configured R2/CDN URLs (zero external hotlinking).
     */
    private function sanitizeImageElement(DOMElement $element): bool
    {
        $rawSrc = $element->hasAttribute('src') ? trim($element->getAttribute('src')) : '';
        $safeSrc = $this->sanitizeInternalImageUrl($rawSrc);

        if ($safeSrc === null) {
            return false;
        }

        $alt = $element->hasAttribute('alt')
            ? mb_substr(trim($element->getAttribute('alt')), 0, 200)
            : '';
        $width = $element->hasAttribute('width') ? (int) $element->getAttribute('width') : 0;
        $height = $element->hasAttribute('height') ? (int) $element->getAttribute('height') : 0;

        while ($element->attributes->length > 0) {
            $attr = $element->attributes->item(0);
            if ($attr !== null) {
                $element->removeAttributeNode($attr);
            }
        }

        $element->setAttribute('src', $safeSrc);
        $element->setAttribute('alt', $alt);
        $element->setAttribute('loading', 'lazy');

        if ($width > 0 && $width <= 4096) {
            $element->setAttribute('width', (string) $width);
        }
        if ($height > 0 && $height <= 4096) {
            $element->setAttribute('height', (string) $height);
        }

        return true;
    }

    private function sanitizeInternalImageUrl(string $url): ?string
    {
        if ($url === '' || str_contains($url, '..') || str_contains($url, "\0")) {
            return null;
        }

        $lower = strtolower($url);
        if (str_ends_with(explode('?', $lower, 2)[0], '.svg')) {
            return null;
        }

        if (str_starts_with($url, '/storage/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        if (! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parsed['host']);
        $allowedHosts = [];

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $allowedHosts[] = strtolower($appHost);
        }

        $s3Url = (string) config('filesystems.disks.s3.url', '');
        $s3Host = $s3Url !== '' ? parse_url($s3Url, PHP_URL_HOST) : null;
        if (is_string($s3Host) && $s3Host !== '') {
            $allowedHosts[] = strtolower($s3Host);
        }

        if (! in_array($host, $allowedHosts, true)) {
            return null;
        }

        return $url;
    }

    private function sanitizeUrl(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return null;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true) ? $url : null;
    }
}
