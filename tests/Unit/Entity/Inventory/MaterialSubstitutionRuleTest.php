<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\MaterialSubstitutionRule;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Catalog\MeasurementDimensionType;
use PHPUnit\Framework\TestCase;

final class MaterialSubstitutionRuleTest extends TestCase
{
    public function testItNormalizesTheInventoryEquivalence(): void
    {
        $rule = new MaterialSubstitutionRule($this->variant('ORIGINAL'), $this->variant('SUSTITUTO'), '1.25', 'Misma aplicación técnica.');

        self::assertSame('1.250000000000', $rule->getSubstitutePerOriginal());
        self::assertTrue($rule->isActive());
    }

    public function testItRejectsTheSameVariantAsItsOwnSubstitute(): void
    {
        $variant = $this->variant('MISMA');
        $this->expectException(\InvalidArgumentException::class);
        new MaterialSubstitutionRule($variant, $variant, '1');
    }

    private function variant(string $code): MaterialVariant
    {
        $unit = (new MeasurementUnit())->setCode('PZA')->setName('Pieza')->setSymbol('pza')->setDimensionType(MeasurementDimensionType::COUNT)->setConversionFactor('1');
        return new MaterialVariant(new Material(), $code, $unit, $unit, $unit);
    }
}
