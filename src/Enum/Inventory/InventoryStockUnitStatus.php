<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Estado actual de uso de una unidad física; no sustituye el libro de movimientos. */
enum InventoryStockUnitStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case BLOCKED = 'BLOCKED';
    case EXHAUSTED = 'EXHAUSTED';
    case DISPOSED = 'DISPOSED';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Disponible',
            self::BLOCKED => 'Bloqueada',
            self::EXHAUSTED => 'Agotada',
            self::DISPOSED => 'Desechada',
        };
    }
}
