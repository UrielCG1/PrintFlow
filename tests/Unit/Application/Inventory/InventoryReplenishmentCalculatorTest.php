<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryReplenishmentCalculator;
use PHPUnit\Framework\TestCase;

final class InventoryReplenishmentCalculatorTest extends TestCase
{
    public function testItCombinesOrderShortageWithTheGapToMinimum(): void
    {
        $calculation = (new InventoryReplenishmentCalculator())->calculate(
            availableQuantity: '2',
            minimumStock: '5',
            reorderPoint: '6',
            reorderQuantity: '0',
            orderShortageQuantity: '4',
        );

        self::assertSame('2.000000', $calculation->availableQuantity);
        self::assertSame('4.000000', $calculation->orderShortageQuantity);
        self::assertSame('3.000000', $calculation->minimumGapQuantity);
        self::assertSame('7.000000', $calculation->suggestedQuantity);
        self::assertTrue($calculation->reorderPointReached);
    }

    public function testItUsesTheConfiguredReorderQuantityWhenItIsLarger(): void
    {
        $calculation = (new InventoryReplenishmentCalculator())->calculate(
            availableQuantity: '4',
            minimumStock: '5',
            reorderPoint: '5',
            reorderQuantity: '20',
            orderShortageQuantity: '0',
        );

        self::assertSame('1.000000', $calculation->minimumGapQuantity);
        self::assertSame('20.000000', $calculation->suggestedQuantity);
    }

    public function testItDoesNotSuggestAReplenishmentAboveThePolicyThreshold(): void
    {
        $calculation = (new InventoryReplenishmentCalculator())->calculate(
            availableQuantity: '8',
            minimumStock: '5',
            reorderPoint: '6',
            reorderQuantity: '20',
            orderShortageQuantity: '0',
        );

        self::assertSame('0.000000', $calculation->suggestedQuantity);
        self::assertFalse($calculation->reorderPointReached);
    }

    public function testItSubtractsOnlyAConfirmedPurchaseInProgress(): void
    {
        $calculation = (new InventoryReplenishmentCalculator())->calculate(
            availableQuantity: '0',
            minimumStock: '5',
            reorderPoint: '5',
            reorderQuantity: '0',
            orderShortageQuantity: '4',
            pendingPurchaseQuantity: '6',
        );

        self::assertSame('6.000000', $calculation->pendingPurchaseQuantity);
        self::assertSame('3.000000', $calculation->suggestedQuantity);
    }
}
