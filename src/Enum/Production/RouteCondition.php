<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum RouteCondition: string
{
    case ALWAYS = 'ALWAYS';
    case CLIENT_FILE_PRESENT = 'CLIENT_FILE_PRESENT';
    case CLIENT_FILE_MISSING = 'CLIENT_FILE_MISSING';
    case SPECIFICATION_EQUALS = 'SPECIFICATION_EQUALS';

    public function label(): string
    {
        return match ($this) {
            self::ALWAYS => 'Siempre',
            self::CLIENT_FILE_PRESENT => 'El cliente adjuntó archivo',
            self::CLIENT_FILE_MISSING => 'El cliente no adjuntó archivo',
            self::SPECIFICATION_EQUALS => 'La especificación coincide',
        };
    }
}
