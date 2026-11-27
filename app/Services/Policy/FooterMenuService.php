<?php

namespace App\Services\Policy;

use App\Enums\FooterGroup;
use App\Models\FooterLink;
use App\Services\System\SystemSettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

class FooterMenuService
{
    public const CACHE_KEY = 'syncviet.footer.links.v2';

    private const LEGACY_CACHE_KEY = 'syncviet.footer.links.v1';

    /** @var list<string> */
    private const PLACEHOLDER_DOMAINS = [
        'example.com',
        'example.org',
        'example.net',
        'localhost',
        'test',
        'invalid',
    ];

    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Retrieve visible, approved footer links grouped and localized for Inertia shared props.
     *
     * @return array{
     *     year: int,
     *     supportEmail: string|null,
     *     contactInfo: string|null,
     *     copyrightText: string|null,
     *     links: list<array{
     *         id: int,
     *         label: string,
     *         href: string,
     *         group: string,
     *         group_label: string,
     *         placement: string,
     *         open_in_new_tab: bool
     *     }>,
     *     mainLinks: list<array{
     *         id: int,
     *         label: string,
     *         href: string,
     *         group: string,
     *         group_label: string,
     *         placement: string,
     *         open_in_new_tab: bool
     *     }>,
     *     moreLinks: list<array{
     *         id: int,
     *         label: string,
     *         href: string,
     *         group: string,
     *         group_label: string,
     *         placement: string,
     *         open_in_new_tab: bool
     *     }>,
     *     groups: list<array{
     *         key: string,
     *         label: string,
     *         items: list<array{
     *             id: int,
     *             label: string,
     *             href: string,
     *             group: string,
     *             group_label: string,
     *             placement: string,
     *             open_in_new_tab: bool
     *         }>
     *     }>
     * }
     */
    public function shared(string $locale): array
    {
        /** @var list<array{
         *     id: int,
         *     label_vi: string,
         *     label_en: string,
         *     translations: array<string, string>,
         *     href: string,
         *     group: string,
         *     placement: string,
         *     open_in_new_tab: bool
         * }> $cached */
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), function (): array {
            $links = FooterLink::query()
                ->with(['policyPage:id,slug,status,is_visible,archived_at'])
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $items = [];

            foreach ($links as $link) {
                $href = $this->resolveHref($link);

                if ($href === null) {
                    continue;
                }

                $placement = in_array($link->placement, ['main', 'more'], true) ? $link->placement : 'main';
                $translations = is_array($link->translations)
                    ? array_filter(
                        array_map(static fn (string $val): string => trim($val), $link->translations),
                        static fn (string $val): bool => $val !== '',
                    )
                    : [];

                $items[] = [
                    'id' => $link->id,
                    'label_vi' => $link->label_vi,
                    'label_en' => $link->label_en,
                    'translations' => $translations,
                    'href' => $href,
                    'group' => $link->footer_group->value,
                    'placement' => $placement,
                    'open_in_new_tab' => $link->open_in_new_tab,
                ];
            }

            return $items;
        });

        $localizedLinks = [];
        $mainLinks = [];
        $moreLinks = [];
        $grouped = [];

        foreach ($cached as $item) {
            $groupEnum = FooterGroup::tryFrom($item['group']) ?? FooterGroup::Legal;
            $groupLabel = $groupEnum->label($locale);
            $label = $this->resolveLocalizedLabel($item, $locale);

            $resolved = [
                'id' => $item['id'],
                'label' => $label,
                'href' => $item['href'],
                'group' => $groupEnum->value,
                'group_label' => $groupLabel,
                'placement' => $item['placement'],
                'open_in_new_tab' => $item['open_in_new_tab'],
            ];

            $localizedLinks[] = $resolved;

            if ($item['placement'] === 'more') {
                $moreLinks[] = $resolved;
            } else {
                $mainLinks[] = $resolved;
            }

            if (! isset($grouped[$groupEnum->value])) {
                $grouped[$groupEnum->value] = [
                    'key' => $groupEnum->value,
                    'label' => $groupLabel,
                    'items' => [],
                ];
            }

            $grouped[$groupEnum->value]['items'][] = $resolved;
        }

        // If all visible links were placed in "more", promote the first 5 into mainLinks cleanly.
        if ($mainLinks === [] && $moreLinks !== []) {
            $mainLinks = array_slice($moreLinks, 0, 5);
            $moreLinks = array_slice($moreLinks, 5);
        }

        $configuredEmail = trim($this->settings->get('contact_email', ''));
        $supportEmail = $this->sanitizeSupportEmail($configuredEmail);

        $contactInfoRaw = trim($this->settings->get('support_contact_info', ''));
        $copyrightRaw = trim($this->settings->get('footer_copyright_text', ''));

        return [
            'year' => (int) now()->year,
            'supportEmail' => $supportEmail,
            'contactInfo' => $contactInfoRaw !== '' ? $contactInfoRaw : null,
            'copyrightText' => $copyrightRaw !== '' ? $copyrightRaw : null,
            'links' => $localizedLinks,
            'mainLinks' => $mainLinks,
            'moreLinks' => $moreLinks,
            'groups' => array_values($grouped),
        ];
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::LEGACY_CACHE_KEY);
    }

    public function sanitizeSupportEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $trimmed = trim($email);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $parts = explode('@', strtolower($trimmed));
        $domain = $parts[1] ?? '';

        if (
            $domain === ''
            || in_array($domain, self::PLACEHOLDER_DOMAINS, true)
            || str_ends_with($domain, '.example')
            || str_ends_with($domain, '.local')
            || str_ends_with($domain, '.invalid')
        ) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  array{label_vi: string, label_en: string, translations: array<string, string>}  $item
     */
    private function resolveLocalizedLabel(array $item, string $locale): string
    {
        if (isset($item['translations'][$locale]) && $item['translations'][$locale] !== '') {
            return $item['translations'][$locale];
        }

        if ($locale === 'vi') {
            return $item['label_vi'] ?: $item['label_en'];
        }

        return (string) Lang::get($item['label_en'], [], $locale);
    }

    private function resolveHref(FooterLink $link): ?string
    {
        if ($link->policy_page_id !== null) {
            $policy = $link->policyPage;

            if ($policy === null || ! $policy->isPubliclyAccessible()) {
                return null;
            }

            return route('policies.show', ['policyPage' => $policy->slug]);
        }

        if ($link->route_name !== null && $link->route_name !== '' && Route::has($link->route_name)) {
            return route($link->route_name);
        }

        if ($link->url !== null && $link->url !== '') {
            if (str_starts_with($link->url, '/') && ! str_starts_with($link->url, '//')) {
                return $link->url;
            }

            $scheme = parse_url($link->url, PHP_URL_SCHEME);

            if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
                return $link->url;
            }
        }

        return null;
    }
}
