<?php

namespace App\Enums;

enum AdBudgetType: string
{
    case Daily = 'daily';
    case Total = 'total';

    public function label(): string
    {
        return match ($this) {
            self::Daily => __('Daily budget'),
            self::Total => __('Total budget'),
        };
    }
}
