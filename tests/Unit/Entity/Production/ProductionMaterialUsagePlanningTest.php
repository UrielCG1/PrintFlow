<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Production;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Users\User;
use App\Enum\Inventory\QuantitySource;
use App\Enum\Production\CalculationMethod;
use PHPUnit\Framework\TestCase;

final class ProductionMaterialUsagePlanningTest extends TestCase
{
    public function testRevisionReplacesThePlannedVariantAndQuantityWithoutAddingAnotherUsage(): void
    {
        $item = new ServiceOrderItem();
        $plan = (new ServiceOrderOperationPlan())->setServiceOrderItem($item);
        $unit = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²');
        $material = (new Material())->setName('Lona frontal');
        $original = new MaterialVariant($material, 'LONA-152', $unit, $unit, $unit);
        $replacement = new MaterialVariant($material, 'LONA-160', $unit, $unit, $unit);
        $usage = new ProductionMaterialUsage($item, $original, $unit, '30.4', CalculationMethod::FIXED, 1, ['source' => 'printing-production-plan']);
        $usage->assignPlanContext($plan);

        $usage->revisePlanned($replacement, '32');

        self::assertSame($replacement, $usage->getVariant());
        self::assertSame('32.000000', $usage->getPlannedQuantity());
        self::assertSame('m²', $usage->getUnitLabelSnapshot());
        self::assertSame('printing-production-plan', $usage->getCalculationSnapshot()['source']);
    }

    public function testRevisionRequiresAPlannedOperation(): void
    {
        $item = new ServiceOrderItem();
        $unit = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²');
        $variant = new MaterialVariant((new Material())->setName('Lona'), 'LONA-152', $unit, $unit, $unit);
        $usage = new ProductionMaterialUsage($item, $variant, $unit, '30.4', CalculationMethod::FIXED, 1, []);

        $this->expectException(\DomainException::class);
        $usage->revisePlanned($variant, '32');
    }

    public function testItKeepsPhysicalRollLengthSeparateFromProductionArea(): void
    {
        $item = new ServiceOrderItem();
        $plan = (new ServiceOrderOperationPlan())->setServiceOrderItem($item);
        $linearMeter = (new MeasurementUnit())->setCode('ML')->setName('Metro lineal')->setSymbol('m');
        $squareMeter = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²');
        $material = (new Material())->setName('Lona frontal');
        $variant = (new MaterialVariant($material, 'LONA-ANCHO-150', $linearMeter, $linearMeter, $squareMeter))
            ->configureRollWidth('1.5');
        $usage = new ProductionMaterialUsage($item, $variant, $squareMeter, '6', CalculationMethod::FIXED, 1, []);
        $usage->assignPlanContext($plan)->setPlannedInventoryQuantity('6');

        self::assertSame('6.000000', $usage->getPlannedQuantity());
        self::assertSame('6.000000', $usage->getPlannedInventoryQuantity());
        self::assertSame('m²', $usage->getUnitLabelSnapshot());
    }

    public function testItRecordsPhysicalRollLengthApartFromProductionArea(): void
    {
        $item = new ServiceOrderItem();
        $linearMeter = (new MeasurementUnit())->setCode('ML')->setName('Metro lineal')->setSymbol('m');
        $squareMeter = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²');
        $variant = (new MaterialVariant((new Material())->setName('Lona frontal'), 'LONA-ANCHO-150', $linearMeter, $linearMeter, $squareMeter))
            ->configureRollWidth('1.5');
        $usage = new ProductionMaterialUsage($item, $variant, $squareMeter, '6', CalculationMethod::FIXED, 1, []);

        $usage->recordActual('6', '0', QuantitySource::MEASURED, new User(), 'medición manual');
        $usage->recordActualInventoryQuantity('6');

        self::assertSame('6.000000', $usage->getActualQuantity());
        self::assertSame('6.000000', $usage->getActualInventoryQuantity());
    }
}
