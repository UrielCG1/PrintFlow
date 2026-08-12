<?php

declare(strict_types=1);

namespace App\Enum\Orders;

enum ServiceOrderStatus: string
{
    case PENDING_PLANNING = 'PENDING_PLANNING';
    case PLANNED = 'PLANNED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_PLANNING => 'Pendiente de planificación',
            self::PLANNED => 'Planificada',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::PENDING_PLANNING => 'warning',
            self::PLANNED => 'info',
        };
    }
}
