<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum DesignAuthorizationType: string
{
    case CLIENT_ORIGINAL = 'CLIENT_ORIGINAL';
    case PREPARED_ARTWORK = 'PREPARED_ARTWORK';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT_ORIGINAL => 'Original viable para impresión',
            self::PREPARED_ARTWORK => 'Arte preparado por Diseño',
        };
    }
}
