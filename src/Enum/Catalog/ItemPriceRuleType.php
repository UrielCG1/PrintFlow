<?php

namespace App\Enum\Catalog;

enum ItemPriceRuleType: string
{
    case QUANTITY_TIER = 'QUANTITY_TIER';

    public function label(): string
    {
        return match ($this) {
            self::QUANTITY_TIER => 'Precio por cantidad',
        };
    }
}