<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustConfiguredProxies extends Middleware
{
    /**
     * Resolve trusted proxies cleanly from cached config('syncviet.trusted_proxies')
     * so IP rate limiting, HTTPS detection, and Secure cookies work accurately behind
     * Cloudflare / Hostinger reverse proxies without trusting arbitrary spoofed headers.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $configured = config('syncviet.trusted_proxies');

        if ($configured === null) {
            return null;
        }

        if (is_array($configured)) {
            return array_values(array_filter(array_map('trim', $configured)));
        }

        $trimmed = trim((string) $configured);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === '*' || $trimmed === '**') {
            return $trimmed;
        }

        return array_values(array_filter(array_map('trim', explode(',', $trimmed))));
    }
}
