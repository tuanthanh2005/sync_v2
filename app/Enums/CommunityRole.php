<?php

namespace App\Enums;

enum CommunityRole: string
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Moderator => __('Moderator'),
            self::Member => __('Member'),
        };
    }

    public function canModerate(): bool
    {
        return $this === self::Owner || $this === self::Moderator;
    }
}
