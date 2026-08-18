<?php

declare(strict_types=1);

namespace App\Enum\Catalog;

/** Tipo de valor que una característica comercial puede solicitar. */
enum CommercialCharacteristicInputType: string
{
    case SELECT = 'SELECT';
    case DECIMAL = 'DECIMAL';
    case TEXT = 'TEXT';
    case BOOLEAN = 'BOOLEAN';

    public function label(): string
    {
        return match ($this) {
            self::SELECT => 'Lista de opciones',
            self::DECIMAL => 'Número decimal',
            self::TEXT => 'Texto corto',
            self::BOOLEAN => 'Sí / No',
        };
    }

    public function supportsOptions(): bool
    {
        return $this === self::SELECT;
    }
}
