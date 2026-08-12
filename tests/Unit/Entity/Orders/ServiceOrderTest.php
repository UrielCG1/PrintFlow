<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
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

    public function testItRequiresACommitmentDateBeforeItCanBeMarkedPlanned(): void
    {
        $this->expectException(\DomainException::class);

        (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->markPlanned(new User());
    }

    public function testItRecordsWhoConfirmedThePlanning(): void
    {
        $actor = new User();
        $serviceOrder = (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->setCommitmentDate(new \DateTimeImmutable('2026-08-20'))
            ->markPlanned($actor);

        self::assertSame(ServiceOrderStatus::PLANNED, $serviceOrder->getStatus());
        self::assertSame($actor, $serviceOrder->getPlannedBy());
        self::assertNotNull($serviceOrder->getPlannedAt());
    }
}
