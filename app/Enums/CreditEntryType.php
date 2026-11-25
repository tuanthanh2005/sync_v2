<?php

namespace App\Enums;

enum CreditEntryType: string
{
    case Deposit = 'deposit';
    case Hold = 'hold';
    case Release = 'release';
    case Spend = 'spend';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => __('Wallet deposit'),
            self::Hold => __('Campaign budget hold'),
            self::Release => __('Budget release'),
            self::Spend => __('Ad spend'),
            self::Refund => __('Campaign refund'),
        };
    }
}
