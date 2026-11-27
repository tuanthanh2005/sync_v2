<?php

namespace App\Enums;

use Illuminate\Support\Facades\Lang;

enum PolicyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(?string $locale = null): string
    {
        $resolvedLocale = $locale ?? app()->getLocale();

        return match ($this) {
            self::Draft => (string) Lang::get('Draft', [], $resolvedLocale),
            self::Published => (string) Lang::get('Published', [], $resolvedLocale),
            self::Archived => (string) Lang::get('Archived', [], $resolvedLocale),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
