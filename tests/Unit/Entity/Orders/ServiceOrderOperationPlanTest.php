<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Users\User;
use PHPUnit\Framework\TestCase;

final class ServiceOrderOperationPlanTest extends TestCase
{
    public function testItRejectsASequenceLowerThanOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ServiceOrderOperationPlan())->setSequenceNumber(0);
    }

    public function testItCanBeDeactivatedWithoutRemovingItsHistory(): void
    {
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->deactivate(new User());

        self::assertFalse($plan->isActive());
        self::assertNotNull($plan->getDeactivatedAt());
        self::assertNotNull($plan->getDeactivatedBy());
    }

    public function testItCanBeReactivatedAfterRemovalFromDraftPlanning(): void
    {
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->deactivate(new User())
            ->reactivate();

        self::assertTrue($plan->isActive());
        self::assertNull($plan->getDeactivatedAt());
        self::assertNull($plan->getDeactivatedBy());
    }
}
