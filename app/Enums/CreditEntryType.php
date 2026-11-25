<?php

namespace App\Enums;

enum CreditEntryType: string
{
    case Deposit = 'deposit';
    case Hold = 'hold';
    case Release = 'release';
    case Spend = 'spend';
    case Refund = 'refund';
    case PromoGrant = 'promo_grant';
    case PromoSpend = 'promo_spend';
    case AdminAdjustment = 'admin_adjustment';
    case AdminRefund = 'admin_refund';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => __('Wallet deposit'),
            self::Hold => __('Campaign budget hold'),
            self::Release => __('Budget release'),
            self::Spend => __('Ad spend'),
            self::Refund => __('Campaign refund'),
            self::PromoGrant => __('Promotional credit grant'),
            self::PromoSpend => __('Promotional credit spend'),
            self::AdminAdjustment => __('Admin ledger adjustment'),
            self::AdminRefund => __('Payment refund'),
        };
    }
}
