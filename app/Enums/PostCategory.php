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

    /**
     * Explicit allowlist of Lucide icon names permitted for feed categories (never raw SVG/HTML).
     *
     * @var list<string>
     */
    public const ALLOWED_ICONS = [
        'LayoutGrid',
        'TrendingUp',
        'Clock',
        'Wallet',
        'Coins',
        'Cpu',
        'BriefcaseBusiness',
        'MessageCircleQuestion',
        'ShoppingBag',
        'Sparkles',
        'Compass',
        'Globe',
    ];

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

    public function icon(): string
    {
        return match ($this) {
            self::General => 'LayoutGrid',
            self::Technology => 'Cpu',
            self::Business => 'BriefcaseBusiness',
            self::Finance => 'Wallet',
            self::Qa => 'MessageCircleQuestion',
            self::Marketplace => 'ShoppingBag',
        };
    }

    /**
     * @return list<array{value: string, label: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'icon' => $category->icon(),
            ],
            self::cases(),
        );
    }
}
