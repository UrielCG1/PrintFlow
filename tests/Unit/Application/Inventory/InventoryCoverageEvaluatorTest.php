<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryCoverageEvaluator;
use PHPUnit\Framework\TestCase;

final class InventoryCoverageEvaluatorTest extends TestCase
{
    public function testItReportsFullCoverageWithoutShortage(): void
    {
        $coverage = (new InventoryCoverageEvaluator())->evaluate('10', '12.5');

        self::assertTrue($coverage->isFullyCovered());
        self::assertFalse($coverage->hasPartialCoverage());
        self::assertSame('10.000000', $coverage->coveredQuantity);
        self::assertSame('0.000000', $coverage->shortageQuantity);
    }

    public function testItReportsPartialCoverageAndExactShortage(): void
    {
        $coverage = (new InventoryCoverageEvaluator())->evaluate('10', '3.25');

        self::assertFalse($coverage->isFullyCovered());
        self::assertTrue($coverage->hasPartialCoverage());
        self::assertSame('3.250000', $coverage->coveredQuantity);
        self::assertSame('6.750000', $coverage->shortageQuantity);
    }

    public function testItReportsACompleteShortageWhenNothingIsAvailable(): void
    {
        $coverage = (new InventoryCoverageEvaluator())->evaluate('2', '0');

        self::assertFalse($coverage->isFullyCovered());
        self::assertFalse($coverage->hasPartialCoverage());
        self::assertSame('2.000000', $coverage->shortageQuantity);
    }
}
