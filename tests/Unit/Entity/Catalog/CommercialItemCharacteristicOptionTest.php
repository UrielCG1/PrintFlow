<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\CommercialItemCharacteristicOption;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use PHPUnit\Framework\TestCase;

final class CommercialItemCharacteristicOptionTest extends TestCase
{
    public function testRejectsAnOptionThatBelongsToAnotherCharacteristic(): void
    {
        $finish = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $cut = (new CommercialCharacteristic())
            ->setCode('CUT_TYPE')
            ->setName('Corte')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $cutOption = (new CommercialCharacteristicOption())
            ->setCharacteristic($cut)
            ->setCode('DIE_CUT')
            ->setName('Troquelado');

        $configuration = (new CommercialItemCharacteristic())
            ->setCharacteristic($finish);

        $this->expectException(\DomainException::class);
        (new CommercialItemCharacteristicOption())
            ->setCommercialItemCharacteristic($configuration)
            ->setCharacteristicOption($cutOption);
    }

    public function testAllowsAnOptionOfTheConfiguredCharacteristic(): void
    {
        $finish = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);
        $matte = (new CommercialCharacteristicOption())
            ->setCharacteristic($finish)
            ->setCode('MATTE')
            ->setName('Mate');
        $configuration = (new CommercialItemCharacteristic())
            ->setCharacteristic($finish);
        $allowedOption = (new CommercialItemCharacteristicOption())
            ->setCharacteristicOption($matte)
            ->setDisplayOrder(10);

        $configuration->addAllowedOption($allowedOption);

        self::assertCount(1, $configuration->getAllowedOptions());
        self::assertSame($configuration, $allowedOption->getCommercialItemCharacteristic());
    }
}
