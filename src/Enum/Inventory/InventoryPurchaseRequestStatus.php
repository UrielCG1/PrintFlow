<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Internal supply-request lifecycle; it never represents stock on hand. */
enum InventoryPurchaseRequestStatus: string
{
    case DRAFT = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Preparada',
            self::IN_PROGRESS => 'Compra en seguimiento',
            self::CLOSED => 'Recibida completa',
            self::CANCELLED => 'Cancelada',
        };
    }
}
