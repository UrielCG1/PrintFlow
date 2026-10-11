<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum ProductionCommitmentRisk: string
{
    case UNPLANNED = 'unplanned';
    case NO_COMMITMENT = 'no_commitment';
    case LATE = 'late';
    case TIGHT = 'tight';
    case ON_TRACK = 'on_track';

    public function label(): string
    {
        return match ($this) {
            self::UNPLANNED => 'Sin pronóstico',
            self::NO_COMMITMENT => 'Sin compromiso',
            self::LATE => 'En riesgo',
            self::TIGHT => 'Holgura baja',
            self::ON_TRACK => 'En tiempo',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::UNPLANNED, self::NO_COMMITMENT => 'secondary',
            self::LATE => 'danger',
            self::TIGHT => 'warning',
            self::ON_TRACK => 'success',
        };
    }
}
