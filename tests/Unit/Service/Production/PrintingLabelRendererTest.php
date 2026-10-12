<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Production;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Production\ProductionPrintedOutput;
use App\Service\Production\PrintingLabelRenderer;
use PHPUnit\Framework\TestCase;

final class PrintingLabelRendererTest extends TestCase
{
    public function testItRendersAPortraitLabelWithContainedQrAndEscapedProductionData(): void
    {
        $unit = $this->createMock(MeasurementUnit::class);
        $unit->method('getSymbol')->willReturn('m²');

        $commercialItem = $this->createMock(CommercialItem::class);
        $commercialItem->method('getName')->willReturn('Producto del catálogo');
        $commercialItem->method('getMeasurementUnit')->willReturn($unit);

        $order = $this->createMock(ServiceOrder::class);
        $order->method('getFolio')->willReturn('OS-2026-000008');

        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getCommercialItemSnapshot')->willReturn(['name' => 'LONA <13 OZ>']);
        $item->method('getCommercialItem')->willReturn($commercialItem);
        $item->method('getServiceOrder')->willReturn($order);
        $item->method('getDisplayLineNumber')->willReturn('1.2');
        $item->method('getQuantity')->willReturn('78.000000');

        $execution = $this->createMock(ProductionOperationExecution::class);
        $execution->method('getCompletedAt')->willReturn(new \DateTimeImmutable('2026-10-11 19:45:00', new \DateTimeZone('UTC')));

        $output = $this->createMock(ProductionPrintedOutput::class);
        $output->method('getServiceOrderItem')->willReturn($item);
        $output->method('getExecution')->willReturn($execution);
        $output->method('getQrPayload')->willReturn('PF|1|OS-2026-000008|P1.2|PF-AB12');
        $output->method('getLabelCode')->willReturn('PF-AB12');
        $output->method('getVersionNumber')->willReturn(2);

        $html = (new PrintingLabelRenderer())->render($output);

        self::assertStringContainsString('@page { size: 70mm 100mm; margin: 0; }', $html);
        self::assertStringContainsString('width: 32mm !important; height: 32mm !important;', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('LONA &lt;13 OZ&gt;', $html);
        self::assertStringContainsString('OS-2026-000008', $html);
        self::assertStringContainsString('1.2', $html);
        self::assertStringContainsString('78 m²', $html);
        self::assertStringContainsString('PF-AB12', $html);
        self::assertStringContainsString('Salida versión 2', $html);
        self::assertStringNotContainsString('LONA <13 OZ>', $html);
    }
}
