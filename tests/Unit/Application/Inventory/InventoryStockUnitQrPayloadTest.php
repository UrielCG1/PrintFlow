<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryStockUnitQrPayload;
use PHPUnit\Framework\TestCase;

final class InventoryStockUnitQrPayloadTest extends TestCase
{
    public function testItNormalizesThePhysicalStockUnitQrPayload(): void
    {
        $payload = InventoryStockUnitQrPayload::fromScanned('  pf:mat:1:ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789  ');

        self::assertSame('abcdef0123456789abcdef0123456789abcdef0123456789', $payload->getToken());
        self::assertSame('PF:MAT:1:abcdef0123456789abcdef0123456789abcdef0123456789', $payload->toCanonicalPayload());
    }

    public function testItRejectsAProductBarcodeInsteadOfAPhysicalQr(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('etiqueta QR válida');

        InventoryStockUnitQrPayload::fromScanned('7501234567890');
    }
}
