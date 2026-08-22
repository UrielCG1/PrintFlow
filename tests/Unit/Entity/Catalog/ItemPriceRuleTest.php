<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Catalog;

use App\Entity\Catalog\ItemPriceRule;
use PHPUnit\Framework\TestCase;

final class ItemPriceRuleTest extends TestCase
{
    public function testNormalizesMinimumQuantity(): void
    {
        $cases = [
            ['10', '10.0000'],
            ['10.5', '10.5000'],
            ['2,25', '2.2500'],
            [' 1.125 ', '1.1250'],
        ];

        foreach ($cases as [$input, $expected]) {
            self::assertSame($expected, ItemPriceRule::normalizeMinimumQuantity($input));
        }
    }

    public function testRejectsZeroMinimumQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemPriceRule::normalizeMinimumQuantity('0');
    }

    public function testRejectsNegativeMinimumQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemPriceRule::normalizeMinimumQuantity('-1');
    }

    public function testRejectsMinimumQuantityWithTooManyDecimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemPriceRule::normalizeMinimumQuantity('1.00001');
    }

    public function testRejectsNonNumericMinimumQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemPriceRule::normalizeMinimumQuantity('abc');
    }

    public function testNormalizesUnitPriceToTwoDecimals(): void
    {
        $rule = (new ItemPriceRule())->setUnitPrice('180,5');

        self::assertSame('180.50', $rule->getUnitPrice());
    }
}
