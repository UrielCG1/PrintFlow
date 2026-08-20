<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Catalog;

use App\Entity\Catalog\CommercialItem;
use PHPUnit\Framework\TestCase;

final class CommercialItemTest extends TestCase
{
    public function testNormalizesBasePrice(): void
    {
        $cases = [
            ['195', '195.00'],
            ['195.5', '195.50'],
            ['180,25', '180.25'],
            ['0', '0.00'],
        ];

        foreach ($cases as [$input, $expected]) {
            $item = (new CommercialItem())->setBasePrice($input);
            self::assertSame($expected, $item->getBasePrice());
        }
    }

    public function testRejectsNegativeBasePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommercialItem())->setBasePrice('-1');
    }

    public function testRejectsBasePriceWithTooManyDecimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommercialItem())->setBasePrice('1.999');
    }

    public function testRejectsNonNumericBasePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommercialItem())->setBasePrice('MXN 195');
    }
}
