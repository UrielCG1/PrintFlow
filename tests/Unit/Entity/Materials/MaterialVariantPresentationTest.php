<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Catalog\MeasurementDimensionType;
use PHPUnit\Framework\TestCase;

final class MaterialVariantPresentationTest extends TestCase
{
    public function testAContainerCanHaveExplicitPurchaseAndConsumptionConversions(): void
    {
        $bottle = $this->unit('BOTELLA', MeasurementDimensionType::COUNT);
        $liter = $this->unit('L', MeasurementDimensionType::VOLUME);
        $milliliter = $this->unit('MLT', MeasurementDimensionType::VOLUME);

        $variant = (new MaterialVariant(new Material(), 'TINTA-NEGRA-BOTELLA-1L', $bottle, $liter, $milliliter))
            ->configurePhysicalPresentation('BOTELLA', '1', '1000');

        self::assertSame(['physical_form' => 'BOTELLA', 'conversion_confirmed' => true], $variant->getSpecifications());
        self::assertSame('1.000000000000', $variant->getPurchaseToInventoryFactor());
        self::assertSame('1000.000000000000', $variant->getInventoryToConsumptionFactor());
    }

    public function testEqualUnitsDefaultToOneButRejectAnotherFactor(): void
    {
        $sheet = $this->unit('HOJA', MeasurementDimensionType::COUNT);
        $variant = (new MaterialVariant(new Material(), 'PAPEL-HOJA', $sheet, $sheet, $sheet))
            ->configurePhysicalPresentation('HOJA', '', '');

        self::assertSame('1.000000000000', $variant->getPurchaseToInventoryFactor());
        self::assertSame('1.000000000000', $variant->getInventoryToConsumptionFactor());

        $this->expectException(\DomainException::class);
        $variant->configurePhysicalPresentation('HOJA', '2', '1');
    }

    public function testDifferentUnitsRequireAnExplicitFactor(): void
    {
        $box = $this->unit('CAJA', MeasurementDimensionType::COUNT);
        $sheet = $this->unit('HOJA', MeasurementDimensionType::COUNT);
        $variant = new MaterialVariant(new Material(), 'PAPEL-CAJA', $box, $sheet, $sheet);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('compra a inventario');
        $variant->configurePhysicalPresentation('CAJA', '', '');
    }

    public function testAReceivedPackageCanHoldSeveralCountedSheets(): void
    {
        $package = $this->unit('PAQ', MeasurementDimensionType::COUNT);
        $sheet = $this->unit('HOJA', MeasurementDimensionType::COUNT);
        $variant = (new MaterialVariant(new Material(), 'PAPEL-PAQ500', $package, $sheet, $sheet))
            ->configurePhysicalPresentation('PAQUETE', '500', '');

        self::assertSame('500.000000000000', $variant->getPurchaseToInventoryFactor());
        self::assertSame('1.000000000000', $variant->getInventoryToConsumptionFactor());
    }

    public function testRollsMeasuredLinearlyAndConsumedByAreaNeedTheirWidth(): void
    {
        $linearMeter = $this->unit('ML', MeasurementDimensionType::LENGTH);
        $squareMeter = $this->unit('M2', MeasurementDimensionType::AREA);
        $variant = new MaterialVariant(new Material(), 'LONA-SIN-ANCHO', $linearMeter, $linearMeter, $squareMeter);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ancho');
        $variant->configurePhysicalPresentation('ROLLO', '', '1.52');
    }

    private function unit(string $code, MeasurementDimensionType $dimension): MeasurementUnit
    {
        return (new MeasurementUnit())->setCode($code)->setDimensionType($dimension);
    }
}
