<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Distingue una entrada ordinaria del conteo que inaugura el saldo controlado. */
enum InventoryReceiptType: string
{
    case RECEIPT = 'RECEIPT';
    case INITIAL_COUNT = 'INITIAL_COUNT';

    public function label(): string
    {
        return match ($this) {
            self::RECEIPT => 'Recepción',
            self::INITIAL_COUNT => 'Conteo inicial',
        };
    }
}
