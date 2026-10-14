<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\InventoryStockUnitOperation;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;
use App\Enum\Inventory\InventoryStockUnitStatus;
use PHPUnit\Framework\TestCase;

final class InventoryStockUnitOperationTest extends TestCase
{
    public function testItKeepsTheReasonAndStatusTransitionForAControlledBlock(): void
    {
        $stockUnit = new InventoryStockUnit($this->variant(), '6.5');
        $operation = new InventoryStockUnitOperation(
            $stockUnit,
            new User(),
            InventoryStockUnitOperationType::BLOCK,
            InventoryStockUnitOperationReason::QUALITY_REVIEW,
            '6.5',
            InventoryStockUnitStatus::AVAILABLE,
            InventoryStockUnitStatus::BLOCKED,
            'Etiqueta dañada; se aparta para revisión de calidad.',
        );

        self::assertSame($stockUnit, $operation->getStockUnit());
        self::assertSame('6.500000', $operation->getQuantity());
        self::assertSame(InventoryStockUnitOperationType::BLOCK, $operation->getType());
        self::assertSame(InventoryStockUnitOperationReason::QUALITY_REVIEW, $operation->getReason());
        self::assertNull($operation->getMovement());
    }

    public function testItRequiresAnExternalReferenceForSupplierReturns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('folio de remisión');

        new InventoryStockUnitOperation(
            new InventoryStockUnit($this->variant(), '2'),
            new User(),
            InventoryStockUnitOperationType::DISPOSE,
            InventoryStockUnitOperationReason::RETURN_TO_SUPPLIER,
            '2',
            InventoryStockUnitStatus::AVAILABLE,
            InventoryStockUnitStatus::DISPOSED,
            'Proveedor autorizó el retorno del material no conforme.',
        );
    }

    public function testItRejectsAReasonThatDoesNotBelongToTheSelectedAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no corresponde');

        new InventoryStockUnitOperation(
            new InventoryStockUnit($this->variant(), '2'),
            new User(),
            InventoryStockUnitOperationType::RELEASE,
            InventoryStockUnitOperationReason::EXPIRED,
            '2',
            InventoryStockUnitStatus::BLOCKED,
            InventoryStockUnitStatus::AVAILABLE,
            'Intento de liberar material caduco.',
        );
    }

    private function variant(): MaterialVariant
    {
        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1');

        return new MaterialVariant(new Material(), 'VINIL-BODEGA-152', $unit, $unit, $unit);
    }
}
