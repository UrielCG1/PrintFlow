<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Distingue material recién llegado del que ya estaba en bodega al registrarlo. */
enum InventoryReceiptType: string
{
    case RECEIPT = 'RECEIPT';
    case INITIAL_COUNT = 'INITIAL_COUNT';

    public function label(): string
    {
        return match ($this) {
            self::RECEIPT => 'Recepción',
            self::INITIAL_COUNT => 'Ya estaba en bodega',
        };
    }
}
