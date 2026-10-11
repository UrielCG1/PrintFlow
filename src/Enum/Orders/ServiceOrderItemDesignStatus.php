<?php

declare(strict_types=1);

namespace App\Enum\Orders;

enum ServiceOrderItemDesignStatus: string
{
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case IN_REVIEW = 'IN_REVIEW';
    case READY_FOR_PRINT = 'READY_FOR_PRINT';
    case BLOCKED = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'Pendiente de Diseño',
            self::IN_REVIEW => 'En revisión de Diseño',
            self::READY_FOR_PRINT => 'Lista para Impresión',
            self::BLOCKED => 'Bloqueada en Diseño',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'warning',
            self::IN_REVIEW => 'info',
            self::READY_FOR_PRINT => 'success',
            self::BLOCKED => 'danger',
        };
    }
}
