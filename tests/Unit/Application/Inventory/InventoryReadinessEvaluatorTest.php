<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryReadinessEvaluator;
use PHPUnit\Framework\TestCase;

final class InventoryReadinessEvaluatorTest extends TestCase
{
    public function testReportsLegacyCountPresentationMinimumAndInitialCountPending(): void
    {
        $report = (new InventoryReadinessEvaluator())->evaluate([
            [
                'material_code' => 'LONA-FRONT-13OZ',
                'variant_code' => 'LONA-FRONT-13OZ-DEFAULT',
                'specifications' => '{"legacy_migration":true}',
                'minimum_stock' => '0.000000',
                'reorder_point' => '0.000000',
                'reorder_quantity' => '0.000000',
                'material_is_hazardous' => false,
                'storage_conditions' => null,
                'inventory_unit_code' => 'PZA',
                'inventory_unit_dimension' => 'COUNT',
                'inventory_unit_allows_fraction' => false,
                'consumption_unit_code' => 'PZA',
                'consumption_unit_dimension' => 'COUNT',
            ],
        ], 0, 0);

        self::assertSame([
            'variants' => 1,
            'ready' => 0,
            'requires_review' => 1,
        ], $report['summary']);
        self::assertSame([
            'PRESENTACION_LEGADA_POR_REVISAR',
            'POLITICA_DE_MINIMOS_PENDIENTE',
            'PRESENTACION_CONTABLE_POR_CONFIRMAR',
            'CONTEO_INICIAL_PENDIENTE',
        ], array_column($report['findings'], 'rule'));
    }

    public function testMarksAConfiguredVariantReady(): void
    {
        $report = (new InventoryReadinessEvaluator())->evaluate([
            [
                'material_code' => 'VINIL-BLANCO',
                'variant_code' => 'VINIL-BLANCO-152',
                'specifications' => ['width_mm' => 1520],
                'minimum_stock' => '10.000000',
                'reorder_point' => '15.000000',
                'reorder_quantity' => '50.000000',
                'material_is_hazardous' => false,
                'storage_conditions' => null,
                'inventory_unit_code' => 'ML',
                'inventory_unit_dimension' => 'LENGTH',
                'inventory_unit_allows_fraction' => true,
                'consumption_unit_code' => 'ML',
                'consumption_unit_dimension' => 'LENGTH',
            ],
        ], 2, 1);

        self::assertSame([
            'variants' => 1,
            'ready' => 1,
            'requires_review' => 0,
        ], $report['summary']);
        self::assertSame([], $report['findings']);
    }

    public function testAcceptsAConfirmedPhysicalPackageWithCountedContents(): void
    {
        $report = (new InventoryReadinessEvaluator())->evaluate([
            [
                'material_code' => 'PAPEL-COUCHE',
                'variant_code' => 'PAPEL-COUCHE-PAQ500',
                'specifications' => ['physical_form' => 'PAQUETE', 'conversion_confirmed' => true],
                'minimum_stock' => '100.000000',
                'reorder_point' => '200.000000',
                'reorder_quantity' => '500.000000',
                'material_is_hazardous' => false,
                'storage_conditions' => null,
                'inventory_unit_code' => 'HOJA',
                'inventory_unit_dimension' => 'COUNT',
                'inventory_unit_allows_fraction' => false,
                'consumption_unit_code' => 'PZA',
                'consumption_unit_dimension' => 'COUNT',
            ],
        ], 1, 1);

        self::assertSame(1, $report['summary']['ready']);
        self::assertSame([], $report['findings']);
    }

    public function testAcceptsConfirmedCrossDimensionConversion(): void
    {
        $report = (new InventoryReadinessEvaluator())->evaluate([
            [
                'material_code' => 'TINTA-NEGRA',
                'variant_code' => 'TINTA-NEGRA-BOTELLA-1L',
                'specifications' => ['physical_form' => 'BOTELLA', 'conversion_confirmed' => true],
                'minimum_stock' => '2.000000',
                'reorder_point' => '3.000000',
                'reorder_quantity' => '5.000000',
                'material_is_hazardous' => false,
                'storage_conditions' => null,
                'inventory_unit_code' => 'BOTELLA',
                'inventory_unit_dimension' => 'COUNT',
                'inventory_unit_allows_fraction' => false,
                'consumption_unit_code' => 'MLT',
                'consumption_unit_dimension' => 'VOLUME',
            ],
        ], 1, 1);

        self::assertSame(1, $report['summary']['ready']);
        self::assertSame([], $report['findings']);
    }

    public function testRequestsStorageConditionsForHazardousMaterial(): void
    {
        $report = (new InventoryReadinessEvaluator())->evaluate([
            [
                'material_code' => 'TINTA-SOLVENTE',
                'variant_code' => 'TINTA-SOLVENTE-NEGRA',
                'specifications' => [],
                'minimum_stock' => '5.000000',
                'reorder_point' => '7.000000',
                'reorder_quantity' => '10.000000',
                'material_is_hazardous' => true,
                'storage_conditions' => '',
                'inventory_unit_code' => 'MLT',
                'inventory_unit_dimension' => 'VOLUME',
                'inventory_unit_allows_fraction' => true,
                'consumption_unit_code' => 'MLT',
                'consumption_unit_dimension' => 'VOLUME',
            ],
        ], 1, 1);

        self::assertSame(['ALMACENAMIENTO_DE_RIESGO_PENDIENTE'], array_column($report['findings'], 'rule'));
    }
}
