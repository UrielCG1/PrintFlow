<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** A controlled warehouse action applied to one identifiable QR unit. */
enum InventoryStockUnitOperationType: string
{
    case BLOCK = 'BLOCK';
    case RELEASE = 'RELEASE';
    case DISPOSE = 'DISPOSE';

    public function label(): string
    {
        return match ($this) {
            self::BLOCK => 'Bloquear unidad',
            self::RELEASE => 'Liberar unidad',
            self::DISPOSE => 'Retirar de inventario',
        };
    }
}
