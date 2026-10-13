<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryCycleCountStatus;
use PHPUnit\Framework\TestCase;

final class InventoryCycleCountTest extends TestCase
{
    public function testItSnapshotsTheQrUnitBalanceAndRequiresAReasonForADifference(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '10');
        $count = new InventoryCycleCount(
            $stockUnit,
            new User(),
            'cycle-count-unit-test-0001',
            hash('sha256', 'cycle-count-unit-test'),
            '7.5',
            'Medido antes del corte',
        );

        self::assertSame('10.000000', $count->getExpectedQuantity());
        self::assertSame(1, $count->getStockUnitVersion());
        self::assertSame('7.500000', $count->getCountedQuantity());
        self::assertSame('-2.500000', $count->getDifferenceQuantity());
        self::assertSame(InventoryCycleCountStatus::PENDING_REVIEW, $count->getStatus());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('motivo del ajuste');
        $count->approve(new User(), null, null);
    }

    public function testItCanConfirmAReadingWithoutDifferenceAndMarkAnObsoleteReading(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '2');
        $confirmed = new InventoryCycleCount(
            $stockUnit,
            new User(),
            'cycle-count-unit-test-0002',
            hash('sha256', 'cycle-count-unit-test-2'),
            '2',
        );
        $confirmed->approve(new User(), null, null);

        self::assertTrue($confirmed->isApproved());

        $stale = new InventoryCycleCount(
            $stockUnit,
            new User(),
            'cycle-count-unit-test-0003',
            hash('sha256', 'cycle-count-unit-test-3'),
            '1',
        );
        $stale->markStale(new User());

        self::assertTrue($stale->isStale());
        self::assertSame('El saldo cambió después de la lectura; se requiere un reconteo.', $stale->getReviewNote());
    }

    private function variant(): MaterialVariant
    {
        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1');

        return new MaterialVariant(new Material(), 'VINIL-BLANCO-152', $unit, $unit, $unit);
    }
}
