<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Users\User;
use PHPUnit\Framework\TestCase;

final class ServiceOrderTraceabilityEventTest extends TestCase
{
    public function testItCapturesAnImmutableOperationalEvent(): void
    {
        $order = (new ServiceOrder())->setFolio('OS-2026-000001');
        $event = new ServiceOrderTraceabilityEvent(
            $order,
            'production.order_initialized',
            new User(),
            null,
            $order->traceabilityStateSnapshot(),
            correlationId: 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
        );

        self::assertSame('production.order_initialized', $event->getEventType());
        self::assertSame($order, $event->getServiceOrder());
        self::assertSame($order->traceabilityStateSnapshot(), $event->getNewState());
        self::assertNull($event->getCommandId());
    }

    public function testItRejectsACommandIdWithoutTheRequiredFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceOrderTraceabilityEvent(
            (new ServiceOrder())->setFolio('OS-2026-000001'),
            'production.order_initialized',
            new User(),
            null,
            null,
            commandId: 'bad',
        );
    }
}
