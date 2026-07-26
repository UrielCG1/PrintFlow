<?php

namespace App\Enum\Catalog;

enum CommercialItemType: string
{
    case PRODUCT = 'PRODUCT';
    case SERVICE = 'SERVICE';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'Producto',
            self::SERVICE => 'Servicio',
        };
    }
}