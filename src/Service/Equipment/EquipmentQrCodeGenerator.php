<?php

declare(strict_types=1);

namespace App\Service\Equipment;

use App\Entity\Equipment\Equipment;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

final class EquipmentQrCodeGenerator
{
    public function generateSvg(Equipment $equipment): string
    {
        if (!class_exists(SvgWriter::class)) {
            throw new \RuntimeException('No está instalada la biblioteca QR para imprimir etiquetas de equipos.');
        }

        return Builder::create()
            ->writer(new SvgWriter())
            ->writerOptions([SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true])
            ->data($equipment->getCode())
            ->size(320)
            ->margin(16)
            ->build()
            ->getString();
    }
}
