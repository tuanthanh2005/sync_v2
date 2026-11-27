<?php

namespace App\Enums;

use Illuminate\Support\Facades\Lang;

enum FooterGroup: string
{
    case Company = 'company';
    case Platform = 'platform';
    case Support = 'support';
    case Legal = 'legal';
    case Business = 'business';

    public function label(?string $locale = null): string
    {
        $resolvedLocale = $locale ?? app()->getLocale();

        return match ($this) {
            self::Company => (string) Lang::get('Company', [], $resolvedLocale),
            self::Platform => (string) Lang::get('Platform', [], $resolvedLocale),
            self::Support => (string) Lang::get('Support', [], $resolvedLocale),
            self::Legal => (string) Lang::get('Legal & Policies', [], $resolvedLocale),
            self::Business => (string) Lang::get('Business & Growth', [], $resolvedLocale),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $group): string => $group->value,
            self::cases(),
        );
    }
}
