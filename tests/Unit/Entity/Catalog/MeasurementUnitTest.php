<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\MeasurementDimensionType;
use PHPUnit\Framework\TestCase;

final class MeasurementUnitTest extends TestCase
{
    public function testAllowsCompatibleBaseUnitAndConversion(): void
    {
        $metre = (new MeasurementUnit())
            ->setCode('M')
            ->setName('Metro')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1');

        $centimetre = (new MeasurementUnit())
            ->setCode('CM')
            ->setName('Centímetro')
            ->setSymbol('cm')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setBaseUnit($metre)
            ->setConversionFactor('0.01')
            ->setDecimalScale(4)
            ->setAllowsFraction(true);

        self::assertSame($metre, $centimetre->getBaseUnit());
        self::assertSame('LENGTH', $centimetre->getDimensionType());
        self::assertSame('0.010000000000', $centimetre->getConversionFactor());
        self::assertSame(4, $centimetre->getDecimalScale());
        self::assertTrue($centimetre->allowsFraction());
    }

    public function testRejectsBaseUnitFromAnotherDimension(): void
    {
        $hour = (new MeasurementUnit())
            ->setCode('HORA')
            ->setName('Hora')
            ->setSymbol('h')
            ->setDimensionType(MeasurementDimensionType::TIME);

        $centimetre = (new MeasurementUnit())
            ->setCode('CM')
            ->setName('Centímetro')
            ->setSymbol('cm')
            ->setDimensionType(MeasurementDimensionType::LENGTH);

        $this->expectException(\DomainException::class);
        $centimetre->setBaseUnit($hour);
    }

    public function testRejectsItselfAsBaseUnit(): void
    {
        $metre = (new MeasurementUnit())
            ->setCode('M')
            ->setName('Metro')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH);

        $this->expectException(\DomainException::class);
        $metre->setBaseUnit($metre);
    }

    public function testRejectsInvalidConversionFactor(): void
    {
        $unit = (new MeasurementUnit())
            ->setCode('M2')
            ->setName('Metro cuadrado')
            ->setSymbol('m²')
            ->setDimensionType(MeasurementDimensionType::AREA);

        $this->expectException(\InvalidArgumentException::class);
        $unit->setConversionFactor('0');
    }
}
