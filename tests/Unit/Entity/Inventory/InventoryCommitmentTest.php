<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Application\Inventory\InventoryCoverage;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryCommitmentStatus;
use PHPUnit\Framework\TestCase;

final class InventoryCommitmentTest extends TestCase
{
    public function testItTracksConsumedAndReleasedQuantitiesSeparately(): void
    {
        $commitment = new InventoryCommitment(
            new ServiceOrder(),
            $this->variant(),
            new User(),
            new InventoryCoverage('10', '7', '7', '3'),
        );

        self::assertSame('7.000000', $commitment->getPendingQuantity());

        $commitment->recordConsumedQuantity('4.25');

        self::assertSame('4.250000', $commitment->getConsumedQuantity());
        self::assertSame('2.750000', $commitment->getPendingQuantity());

        self::assertSame('2.750000', $commitment->releasePendingQuantity());
        self::assertSame('2.750000', $commitment->getReleasedQuantity());
        self::assertSame('0.000000', $commitment->getPendingQuantity());
    }

    public function testItRejectsConsumptionAgainstAnAlreadyReleasedCommitment(): void
    {
        $commitment = new InventoryCommitment(
            new ServiceOrder(),
            $this->variant(),
            new User(),
            new InventoryCoverage('2', '2', '2', '0'),
        );
        $commitment->releasePendingQuantity();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('saldo pendiente');

        $commitment->recordConsumedQuantity('1');
    }

    public function testItCanReserveFreshlyReceivedStockAgainstAnExistingShortage(): void
    {
        $commitment = new InventoryCommitment(
            new ServiceOrder(),
            $this->variant(),
            new User(),
            new InventoryCoverage('10', '4', '4', '6'),
        );

        $commitment->coverShortageQuantity('2.5');

        self::assertSame('6.500000', $commitment->getCommittedQuantity());
        self::assertSame('3.500000', $commitment->getShortageQuantity());
        self::assertSame(InventoryCommitmentStatus::PARTIAL, $commitment->getStatus());

        $commitment->coverShortageQuantity('3.5');

        self::assertSame('0.000000', $commitment->getShortageQuantity());
        self::assertSame(InventoryCommitmentStatus::COVERED, $commitment->getStatus());
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
