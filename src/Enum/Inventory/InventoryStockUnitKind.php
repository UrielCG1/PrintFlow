<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Describe el origen físico de una unidad identificada en bodega. */
enum InventoryStockUnitKind: string
{
    case RECEIVED = 'RECEIVED';
    case SURPLUS = 'SURPLUS';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVED => 'Unidad recibida',
            self::SURPLUS => 'Sobrante recuperable',
        };
    }
}
