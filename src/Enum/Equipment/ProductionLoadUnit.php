<?php

declare(strict_types=1);

namespace App\Enum\Equipment;

enum ProductionLoadUnit: string
{
    case SQUARE_METERS = 'm2';
    case LINEAR_METERS = 'linear_m';
    case CUT_CENTIMETERS = 'cut_cm';

    public function label(): string
    {
        return match ($this) {
            self::SQUARE_METERS => 'm²',
            self::LINEAR_METERS => 'm lineales',
            self::CUT_CENTIMETERS => 'cm de trayectoria',
        };
    }
}
