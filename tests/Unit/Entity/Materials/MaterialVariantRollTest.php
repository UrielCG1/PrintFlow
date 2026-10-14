<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Catalog\MeasurementDimensionType;
use PHPUnit\Framework\TestCase;

final class MaterialVariantRollTest extends TestCase
{
    public function testAWidthDefinesTheConversionWhileTheRollLengthRemainsVariable(): void
    {
        $linearMeter = (new MeasurementUnit())->setCode('ML')->setDimensionType(MeasurementDimensionType::LENGTH);
        $squareMeter = (new MeasurementUnit())->setCode('M2')->setDimensionType(MeasurementDimensionType::AREA);
        $variant = (new MaterialVariant(new Material(), 'LONA-ANCHO-1P5200M', $linearMeter, $linearMeter, $squareMeter))
            ->configureRollWidth('1.52');

        self::assertSame('1.520000000000', $variant->getInventoryToConsumptionFactor());
        self::assertSame([
            'physical_form' => 'ROLLO',
            'width_m' => '1.5200',
            'variable_length' => true,
        ], $variant->getSpecifications());
    }

    public function testItRejectsAWidthOnAnIncompatibleUnitPair(): void
    {
        $piece = (new MeasurementUnit())->setCode('PZA');
        $variant = new MaterialVariant(new Material(), 'LONA-LEGADA', $piece, $piece, $piece);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('metros lineales');

        $variant->configureRollWidth('1.52');
    }

    public function testItCanCorrectAnUnusedRollsWidthAndCode(): void
    {
        $linearMeter = (new MeasurementUnit())->setCode('ML')->setDimensionType(MeasurementDimensionType::LENGTH);
        $squareMeter = (new MeasurementUnit())->setCode('M2')->setDimensionType(MeasurementDimensionType::AREA);
        $variant = (new MaterialVariant(new Material(), 'LONA-ANCHO-1P5200M', $linearMeter, $linearMeter, $squareMeter))
            ->configureRollWidth('1.52');

        $variant->correctUnusedRollWidth('1.60', 'LONA-ANCHO-1P6000M');

        self::assertSame('LONA-ANCHO-1P6000M', $variant->getCode());
        self::assertSame('1.6000', $variant->getSpecifications()['width_m']);
        self::assertSame('1.600000000000', $variant->getInventoryToConsumptionFactor());
    }
}
