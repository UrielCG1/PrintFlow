<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Quotations;

use App\Application\Quotations\QuotationItemCharacteristicsSpecificationResolver;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\CommercialItemCharacteristicOption;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use PHPUnit\Framework\TestCase;

final class QuotationItemCharacteristicsSpecificationResolverTest extends TestCase
{
    public function testFreezesOnlyConfiguredCharacteristicValues(): void
    {
        $finish = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $matte = (new CommercialCharacteristicOption())
            ->setCharacteristic($finish)
            ->setCode('MATTE')
            ->setName('Mate');
        $finish->addOption($matte);

        $finishConfiguration = (new CommercialItemCharacteristic())
            ->setCharacteristic($finish)
            ->setIsRequired(true);
        $finishConfiguration->addAllowedOption(
            (new CommercialItemCharacteristicOption())->setCharacteristicOption($matte),
        );

        $width = (new CommercialCharacteristic())
            ->setCode('FINISHED_WIDTH_CM')
            ->setName('Ancho terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');
        $widthConfiguration = (new CommercialItemCharacteristic())
            ->setCharacteristic($width)
            ->setIsRequired(true);

        $result = (new QuotationItemCharacteristicsSpecificationResolver())->resolve(
            $this->item(),
            [$finishConfiguration, $widthConfiguration],
            [
                'characteristic_finish' => 'MATTE',
                'characteristic_finished_width_cm' => '12,5',
                'characteristic_not_configured' => 'must-not-be-saved',
            ],
            '3',
            'MANUAL',
        );

        self::assertSame('3.0000', $result['quantity']);
        self::assertSame(2, $result['schema_version']);
        self::assertSame(
            QuotationItemCharacteristicsSpecificationResolver::PROFILE,
            $result['snapshot']['profile'],
        );
        self::assertSame('MATTE', $result['snapshot']['values']['FINISH']['submitted_value']);
        self::assertSame('Mate', $result['snapshot']['values']['FINISH']['display_value']);
        self::assertSame('12.5000', $result['snapshot']['values']['FINISHED_WIDTH_CM']['submitted_value']);
        self::assertArrayNotHasKey('NOT_CONFIGURED', $result['snapshot']['values']);
    }

    public function testRejectsAnOptionOutsideTheProductConfiguration(): void
    {
        $finish = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $matte = (new CommercialCharacteristicOption())
            ->setCharacteristic($finish)
            ->setCode('MATTE')
            ->setName('Mate');
        $finish->addOption($matte);

        $configuration = (new CommercialItemCharacteristic())
            ->setCharacteristic($finish)
            ->setIsRequired(true);
        $configuration->addAllowedOption(
            (new CommercialItemCharacteristicOption())->setCharacteristicOption($matte),
        );

        $this->expectException(\DomainException::class);

        (new QuotationItemCharacteristicsSpecificationResolver())->resolve(
            $this->item(),
            [$configuration],
            ['characteristic_finish' => 'GLOSS'],
            '1',
            'MANUAL',
        );
    }

    public function testKeepsLargeFormatAreaCalculationAlongsideConfiguredCharacteristics(): void
    {
        $finish = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $matte = (new CommercialCharacteristicOption())
            ->setCharacteristic($finish)
            ->setCode('MATTE')
            ->setName('Mate');
        $finish->addOption($matte);

        $configuration = (new CommercialItemCharacteristic())
            ->setCharacteristic($finish)
            ->setIsRequired(true);
        $configuration->addAllowedOption(
            (new CommercialItemCharacteristicOption())->setCharacteristicOption($matte),
        );

        $item = $this->item()
            ->setMeasurementUnit((new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado'))
            ->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT);

        $result = (new QuotationItemCharacteristicsSpecificationResolver())->resolve(
            $item,
            [$configuration],
            [
                'characteristic_finish' => 'MATTE',
                'finished_width_cm' => '100',
                'finished_height_cm' => '150',
            ],
            '1',
            'AUTO',
        );

        self::assertSame('1.5000', $result['quantity']);
        self::assertSame('DIMENSIONS', $result['snapshot']['billing_quantity']['source']);
        self::assertSame('100.0000', $result['snapshot']['large_format']['values']['finished_width_cm']);
        self::assertSame('MATTE', $result['snapshot']['values']['FINISH']['submitted_value']);
    }

    private function item(): CommercialItem
    {
        $unit = (new MeasurementUnit())
            ->setCode('PZA')
            ->setName('Pieza');

        return (new CommercialItem())->setMeasurementUnit($unit);
    }
}
