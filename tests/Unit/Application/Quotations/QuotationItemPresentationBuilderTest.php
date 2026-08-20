<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Quotations;

use App\Application\Quotations\QuotationItemPresentationBuilder;
use App\Entity\Quotations\QuotationItem;
use PHPUnit\Framework\TestCase;

final class QuotationItemPresentationBuilderTest extends TestCase
{
    public function testPresentsSchemaTwoLargeFormatWithoutDuplicatingDimensions(): void
    {
        $item = (new QuotationItem())
            ->setLineNumber(1)
            ->setQuantity('6')
            ->setUnitPrice('195')
            ->setLineSubtotal('1170')
            ->setCommercialItemSnapshot([
                'code' => 'LONA-FRONT-13OZ',
                'name' => 'Lona frontal 13 oz',
                'description' => 'Impresión en lona frontal.',
                'measurement_unit' => [
                    'code' => 'M2',
                    'name' => 'Metro cuadrado',
                ],
            ])
            ->setSpecificationsSnapshot([
                'profile' => 'COMMERCIAL_CHARACTERISTICS',
                'schema_version' => 2,
                'values' => [
                    'FINISHED_WIDTH_CM' => [
                        'name' => 'Ancho terminado',
                        'display_value' => '200.0000 cm',
                    ],
                    'FINISHED_HEIGHT_CM' => [
                        'name' => 'Alto terminado',
                        'display_value' => '300.0000 cm',
                    ],
                    'FINISH' => [
                        'name' => 'Acabado',
                        'display_value' => 'Mate',
                    ],
                    'EYELETS' => [
                        'name' => 'Ojillos',
                        'display_value' => 'Sí',
                    ],
                ],
                'billing_quantity' => [
                    'value' => '6.0000',
                    'source' => 'DIMENSIONS',
                    'unit_code' => 'M2',
                    'unit_name' => 'Metro cuadrado',
                ],
                'large_format' => [
                    'values' => [
                        'finished_width_cm' => '200.0000',
                        'finished_height_cm' => '300.0000',
                    ],
                    'calculated' => [
                        'area_m2' => '6.0000',
                    ],
                ],
            ])
            ->setSpecificationSchemaVersion(2);

        $presentation = (new QuotationItemPresentationBuilder())->present($item);

        self::assertSame('Lona frontal 13 oz', $presentation['product']['name']);
        self::assertSame('6.0000 m²', $presentation['quantity_display']);
        self::assertSame('DIMENSIONS', $presentation['billing_source']);
        self::assertSame([
            ['label' => 'Medida terminada', 'value' => '200 × 300 cm'],
            ['label' => 'Área', 'value' => '6.0000 m²'],
            ['label' => 'Acabado', 'value' => 'Mate'],
            ['label' => 'Ojillos', 'value' => 'Sí'],
        ], $presentation['specifications']);
    }

    public function testPresentsLegacyLargeFormatSnapshot(): void
    {
        $item = (new QuotationItem())
            ->setLineNumber(1)
            ->setQuantity('3')
            ->setUnitPrice('400')
            ->setLineSubtotal('1200')
            ->setCommercialItemSnapshot([
                'code' => 'BANNER-PUB',
                'name' => 'Banner publicitario',
                'measurement_unit' => [
                    'code' => 'PZA',
                    'name' => 'Pieza',
                ],
            ])
            ->setSpecificationsSnapshot([
                'profile' => 'LARGE_FORMAT',
                'schema_version' => 1,
                'values' => [
                    'finished_width_cm' => '80.0000',
                    'finished_height_cm' => '180.0000',
                ],
                'calculated' => [
                    'area_m2' => '1.4400',
                ],
                'billing_quantity' => [
                    'value' => '3.0000',
                    'source' => 'MANUAL',
                    'unit_code' => 'PZA',
                    'unit_name' => 'Pieza',
                ],
            ])
            ->setSpecificationSchemaVersion(1);

        $presentation = (new QuotationItemPresentationBuilder())->present($item);

        self::assertSame('3.0000 pza', $presentation['quantity_display']);
        self::assertSame([
            ['label' => 'Medida terminada', 'value' => '80 × 180 cm'],
            ['label' => 'Área', 'value' => '1.4400 m²'],
        ], $presentation['specifications']);
    }
}
