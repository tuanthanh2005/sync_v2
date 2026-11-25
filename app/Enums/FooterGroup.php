<?php

namespace App\Enums;

enum FooterGroup: string
{
    case Platform = 'platform';
    case Legal = 'legal';
    case Support = 'support';

    public function label(string $locale = 'vi'): string
    {
        if ($locale === 'en') {
            return match ($this) {
                self::Platform => 'Platform',
                self::Legal => 'Legal & Policies',
                self::Support => 'Support',
            };
        }

        return match ($this) {
            self::Platform => 'Nền tảng',
            self::Legal => 'Pháp lý & Chính sách',
            self::Support => 'Hỗ trợ',
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
