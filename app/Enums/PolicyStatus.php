<?php

namespace App\Enums;

enum PolicyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(string $locale = 'vi'): string
    {
        if ($locale === 'en') {
            return match ($this) {
                self::Draft => 'Draft',
                self::Published => 'Published',
            };
        }

        return match ($this) {
            self::Draft => 'Bản nháp',
            self::Published => 'Đã xuất bản',
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
