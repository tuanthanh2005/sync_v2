<?php

namespace App\Enums;

enum AdObjective: string
{
    case Reach = 'reach';
    case Engagement = 'engagement';
    case Traffic = 'traffic';

    public function label(): string
    {
        return match ($this) {
            self::Reach => __('Increase reach'),
            self::Engagement => __('Increase engagement'),
            self::Traffic => __('Increase website traffic'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Reach => __('Show your post to a broader relevant audience in feeds.'),
            self::Engagement => __('Encourage likes, comments, and discussions on your post.'),
            self::Traffic => __('Drive qualified clicks to your destination link or landing page.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}
