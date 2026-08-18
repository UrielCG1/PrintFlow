<?php

declare(strict_types=1);

namespace App\Enum\Quotations;

/**
 * Esquemas técnicos soportados por el cotizador interno.
 *
 * El perfil pertenece al concepto comercial, no al producto/material de
 * inventario. Así las partidas históricas pueden conservar un snapshot de
 * sus especificaciones aunque el catálogo cambie después.
 */
enum QuotationItemSpecificationProfile: string
{
    case NONE = 'NONE';
    case LARGE_FORMAT = 'LARGE_FORMAT';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sin especificaciones técnicas',
            self::LARGE_FORMAT => 'Gran formato (ancho y alto terminados)',
        };
    }
}
