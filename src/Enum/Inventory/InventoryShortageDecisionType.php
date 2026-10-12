<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum InventoryShortageDecisionType: string
{
    case WAIT_FOR_PURCHASE = 'WAIT_FOR_PURCHASE';
    case AUTHORIZE_PARTIAL_PRODUCTION = 'AUTHORIZE_PARTIAL_PRODUCTION';

    public function label(): string
    {
        return match ($this) {
            self::WAIT_FOR_PURCHASE => 'En espera de compra',
            self::AUTHORIZE_PARTIAL_PRODUCTION => 'Producción parcial autorizada',
        };
    }
}
