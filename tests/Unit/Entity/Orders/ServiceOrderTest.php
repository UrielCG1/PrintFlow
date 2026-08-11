<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Enum\Orders\ServiceOrderStatus;
use PHPUnit\Framework\TestCase;

final class ServiceOrderTest extends TestCase
{
    public function testItStartsPendingPlanningAndNormalizesItsFolio(): void
    {
        $serviceOrder = (new ServiceOrder())->setFolio(' os-2026-000001 ');

        self::assertSame(ServiceOrderStatus::PENDING_PLANNING, $serviceOrder->getStatus());
        self::assertSame('OS-2026-000001', $serviceOrder->getFolio());
        self::assertNull($serviceOrder->getCommitmentDate());
    }

    public function testItRejectsAnInvalidServiceOrderFolio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ServiceOrder())->setFolio('OS 2026 1');
    }
}
