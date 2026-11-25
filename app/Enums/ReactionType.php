<?php

namespace App\Enums;

enum ReactionType: string
{
    case Like = 'like';
    case Love = 'love';
    case Insightful = 'insightful';

    public function label(): string
    {
        return match ($this) {
            self::Like => __('Like'),
            self::Love => __('Love'),
            self::Insightful => __('Insightful'),
        };
    }
}
