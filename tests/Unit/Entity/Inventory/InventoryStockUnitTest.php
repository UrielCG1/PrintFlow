<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\InventoryLot;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryStockUnitKind;
use App\Enum\Inventory\InventoryStockUnitStatus;
use PHPUnit\Framework\TestCase;

final class InventoryStockUnitTest extends TestCase
{
    public function testItGeneratesAnOpaqueQrPayloadAndTracksThePhysicalBalance(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '10');

        self::assertStringStartsWith('IMU-', $stockUnit->getUnitCode());
        self::assertMatchesRegularExpression('/^PF:MAT:1:[a-f0-9]{48}$/', $stockUnit->getQrPayload());
        self::assertSame('10.000000', $stockUnit->getRemainingQuantity());
        self::assertTrue($stockUnit->isAvailable());

        $stockUnit->consume('3.25');

        self::assertSame('6.750000', $stockUnit->getRemainingQuantity());
        self::assertSame(InventoryStockUnitStatus::AVAILABLE, $stockUnit->getStatus());

        $stockUnit->consume('6.75');

        self::assertSame('0.000000', $stockUnit->getRemainingQuantity());
        self::assertSame(InventoryStockUnitStatus::EXHAUSTED, $stockUnit->getStatus());
    }

    public function testItRequiresAParentForARecoverableSurplus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unidad física de origen');

        new InventoryStockUnit($this->variant(), '1', InventoryStockUnitKind::SURPLUS);
    }

    public function testItRejectsConsumptionBeyondThePhysicalBalance(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '2');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('supera el saldo');

        $stockUnit->consume('2.01');
    }

    public function testItReconcilesAPhysicalCountAndCanRecoverAnExhaustedLabel(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '4');
        self::assertSame(1, $stockUnit->getInventoryVersion());
        $stockUnit->consume('4');

        $stockUnit->reconcileCountedQuantity('1.25');

        self::assertSame('1.250000', $stockUnit->getRemainingQuantity());
        self::assertSame(InventoryStockUnitStatus::AVAILABLE, $stockUnit->getStatus());
        self::assertSame(3, $stockUnit->getInventoryVersion());

        $stockUnit->reconcileCountedQuantity('0');

        self::assertSame('0.000000', $stockUnit->getRemainingQuantity());
        self::assertSame(InventoryStockUnitStatus::EXHAUSTED, $stockUnit->getStatus());
        self::assertSame(4, $stockUnit->getInventoryVersion());
    }

    public function testItSeparatesARecoverableSurplusWithoutChangingTotalPhysicalStock(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '10');
        $stockUnit->setStorageLocation('Bodega A / Rack 2');
        $stockUnit->consume('2');

        $surplus = $stockUnit->separateSurplus('3', 'Bodega A / Retazos', ['usable_width_cm' => 80]);

        self::assertSame('5.000000', $stockUnit->getRemainingQuantity());
        self::assertSame(InventoryStockUnitKind::SURPLUS, $surplus->getKind());
        self::assertSame($stockUnit, $surplus->getParentStockUnit());
        self::assertSame('3.000000', $surplus->getInitialQuantity());
        self::assertSame('3.000000', $surplus->getRemainingQuantity());
        self::assertSame('Bodega A / Retazos', $surplus->getStorageLocation());
        self::assertSame(['usable_width_cm' => 80], $surplus->getPhysicalAttributes());
    }

    public function testASecondPrintingCanSplitTheRemainingRollWithoutDuplicatingStock(): void
    {
        $variant = $this->variant();
        $stockUnit = new InventoryStockUnit($variant, '2');
        $stockUnit->setStorageLocation('QA-RACK-01');
        $balance = new MaterialVariantCost($variant);
        $balance->increaseOnHand('2');
        $balance->commit('1');

        $stockUnit->consume('1');
        $balance->consume('1', '1');
        $surplus = $stockUnit->separateSurplus('0.5');

        self::assertSame('0.500000', $stockUnit->getRemainingQuantity());
        self::assertSame('0.500000', $surplus->getRemainingQuantity());
        self::assertNotSame($stockUnit->getUnitCode(), $surplus->getUnitCode());
        self::assertNotSame($stockUnit->getQrPayload(), $surplus->getQrPayload());
        self::assertSame($stockUnit, $surplus->getParentStockUnit());
        self::assertSame('QA-RACK-01', $surplus->getStorageLocation());
        self::assertSame('1.000000', $balance->getOnHandQuantity());
        self::assertSame('0.000000', $balance->getCommittedQuantity());
    }

    public function testItRejectsALotFromAnotherVariant(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '2');
        $otherLot = new InventoryLot($this->variant('VINIL-NEGRO-152'), 'LOT-NEGRO-001');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('misma variante');

        $stockUnit->assignLot($otherLot);
    }

    public function testItKeepsPhysicalAndAvailableBalancesSeparate(): void
    {
        $balance = new MaterialVariantCost($this->variant());

        $balance->increaseOnHand('12.5');

        self::assertSame('12.500000', $balance->getOnHandQuantity());
        self::assertSame('0.000000', $balance->getBlockedQuantity());
        self::assertSame('0.000000', $balance->getCommittedQuantity());
        self::assertSame('12.500000', $balance->getAvailableQuantity());
    }

    public function testItStoresThresholdsOnTheConcreteMaterialVariant(): void
    {
        $variant = $this->variant();

        $variant->setInventoryThresholds('10', '12.5', '30');

        self::assertSame('10.000000', $variant->getMinimumStock());
        self::assertSame('12.500000', $variant->getReorderPoint());
        self::assertSame('30.000000', $variant->getReorderQuantity());
    }

    public function testItCommitsAndReleasesAvailableMaterialWithoutReducingPhysicalStock(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('12');

        $balance->commit('5.5');

        self::assertSame('12.000000', $balance->getOnHandQuantity());
        self::assertSame('5.500000', $balance->getCommittedQuantity());
        self::assertSame('6.500000', $balance->getAvailableQuantity());

        $balance->releaseCommitment('2');

        self::assertSame('3.500000', $balance->getCommittedQuantity());
        self::assertSame('8.500000', $balance->getAvailableQuantity());
    }

    public function testItConsumesPhysicalStockAndOnlyTheRelatedCommittedQuantity(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('10');
        $balance->commit('6');

        $balance->consume('4', '4');

        self::assertSame('6.000000', $balance->getOnHandQuantity());
        self::assertSame('2.000000', $balance->getCommittedQuantity());
        self::assertSame('4.000000', $balance->getAvailableQuantity());

        $balance->consume('1');

        self::assertSame('5.000000', $balance->getOnHandQuantity());
        self::assertSame('2.000000', $balance->getCommittedQuantity());
        self::assertSame('3.000000', $balance->getAvailableQuantity());
    }

    public function testItAppliesCountAdjustmentsWithoutReducingCommittedOrBlockedStock(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('10');
        $balance->commit('6');

        $balance->applyCountAdjustment('-2');

        self::assertSame('8.000000', $balance->getOnHandQuantity());
        self::assertSame('6.000000', $balance->getCommittedQuantity());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('bloqueada o comprometida');

        $balance->applyCountAdjustment('-3');
    }

    public function testItBlocksAndReleasesPhysicalBalanceBeforeChangingTheQrUnitCondition(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('10');

        $balance->block('4.25');

        self::assertSame('10.000000', $balance->getOnHandQuantity());
        self::assertSame('4.250000', $balance->getBlockedQuantity());
        self::assertSame('5.750000', $balance->getAvailableQuantity());

        $balance->releaseBlocked('1.5');

        self::assertSame('2.750000', $balance->getBlockedQuantity());
        self::assertSame('7.250000', $balance->getAvailableQuantity());
    }

    public function testItKeepsBlockedBalanceAlignedWhenABlockedQrUnitIsRecounted(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('10');
        $balance->block('4');

        $balance->applyCountAdjustment('-1.25', '-1.25');

        self::assertSame('8.750000', $balance->getOnHandQuantity());
        self::assertSame('2.750000', $balance->getBlockedQuantity());
        self::assertSame('6.000000', $balance->getAvailableQuantity());
    }

    public function testItDoesNotAllowAWithdrawalToInvalidateCommittedMaterial(): void
    {
        $balance = new MaterialVariantCost($this->variant());
        $balance->increaseOnHand('10');
        $balance->commit('6');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('bloqueada o comprometida');

        $balance->removeOnHand('5');
    }

    private function variant(string $code = 'VINIL-BLANCO-152'): MaterialVariant
    {
        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1');

        return new MaterialVariant(new Material(), $code, $unit, $unit, $unit);
    }
}
