<?php

declare(strict_types=1);

namespace App\Enum\Orders;

/** Estado de cumplimiento logístico de una orden ya liberada por Calidad. */
enum ServiceOrderDeliveryStatus: string
{
    case NOT_READY = 'NOT_READY';
    case READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    case READY_FOR_SHIPMENT = 'READY_FOR_SHIPMENT';
    case SHIPPED = 'SHIPPED';
    case DELIVERED = 'DELIVERED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_READY => 'No disponible para entrega',
            self::READY_FOR_PICKUP => 'Lista para recoger',
            self::READY_FOR_SHIPMENT => 'Lista para envío',
            self::SHIPPED => 'Enviada',
            self::DELIVERED => 'Entregada',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::NOT_READY => 'neutral',
            self::READY_FOR_PICKUP, self::READY_FOR_SHIPMENT => 'info',
            self::SHIPPED => 'warning',
            self::DELIVERED => 'success',
        };
    }
}
