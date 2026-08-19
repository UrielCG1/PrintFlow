<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Quotations;

use App\Application\Quotations\QuotationItemData;
use App\Application\Quotations\QuotationItemSpecificationResolver;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use PHPUnit\Framework\TestCase;

final class QuotationItemSpecificationResolverTest extends TestCase
{
    public function testCalculatesSquareMetresWhenTheQuantityHasNotBeenAdjusted(): void
    {
        $result = (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '1.0000',
            QuotationItemData::QUANTITY_MODE_AUTO,
        );

        self::assertSame('1.5000', $result['quantity']);
        self::assertSame('DIMENSIONS', $result['snapshot']['billing_quantity']['source']);
        self::assertSame('1.5000', $result['snapshot']['calculated']['area_m2']);
    }

    public function testPreservesAValidManualAdjustmentForSquareMetres(): void
    {
        $result = (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '1.6500',
            QuotationItemData::QUANTITY_MODE_MANUAL,
        );

        self::assertSame('1.6500', $result['quantity']);
        self::assertSame('MANUAL', $result['snapshot']['billing_quantity']['source']);
    }

    public function testKeepsPieceQuantityManualWhilePreservingDimensions(): void
    {
        $result = (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('PZA', 'Pieza'),
            ['finished_width_cm' => '60', 'finished_height_cm' => '160'],
            '3',
            QuotationItemData::QUANTITY_MODE_AUTO,
        );

        self::assertSame('3.0000', $result['quantity']);
        self::assertSame('MANUAL', $result['snapshot']['billing_quantity']['source']);
        self::assertSame('0.9600', $result['snapshot']['calculated']['area_m2']);
    }

    public function testRejectsZeroQuantityBeforePricing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '0',
            QuotationItemData::QUANTITY_MODE_MANUAL,
        );
    }

    public function testRejectsNegativeQuantityBeforePricing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '-1',
            QuotationItemData::QUANTITY_MODE_MANUAL,
        );
    }

    public function testRejectsQuantityWithMoreThanFourDecimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '1.00001',
            QuotationItemData::QUANTITY_MODE_MANUAL,
        );
    }

    public function testRejectsInvalidQuantityModeForLargeFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El origen de la cantidad de la partida no es válido.');

        (new QuotationItemSpecificationResolver())->resolve(
            $this->largeFormatItem('M2', 'Metro cuadrado'),
            ['finished_width_cm' => '100', 'finished_height_cm' => '150'],
            '1',
            'FORGED',
        );
    }

    private function largeFormatItem(string $unitCode, string $unitName): CommercialItem
    {
        $unit = (new MeasurementUnit())
            ->setCode($unitCode)
            ->setName($unitName);

        return (new CommercialItem())
            ->setMeasurementUnit($unit)
            ->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT);
    }
}
