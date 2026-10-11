<?php

declare(strict_types=1);

namespace App\Enum\Production;

/** Estado de un intento concreto de ejecutar una operación planificada. */
enum ProductionOperationExecutionStatus: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case PAUSED = 'PAUSED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return match ($this) {
            self::IN_PROGRESS, self::PAUSED => true,
            self::COMPLETED, self::CANCELLED => false,
        };
    }
}
