<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/** Reason retained with a controlled action so the QR unit remains traceable. */
enum InventoryStockUnitOperationReason: string
{
    case QUALITY_REVIEW = 'QUALITY_REVIEW';
    case DAMAGE = 'DAMAGE';
    case EXPIRED = 'EXPIRED';
    case RETURN_TO_SUPPLIER = 'RETURN_TO_SUPPLIER';
    case CORRECTION = 'CORRECTION';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::QUALITY_REVIEW => 'Revisión de calidad',
            self::DAMAGE => 'Daño o no conformidad',
            self::EXPIRED => 'Caducidad',
            self::RETURN_TO_SUPPLIER => 'Devolución a proveedor',
            self::CORRECTION => 'Corrección verificada',
            self::OTHER => 'Otro motivo documentado',
        };
    }

    public function canBlock(): bool
    {
        return match ($this) {
            self::QUALITY_REVIEW, self::DAMAGE, self::EXPIRED, self::OTHER => true,
            self::RETURN_TO_SUPPLIER, self::CORRECTION => false,
        };
    }

    public function canRelease(): bool
    {
        return match ($this) {
            self::QUALITY_REVIEW, self::CORRECTION, self::OTHER => true,
            self::DAMAGE, self::EXPIRED, self::RETURN_TO_SUPPLIER => false,
        };
    }

    public function canDispose(): bool
    {
        return match ($this) {
            self::DAMAGE, self::EXPIRED, self::RETURN_TO_SUPPLIER, self::OTHER => true,
            self::QUALITY_REVIEW, self::CORRECTION => false,
        };
    }
}
