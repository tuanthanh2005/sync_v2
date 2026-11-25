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
    ];

    /**
     * Sanitize rich policy content server-side so stored HTML cannot execute JavaScript or load unsafe targets.
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
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input'], true)) {
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
