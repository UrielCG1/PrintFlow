<?php

declare(strict_types=1);

namespace App\Enum\Orders;

enum ProductionPriority: string
{
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function weight(): int
    {
        return match ($this) {
            self::NORMAL => 0,
            self::HIGH => 1,
            self::URGENT => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::HIGH => 'Alta',
            self::URGENT => 'Urgente',
        };
    }
}
