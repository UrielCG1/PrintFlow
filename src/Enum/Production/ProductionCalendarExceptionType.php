<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum ProductionCalendarExceptionType: string
{
    case HOLIDAY = 'holiday';
    case MAINTENANCE = 'maintenance';
    case OUTAGE = 'outage';
    case OVERTIME = 'overtime';

    public function label(): string
    {
        return match ($this) {
            self::HOLIDAY => 'Feriado',
            self::MAINTENANCE => 'Mantenimiento',
            self::OUTAGE => 'Avería o paro',
            self::OVERTIME => 'Horas extraordinarias autorizadas',
        };
    }
}
