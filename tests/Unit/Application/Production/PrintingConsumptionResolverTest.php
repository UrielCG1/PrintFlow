<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\PrintingConsumptionResolver;
use App\Enum\Inventory\QuantitySource;
use PHPUnit\Framework\TestCase;

final class PrintingConsumptionResolverTest extends TestCase
{
    public function testConfirmedPlanProvidesTotalWithoutManualCapture(): void
    {
        $decision = (new PrintingConsumptionResolver())->resolve('6.000000', true, '', '', '', true);

        self::assertSame('6.000000', $decision['total']);
        self::assertSame('6.000000', $decision['planned']);
        self::assertSame(QuantitySource::DERIVED, $decision['source']);
        self::assertSame('plan confirmado', $decision['method']);
    }

    public function testDifferentMeasuredTotalPreservesPlanAndRequiresReason(): void
    {
        $decision = (new PrintingConsumptionResolver())->resolve('6', true, '7.5', 'medición manual', 'Reimpresión adicional', true);

        self::assertSame('7.500000', $decision['total']);
        self::assertSame('6.000000', $decision['planned']);
        self::assertSame(QuantitySource::MEASURED, $decision['source']);
        self::assertSame('Reimpresión adicional', $decision['adjustment_reason']);
    }

    public function testSubstituteCannotSilentlyUseOriginalPlan(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no tiene una cantidad prevista aplicable');

        (new PrintingConsumptionResolver())->resolve('6', false, '', '', '', true);
    }

    public function testDifferentTotalNeedsExplanation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Explica por qué');

        (new PrintingConsumptionResolver())->resolve('6', true, '7', 'medición manual', '', true);
    }

    public function testClosingRequiresConfirmationEvenWithPlannedQuantity(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Confirma el total');

        (new PrintingConsumptionResolver())->resolve('6', true, '', '', '', false);
    }
}
