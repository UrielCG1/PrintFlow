<?php

namespace App\Enum\Quotations;

enum QuotationStatus: string
{
    case REQUEST = 'REQUEST';
    case IN_REVIEW = 'IN_REVIEW';
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case ACCEPTED_WITH_CHANGES = 'ACCEPTED_WITH_CHANGES';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
    case SUPERSEDED = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::REQUEST => 'Solicitud',
            self::IN_REVIEW => 'En revisión',
            self::DRAFT => 'Borrador',
            self::ISSUED => 'Emitida',
            self::SENT => 'Enviada',
            self::ACCEPTED => 'Aceptada',
            self::ACCEPTED_WITH_CHANGES => 'Aceptada con cambios',
            self::REJECTED => 'Rechazada',
            self::EXPIRED => 'Expirada',
            self::CANCELLED => 'Cancelada',
            self::SUPERSEDED => 'Reemplazada por una revisión',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function canBeSent(): bool
    {
        return $this === self::ISSUED || $this === self::SENT;
    }

    public function canReceiveDecision(): bool
    {
        return $this === self::ISSUED || $this === self::SENT;
    }

    public function canBeRevised(): bool
    {
        return $this === self::ISSUED
            || $this === self::SENT
            || $this === self::ACCEPTED_WITH_CHANGES
            || $this === self::REJECTED
            || $this === self::EXPIRED
            || $this === self::CANCELLED;
    }

    /*
     * Futuro: si se requiere administrar desde UI las etiquetas, orden,
     * transiciones o reglas de cada estado, se podrá introducir el catálogo
     * quotation_statuses. El código persistido del estado seguirá siendo
     * estable para no afectar las cotizaciones históricas.
     */
}
