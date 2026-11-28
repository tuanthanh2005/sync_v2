<?php

namespace App\Enums;

enum PaymentServiceType: string
{
    case TopUp = 'topup';
    case Premium = 'premium';
    case Banner = 'banner';
    case Boost = 'boost';

    public function label(): string
    {
        return match ($this) {
            self::TopUp => __('Advertising Balance Top-Up'),
            self::Premium => __('SysNoxis Premium'),
            self::Banner => __('Banner Advertising'),
            self::Boost => __('Boost Post'),
        };
    }
}
