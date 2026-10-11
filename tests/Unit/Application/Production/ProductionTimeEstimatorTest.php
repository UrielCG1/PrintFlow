<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionTimeEstimator;
use App\Enum\Equipment\ProductionCapacityUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionTimeEstimatorTest extends TestCase
{
    #[DataProvider('physicalLoads')]
    public function testItCalculatesTimeInTheCapacityUnit(int $expected, ProductionCapacityUnit $unit, float $load, float $rate, int $setup, float $efficiency, int $cleanup = 0): void
    {
        self::assertSame($expected, ProductionTimeEstimator::calculateMinutes($unit, $load, $rate, $setup, $efficiency, $cleanup));
    }

    public static function physicalLoads(): iterable
    {
        yield 'print m2/hour with setup and efficiency' => [74, ProductionCapacityUnit::SQUARE_METERS_PER_HOUR, 12, 15, 10, 75];
        yield 'setup and cleaning are both included' => [77, ProductionCapacityUnit::SQUARE_METERS_PER_HOUR, 12, 15, 10, 75, 3];
        yield 'lamination linear meters/hour' => [65, ProductionCapacityUnit::LINEAR_METERS_PER_HOUR, 100, 100, 5, 100];
        yield 'cut path centimeters/second' => [3, ProductionCapacityUnit::CUT_CENTIMETERS_PER_SECOND, 6000, 50, 1, 100];
        yield 'round up fractional minute' => [7, ProductionCapacityUnit::LINEAR_METERS_PER_HOUR, 2, 20, 1, 100];
    }

    public function testItRejectsEfficiencyOutsideValidatedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductionTimeEstimator::calculateMinutes(ProductionCapacityUnit::CUT_CENTIMETERS_PER_SECOND, 100, 50, 0, 0);
    }
}
