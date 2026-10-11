<?php

declare(strict_types=1);

namespace App\Enum\Orders;

/** The commercial delivery choice frozen when the work order is created. */
enum ServiceOrderFulfillmentMethod: string
{
    case PICKUP = 'pickup';
    case SHIPPING = 'shipping';
    case UNDEFINED = 'undefined';

    public function label(): string
    {
        return match ($this) {
            self::PICKUP => 'Recoger en sucursal',
            self::SHIPPING => 'Envío a domicilio',
            self::UNDEFINED => 'Por definir',
        };
    }
}
