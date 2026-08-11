<?php

declare(strict_types=1);

namespace App\Enum\Orders;

/**
 * El primer estado es deliberadamente operativo y no de producción.
 * La planificación, asignación de equipo y ejecución se incorporarán en las
 * subfases posteriores sin reinterpretar órdenes ya creadas.
 */
enum ServiceOrderStatus: string
{
    case PENDING_PLANNING = 'PENDING_PLANNING';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_PLANNING => 'Pendiente de planificación',
        };
    }
}
