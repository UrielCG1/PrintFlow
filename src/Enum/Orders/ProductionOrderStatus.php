<?php

declare(strict_types=1);

namespace App\Enum\Orders;

/**
 * Estado resumido de fabricación de una orden.
 *
 * La planificación y la entrega se conservan en ejes distintos para que una
 * orden planificada no se presente como fabricada, ni una orden terminada
 * como entregada.
 */
enum ProductionOrderStatus: string
{
    case WAITING_DESIGN = 'WAITING_DESIGN';
    case IN_PRODUCTION = 'IN_PRODUCTION';
    case WAITING_QUALITY = 'WAITING_QUALITY';
    case QUALITY_REWORK = 'QUALITY_REWORK';
    case PRODUCTION_COMPLETED = 'PRODUCTION_COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::WAITING_DESIGN => 'Pendiente de Diseño',
            self::IN_PRODUCTION => 'En producción',
            self::WAITING_QUALITY => 'Pendiente de Calidad',
            self::QUALITY_REWORK => 'En retrabajo de Calidad',
            self::PRODUCTION_COMPLETED => 'Producción terminada',
            self::CANCELLED => 'Producción cancelada',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::WAITING_DESIGN, self::WAITING_QUALITY => 'warning',
            self::IN_PRODUCTION => 'info',
            self::QUALITY_REWORK => 'danger',
            self::PRODUCTION_COMPLETED => 'success',
            self::CANCELLED => 'neutral',
        };
    }
}
