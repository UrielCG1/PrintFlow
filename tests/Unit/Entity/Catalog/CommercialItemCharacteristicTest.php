<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\CommercialItemCharacteristicOption;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use PHPUnit\Framework\TestCase;

final class CommercialItemCharacteristicTest extends TestCase
{
    public function testItCanRemoveAnAllowedOptionFromTheConfiguration(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);

        $option = (new CommercialCharacteristicOption())
            ->setCharacteristic($characteristic)
            ->setCode('MATTE')
            ->setName('Mate');

        $configuration = (new CommercialItemCharacteristic())
            ->setCharacteristic($characteristic);
        $allowedOption = (new CommercialItemCharacteristicOption())
            ->setCharacteristicOption($option);

        $configuration->addAllowedOption($allowedOption);
        self::assertCount(1, $configuration->getAllowedOptions());

        $configuration->removeAllowedOption($allowedOption);
        self::assertCount(0, $configuration->getAllowedOptions());
    }
}
