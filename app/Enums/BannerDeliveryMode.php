<?php

namespace App\Enums;

enum BannerDeliveryMode: string
{
    case Exclusive = 'exclusive';
    case Rotation = 'rotation';

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => __('Exclusive'),
            self::Rotation => __('Rotation'),
        };
    }
}
