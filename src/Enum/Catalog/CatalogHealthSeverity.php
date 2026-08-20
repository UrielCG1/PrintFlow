<?php

declare(strict_types=1);

namespace App\Enum\Catalog;

/**
 * Severidad funcional utilizada por el diagnóstico del catálogo comercial.
 *
 * INCOMPLETE identifica inconsistencias que pueden impedir o degradar el uso
 * correcto de un registro. ATTENTION marca configuraciones válidas que conviene
 * revisar. UNUSED identifica registros activos que hoy no participan en ningún
 * flujo conocido del catálogo.
 */
enum CatalogHealthSeverity: string
{
    case INCOMPLETE = 'incomplete';
    case ATTENTION = 'attention';
    case UNUSED = 'unused';

    public function label(): string
    {
        return match ($this) {
            self::INCOMPLETE => 'Incompleto',
            self::ATTENTION => 'Atención',
            self::UNUSED => 'Sin uso',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::INCOMPLETE => 30,
            self::ATTENTION => 20,
            self::UNUSED => 10,
        };
    }

    /** @return list<self> */
    public static function orderedCases(): array
    {
        return [self::INCOMPLETE, self::ATTENTION, self::UNUSED];
    }
}
