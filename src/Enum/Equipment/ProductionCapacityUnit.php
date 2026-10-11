<?php

declare(strict_types=1);

namespace App\Enum\Equipment;

enum ProductionCapacityUnit: string
{
    case SQUARE_METERS_PER_HOUR = 'm2_per_hour';
    case LINEAR_METERS_PER_HOUR = 'linear_m_per_hour';
    case CUT_CENTIMETERS_PER_SECOND = 'cut_cm_per_second';

    public function loadUnit(): ProductionLoadUnit
    {
        return match ($this) {
            self::SQUARE_METERS_PER_HOUR => ProductionLoadUnit::SQUARE_METERS,
            self::LINEAR_METERS_PER_HOUR => ProductionLoadUnit::LINEAR_METERS,
            self::CUT_CENTIMETERS_PER_SECOND => ProductionLoadUnit::CUT_CENTIMETERS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SQUARE_METERS_PER_HOUR => 'm² por hora',
            self::LINEAR_METERS_PER_HOUR => 'metros lineales por hora',
            self::CUT_CENTIMETERS_PER_SECOND => 'centímetros de trayectoria por segundo',
        };
    }
}
