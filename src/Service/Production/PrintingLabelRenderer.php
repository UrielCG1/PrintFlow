<?php

declare(strict_types=1);

namespace App\Service\Production;

use App\Entity\Production\ProductionPrintedOutput;

/** Compone una etiqueta HTML imprimible; la generación QR queda aislada del flujo productivo. */
final class PrintingLabelRenderer
{
    public function render(ProductionPrintedOutput $output): string
    {
        if (!class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
            throw new \RuntimeException('No está instalada la biblioteca QR. La salida queda pendiente de etiqueta y puede reintentarse sin duplicar la impresión.');
        }

        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\SvgWriter())
            ->writerOptions([
                \Endroid\QrCode\Writer\SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
            ])
            ->data($output->getQrPayload())
            ->size(240)
            ->margin(8)
            ->build();
        $qrSvg = $result->getString();
        $item = $output->getServiceOrderItem();
        $product = $item->getCommercialItemSnapshot()['name'] ?? $item->getCommercialItem()->getName();

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Etiqueta '.self::e($output->getLabelCode()).'</title><style>'
            .'@page{margin:7mm}.label{width:92mm;font:12px Arial,sans-serif;color:#111}.label h1{font-size:18px;margin:0 0 6px}.label .qr{width:48mm;height:48mm}.label dl{margin:6px 0}.label dt{font-weight:bold;float:left;width:28mm}.label dd{margin-left:30mm;margin-bottom:4px}.label .code{font-family:monospace;font-size:11px;word-break:break-all}.actions{margin-top:12px}@media print{.actions{display:none}}'
            .'</style></head><body><main class="label"><h1>PrintFlow · Salida impresa</h1><div class="qr">'.$qrSvg.'</div><dl>'
            .'<dt>Orden</dt><dd>'.self::e($item->getServiceOrder()->getFolio()).'</dd>'
            .'<dt>Partida</dt><dd>'.self::e($item->getDisplayLineNumber()).'</dd>'
            .'<dt>Producto</dt><dd>'.self::e((string) $product).'</dd>'
            .'<dt>Cantidad</dt><dd>'.self::e($item->getQuantity()).'</dd>'
            .'<dt>Siguiente área</dt><dd>Acabados</dd>'
            .'<dt>Salida</dt><dd>Versión '.self::e((string) $output->getVersionNumber()).'</dd>'
            .'</dl><p class="code">'.self::e($output->getQrPayload()).'</p><p class="actions"><button type="button" onclick="window.print()">Imprimir etiqueta</button></p></main></body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
