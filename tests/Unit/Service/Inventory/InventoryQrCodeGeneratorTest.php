<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Inventory;

use App\Entity\Inventory\InventoryStockUnit;
use App\Service\Inventory\InventoryQrCodeGenerator;
use PHPUnit\Framework\TestCase;

final class InventoryQrCodeGeneratorTest extends TestCase
{
    public function testItGeneratesAnSvgForThePhysicalUnitPayload(): void
    {
        $stockUnit = $this->createMock(InventoryStockUnit::class);
        $stockUnit->method('getQrPayload')->willReturn('PF:MAT:1:QA-PILOT-UNIT');

        $svg = (new InventoryQrCodeGenerator())->generateSvg($stockUnit);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('http://www.w3.org/2000/svg', $svg);
        self::assertGreaterThan(1000, strlen($svg));
    }
}
