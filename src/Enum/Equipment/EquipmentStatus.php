<?php

declare(strict_types=1);

namespace App\Enum\Equipment;

enum EquipmentStatus: string
{
    case AVAILABLE = 'available';
    case MAINTENANCE = 'maintenance';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Disponible',
            self::MAINTENANCE => 'En mantenimiento',
            self::INACTIVE => 'Inactivo',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::AVAILABLE => 'success',
            self::MAINTENANCE => 'warning',
            self::INACTIVE => 'neutral',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AVAILABLE => [self::MAINTENANCE, self::INACTIVE],
            self::MAINTENANCE => [self::AVAILABLE, self::INACTIVE],
            self::INACTIVE => [self::AVAILABLE],
        };
    }

    public function transitionLabel(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Marcar disponible',
            self::MAINTENANCE => 'Enviar a mantenimiento',
            self::INACTIVE => 'Dar de baja',
        };
    }

    public function isSelectableForFutureExecution(): bool
    {
        return $this === self::AVAILABLE;
    }
}