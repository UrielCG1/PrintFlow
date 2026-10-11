<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum ProductionFilePurpose: string
{
    case CLIENT_ORIGINAL = 'CLIENT_ORIGINAL';
    case PREPARED_ARTWORK = 'PREPARED_ARTWORK';
    case DESIGN_REFERENCE = 'DESIGN_REFERENCE';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT_ORIGINAL => 'Original del cliente',
            self::PREPARED_ARTWORK => 'Arte preparado para impresión',
            self::DESIGN_REFERENCE => 'Captura de referencia',
        };
    }
}
