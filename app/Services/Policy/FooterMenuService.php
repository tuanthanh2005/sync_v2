<?php

namespace App\Services\Policy;

use App\Enums\FooterGroup;
use App\Enums\PolicyStatus;
use App\Models\FooterLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

class FooterMenuService
{
    public const CACHE_KEY = 'syncviet.footer.links.v1';

    /**
     * Retrieve visible, approved footer links grouped and localized for Inertia shared props.
     *
     * @return array{
     *     year: int,
     *     links: list<array{
     *         id: int,
     *         label: string,
     *         href: string,
     *         group: string,
     *         group_label: string,
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
         *     href: string,
         *     group: string,
         *     open_in_new_tab: bool
         * }> $cached */
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), function (): array {
            $links = FooterLink::query()
                ->with(['policyPage:id,slug,status,is_visible'])
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

                $items[] = [
                    'id' => $link->id,
                    'label_vi' => $link->label_vi,
                    'label_en' => $link->label_en,
                    'href' => $href,
                    'group' => $link->footer_group->value,
                    'open_in_new_tab' => $link->open_in_new_tab,
                ];
            }

            return $items;
        });

        $localizedLinks = [];
        $grouped = [];

        foreach ($cached as $item) {
            $groupEnum = FooterGroup::tryFrom($item['group']) ?? FooterGroup::Legal;
            $groupLabel = $groupEnum->label($locale);
            $resolved = [
                'id' => $item['id'],
                'label' => $locale === 'vi'
                    ? ($item['label_vi'] ?: $item['label_en'])
                    : (string) Lang::get($item['label_en'], [], $locale),
                'href' => $item['href'],
                'group' => $groupEnum->value,
                'group_label' => $groupLabel,
                'open_in_new_tab' => $item['open_in_new_tab'],
            ];

            $localizedLinks[] = $resolved;

            if (! isset($grouped[$groupEnum->value])) {
                $grouped[$groupEnum->value] = [
                    'key' => $groupEnum->value,
                    'label' => $groupLabel,
                    'items' => [],
                ];
            }

            $grouped[$groupEnum->value]['items'][] = $resolved;
        }

        return [
            'year' => (int) now()->year,
            'supportEmail' => config('mail.from.address'),
            'links' => $localizedLinks,
            'groups' => array_values($grouped),
        ];
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function resolveHref(FooterLink $link): ?string
    {
        if ($link->policy_page_id !== null) {
            $policy = $link->policyPage;

            if ($policy === null || $policy->status !== PolicyStatus::Published || ! $policy->is_visible) {
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
