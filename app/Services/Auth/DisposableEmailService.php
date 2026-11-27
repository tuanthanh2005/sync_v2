<?php

namespace App\Services\Auth;

use App\Models\DisposableEmailDomain;
use App\Services\System\SystemSettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DisposableEmailService
{
    public const CACHE_KEY = 'syncviet.auth.disposable_domains.v1';

    /**
     * Curated built-in temporary / throwaway email provider domains.
     * Legitimate global providers (Gmail, Outlook, Yahoo, Proton, iCloud) and corporate
     * custom domains are NEVER blocked.
     *
     * @var list<string>
     */
    private const DEFAULT_DISPOSABLE_DOMAINS = [
        '10minutemail.com',
        '10minutemail.net',
        '20minutemail.com',
        'burnermail.io',
        'dispostable.com',
        'dropmail.me',
        'emailondeck.com',
        'fakeinbox.com',
        'getairmail.com',
        'getnada.com',
        'grr.la',
        'guerrillamail.biz',
        'guerrillamail.com',
        'guerrillamail.de',
        'guerrillamail.info',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamailblock.com',
        'harakirimail.com',
        'inboxkitten.com',
        'jetable.org',
        'mailcatch.com',
        'maildrop.cc',
        'mailinator.com',
        'mailinator.net',
        'mailnesia.com',
        'mintemail.com',
        'mohmal.com',
        'mytemp.email',
        'nada.email',
        'pokemail.net',
        'sharklasers.com',
        'spam4.me',
        'spamgourmet.com',
        'temp-mail.io',
        'temp-mail.org',
        'tempail.com',
        'tempinbox.com',
        'tempmail.com',
        'tempmail.de',
        'tempmail.net',
        'tempmailo.com',
        'tempr.email',
        'throwawaymail.com',
        'trashmail.com',
        'trashmail.de',
        'trashmail.net',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
    ];

    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Extract a privacy-hashed anti-abuse fingerprint.
     * Applies dot/plus folding ONLY for Gmail/Googlemail (never for non-Gmail or corporate domains).
     */
    public function canonicalFingerprint(string $email): string
    {
        $normalized = $this->normalizeEmail($email);
        if (! str_contains($normalized, '@')) {
            return hash('sha256', $normalized);
        }

        [$local, $domain] = explode('@', $normalized, 2);

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $plusPos = strpos($local, '+');
            if ($plusPos !== false) {
                $local = substr($local, 0, $plusPos);
            }
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return hash_hmac('sha256', "{$local}@{$domain}", (string) config('app.key', 'syncviet-fp'));
    }

    public function isDisposable(string $email): bool
    {
        if (! (bool) $this->settings->get('auth_disposable_email_protection', true)) {
            return false;
        }

        $normalized = $this->normalizeEmail($email);
        if (! str_contains($normalized, '@')) {
            return false;
        }

        $domain = substr(strrchr($normalized, '@') ?: '', 1);
        if ($domain === '') {
            return false;
        }

        $blocked = $this->blockedDomainsSet();

        if (isset($blocked[$domain])) {
            return true;
        }

        // Also catch subdomains of known disposable providers (e.g. sub.mailinator.com)
        foreach (array_keys($blocked) as $candidate) {
            if (str_ends_with($domain, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function blockedDomainsSet(): array
    {
        /** @var array<string, true> $set */
        $set = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), static function (): array {
            $map = array_fill_keys(self::DEFAULT_DISPOSABLE_DOMAINS, true);

            if (Schema::hasTable('disposable_email_domains')) {
                $rows = DisposableEmailDomain::query()->get(['domain', 'is_active']);
                foreach ($rows as $row) {
                    $d = Str::lower(trim($row->domain));
                    if ($d === '') {
                        continue;
                    }
                    if ($row->is_active) {
                        $map[$d] = true;
                    } else {
                        unset($map[$d]);
                    }
                }
            }

            return $map;
        });

        return $set;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
