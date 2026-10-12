<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryReceipt;
use App\Entity\Inventory\InventoryReceiptLine;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryReceiptType;
use PHPUnit\Framework\TestCase;

final class InventoryReceiptTest extends TestCase
{
    public function testItCreatesALineForThePhysicalQrUnit(): void
    {
        $receipt = new InventoryReceipt(
            'receipt-unit-test-0001',
            hash('sha256', 'receipt-unit-test'),
            new User(),
            'REM-001',
            null,
            true,
        );
        $variant = $this->variant('VINIL-BLANCO');
        $stockUnit = new InventoryStockUnit($variant, '25');
        $line = new InventoryReceiptLine($receipt, 1, $variant, null, $stockUnit, '25', '0');
        $receipt->addLine($line);

        self::assertCount(1, $receipt->getLines());
        self::assertSame($receipt, $line->getReceipt());
        self::assertSame($stockUnit, $line->getStockUnit());
        self::assertSame('25.000000', $line->getReceivedQuantity());
        self::assertSame('0.000000', $line->getUnitCostMxn());
        self::assertTrue($receipt->isProvisional());
    }

    public function testItRejectsLineWithAStockUnitFromAnotherVariant(): void
    {
        $receipt = new InventoryReceipt(
            'receipt-unit-test-0002',
            hash('sha256', 'receipt-unit-test-2'),
            new User(),
            null,
            null,
            false,
        );
        $receivedVariant = $this->variant('VINIL-BLANCO');
        $otherStockUnit = new InventoryStockUnit($this->variant('VINIL-NEGRO'), '10');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unidad QR');

        new InventoryReceiptLine($receipt, 1, $receivedVariant, null, $otherStockUnit, '10', null);
    }

    public function testItRejectsInvalidIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotencia');

        new InventoryReceipt('short', hash('sha256', 'x'), new User(), null, null, false);
    }

    public function testItIdentifiesAnInitialCountWithItsOwnTypeAndCode(): void
    {
        $receipt = new InventoryReceipt(
            'initial-count-unit-test-0001',
            hash('sha256', 'initial-count-unit-test'),
            new User(),
            'CORTE-2026-10',
            null,
            false,
            InventoryReceiptType::INITIAL_COUNT,
        );

        self::assertTrue($receipt->isInitialCount());
        self::assertSame(InventoryReceiptType::INITIAL_COUNT, $receipt->getType());
        self::assertStringStartsWith('CON-', $receipt->getReceiptCode());
    }

    private function variant(string $code): MaterialVariant
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
