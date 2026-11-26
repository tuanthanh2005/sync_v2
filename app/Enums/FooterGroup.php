<?php

namespace App\Enums;

use Illuminate\Support\Facades\Lang;

enum FooterGroup: string
{
    case Platform = 'platform';
    case Legal = 'legal';
    case Support = 'support';

    public function label(?string $locale = null): string
    {
        $resolvedLocale = $locale ?? app()->getLocale();

        return match ($this) {
            self::Platform => (string) Lang::get('Platform', [], $resolvedLocale),
            self::Legal => (string) Lang::get('Legal & Policies', [], $resolvedLocale),
            self::Support => (string) Lang::get('Support', [], $resolvedLocale),
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
