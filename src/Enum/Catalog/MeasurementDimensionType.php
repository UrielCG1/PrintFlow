<?php

declare(strict_types=1);

namespace App\Enum\Catalog;

/**
 * Dimensión física o comercial de una unidad de medida.
 *
 * COUNT agrupa presentaciones que no tienen una conversión universal entre sí
 * (pieza, paquete, caja, rollo, servicio, etc.). Las demás dimensiones sí
 * pueden declarar una unidad base y un factor de conversión compatible.
 */
enum MeasurementDimensionType: string
{
    case COUNT = 'COUNT';
    case LENGTH = 'LENGTH';
    case AREA = 'AREA';
    case VOLUME = 'VOLUME';
    case MASS = 'MASS';
    case TIME = 'TIME';

    public function label(): string
    {
        return match ($this) {
            self::COUNT => 'Conteo y presentación',
            self::LENGTH => 'Longitud',
            self::AREA => 'Área',
            self::VOLUME => 'Volumen',
            self::MASS => 'Masa',
            self::TIME => 'Tiempo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::COUNT => 'Piezas y presentaciones comerciales sin una conversión universal entre sí.',
            self::LENGTH => 'Medidas lineales como metro, centímetro y milímetro.',
            self::AREA => 'Superficies como metro cuadrado y centímetro cuadrado.',
            self::VOLUME => 'Capacidad o volumen como litro y mililitro.',
            self::MASS => 'Peso o masa como kilogramo y gramo.',
            self::TIME => 'Duraciones utilizadas para servicios y operaciones.',
        };
    }

    /** @return list<self> */
    public static function orderedCases(): array
    {
        return [
            self::COUNT,
            self::LENGTH,
            self::AREA,
            self::TIME,
            self::VOLUME,
            self::MASS,
        ];
    }
}
