<?php

namespace App\Enums;

enum PostCategory: string
{
    case General = 'general';
    case Technology = 'technology';
    case Business = 'business';
    case Finance = 'finance';
    case Qa = 'qa';
    case Marketplace = 'marketplace';

    public function label(): string
    {
        return match ($this) {
            self::General => __('General'),
            self::Technology => __('Technology'),
            self::Business => __('Business'),
            self::Finance => __('Finance & Earning'),
            self::Qa => __('Q&A'),
            self::Marketplace => __('Marketplace'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            self::cases(),
        );
    }
}
