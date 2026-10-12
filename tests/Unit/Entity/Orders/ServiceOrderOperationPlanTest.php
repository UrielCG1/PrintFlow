<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
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

    public function testLatestAttemptMustBeCompletedBeforeAnOperationIsConsideredComplete(): void
    {
        $operator = new User();
        $plan = new ServiceOrderOperationPlan();
        $first = new ProductionOperationExecution($plan, $operator, $operator, 1);
        $plan->addExecution($first);
        $first->complete('Primera ejecución completada.');

        self::assertTrue($plan->hasCompletedLatestExecution());

        $rework = new ProductionOperationExecution($plan, $operator, $operator, 2, $first);
        $plan->addExecution($rework);

        self::assertFalse($plan->hasCompletedLatestExecution());
        self::assertTrue($plan->hasExecutionOrigin($first));

        $rework->complete('Retrabajo completado.');
        self::assertTrue($plan->hasCompletedLatestExecution());
    }

    public function testMeasuredOperationTimeUsesExecutionActiveSecondsAndExcludesPauses(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-12 08:00:00 UTC');
        \Symfony\Component\Clock\Clock::set($clock);
        try {
            $operator = new User();
            $plan = new ServiceOrderOperationPlan();
            $execution = new ProductionOperationExecution($plan, $operator, $operator, 1);
            $plan->addExecution($execution);
            $started = $execution->getStartedAt();
            $execution->pause('Se espera confirmación.', $started->modify('+90 seconds'));
            $execution->resume($started->modify('+1 hour'));
            $execution->complete('Trabajo terminado.', $started->modify('+1 hour 5 minutes'));

            self::assertTrue($plan->hasStartedExecution());
            self::assertSame(390, $plan->getMeasuredActiveSeconds());
            self::assertSame(7, $plan->getMeasuredActiveMinutes());
        } finally {
            \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\NativeClock());
        }
    }
}
