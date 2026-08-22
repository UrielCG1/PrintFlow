<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\CatalogHealthEvaluator;
use App\Application\Catalog\CommercialCharacteristicTechnicalContract;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CatalogHealthSeverity;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use App\Enum\Catalog\CommercialItemType;
use App\Enum\Catalog\MeasurementDimensionType;
use PHPUnit\Framework\TestCase;

final class CatalogHealthEvaluatorTest extends TestCase
{
    public function testFlagsActiveProductWithoutCharacteristicsAsAttention(): void
    {
        $finding = $this->evaluator()->evaluateItem($this->product('0.00'), []);

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::ATTENTION, $finding->severity);
        self::assertCount(2, $finding->reasons);
    }

    public function testFlagsSelectConfigurationWithoutAllowedOptionsAsIncomplete(): void
    {
        $product = $this->product('100.00');
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);

        $configuration = (new CommercialItemCharacteristic())
            ->setCommercialItem($product)
            ->setCharacteristic($characteristic);

        $finding = $this->evaluator()->evaluateItem($product, [$configuration]);

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::INCOMPLETE, $finding->severity);
        self::assertStringContainsString('no tiene opciones activas permitidas', implode(' ', $finding->reasons));
    }

    public function testMarksActiveCategoryWithoutCommercialUsageAsUnused(): void
    {
        $category = (new CommercialCategory())
            ->setCode('EMPTY')
            ->setName('Sin uso');

        $finding = $this->evaluator()->evaluateCategory($category, ['total' => 0, 'active' => 0]);

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::UNUSED, $finding->severity);
    }

    public function testFlagsInactiveUnitWithActiveDependenciesAsIncomplete(): void
    {
        $unit = (new MeasurementUnit())
            ->setCode('PZA')
            ->setName('Pieza')
            ->setSymbol('pza')
            ->setDimensionType(MeasurementDimensionType::COUNT)
            ->setIsActive(false);

        $finding = $this->evaluator()->evaluateUnit(
            $unit,
            ['total' => 1, 'active' => 1],
            ['total' => 0, 'active' => 0],
            0,
        );

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::INCOMPLETE, $finding->severity);
    }

    public function testFlagsActiveSelectCharacteristicWithoutOptionsAsIncomplete(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);

        $finding = $this->evaluator()->evaluateCharacteristic(
            $characteristic,
            ['total' => 0, 'active' => 0],
            ['total' => 0, 'active' => 0],
        );

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::INCOMPLETE, $finding->severity);
    }

    public function testMarksUnusedTextCharacteristicWithoutProductUsage(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('NOTE')
            ->setName('Nota de producción')
            ->setInputType(CommercialCharacteristicInputType::TEXT);

        $finding = $this->evaluator()->evaluateCharacteristic(
            $characteristic,
            ['total' => 0, 'active' => 0],
            ['total' => 0, 'active' => 0],
        );

        self::assertNotNull($finding);
        self::assertSame(CatalogHealthSeverity::UNUSED, $finding->severity);
    }

    private function evaluator(): CatalogHealthEvaluator
    {
        return new CatalogHealthEvaluator(new CommercialCharacteristicTechnicalContract());
    }

    private function product(string $basePrice): CommercialItem
    {
        $category = (new CommercialCategory())
            ->setCode('GENERAL')
            ->setName('General');
        $unit = (new MeasurementUnit())
            ->setCode('PZA')
            ->setName('Pieza')
            ->setSymbol('pza')
            ->setDimensionType(MeasurementDimensionType::COUNT);

        return (new CommercialItem())
            ->setCode('PRODUCT-1')
            ->setName('Producto de prueba')
            ->setType(CommercialItemType::PRODUCT)
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setBasePrice($basePrice);
    }
}
