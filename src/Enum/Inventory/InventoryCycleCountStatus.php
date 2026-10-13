<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** State of a QR-unit count until its discrepancy has been reviewed. */
enum InventoryCycleCountStatus: string
{
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case STALE = 'STALE';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'Pendiente de revisión',
            self::APPROVED => 'Confirmado',
            self::REJECTED => 'Rechazado',
            self::STALE => 'Reconteo requerido',
        };
    }
}
