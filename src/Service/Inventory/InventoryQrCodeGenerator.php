<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\InventoryStockUnit;

/** Genera el QR estable de una unidad física sin exponer su saldo o datos sensibles. */
final class InventoryQrCodeGenerator
{
    public function generateSvg(InventoryStockUnit $stockUnit): string
    {
        if (!class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
            throw new \RuntimeException(
                'No está instalada la biblioteca QR. La recepción ya fue registrada; instala endroid/qr-code para imprimir la etiqueta.',
            );
        }

        return \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\SvgWriter())
            ->data($stockUnit->getQrPayload())
            ->size(300)
            ->margin(8)
            ->build()
            ->getString();
    }
}
