<?php

namespace App\Enum\Quotations;

enum QuotationStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::ISSUED => 'Emitida',
            self::SENT => 'Enviada',
            self::ACCEPTED => 'Aceptada',
            self::REJECTED => 'Rechazada',
            self::EXPIRED => 'Expirada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /*
     * Futuro: si se requiere administrar desde UI las etiquetas, orden,
     * transiciones o reglas de cada estado, se podrá introducir el catálogo
     * quotation_statuses. El código persistido del estado seguirá siendo
     * estable para no afectar las cotizaciones históricas.
     */
}