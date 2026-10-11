<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Enum\Production\ProductionOperationExecutionStatus;
use PHPUnit\Framework\TestCase;

final class ProductionOperationExecutionTest extends TestCase
{
    public function testItKeepsTheAttemptHistoryInsteadOfResettingThePlan(): void
    {
        $plan = new ServiceOrderOperationPlan();
        $operator = new User();
        $execution = new ProductionOperationExecution($plan, $operator, $operator, 1);

        $plan->addExecution($execution);
        $execution->pause('Se revisa la configuración del archivo.')->resume()->complete('Trabajo terminado sin incidencias.');

        self::assertSame(ProductionOperationExecutionStatus::COMPLETED, $execution->getStatus());
        self::assertFalse($execution->isOpen());
        self::assertSame(2, $plan->nextExecutionAttemptNumber());
        self::assertTrue($plan->hasCompletedExecution());
    }

    public function testItDoesNotAllowClosingAnAttemptThatIsPaused(): void
    {
        $execution = new ProductionOperationExecution(new ServiceOrderOperationPlan(), new User(), new User(), 1);
        $execution->pause('Falta confirmar el material seleccionado.');

        $this->expectException(\DomainException::class);
        $execution->complete('No debe cerrar mientras esté pausado.');
    }

    public function testActiveTimeExcludesPausedPeriodsAcrossResumption(): void
    {
        $execution = new ProductionOperationExecution(new ServiceOrderOperationPlan(), new User(), new User(), 1);
        $started = $execution->getStartedAt();
        $pausedAt = $started->modify('+90 seconds');
        $resumedAt = $started->modify('+1 hour');
        $completedAt = $resumedAt->modify('+5 minutes');

        $execution->pause('Espera de material.', $pausedAt);
        self::assertSame(90, $execution->getActiveSeconds());
        self::assertSame(90, $execution->getActiveSecondsAt($resumedAt));

        $execution->resume($resumedAt);
        $execution->complete('Trabajo terminado.', $completedAt);

        self::assertSame(390, $execution->getActiveSeconds());
    }
}
