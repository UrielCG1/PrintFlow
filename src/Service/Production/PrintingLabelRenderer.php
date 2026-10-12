<?php

declare(strict_types=1);

namespace App\Service\Production;

use App\Entity\Production\ProductionPrintedOutput;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

final class PrintingLabelRenderer
{
    public function render(ProductionPrintedOutput $output): string
    {
        if (!class_exists(SvgWriter::class)) {
            throw new \RuntimeException('No está instalada la biblioteca QR. La salida queda pendiente de etiqueta y puede reintentarse sin duplicar la impresión.');
        }

        $item = $output->getServiceOrderItem();
        $snapshot = $item->getCommercialItemSnapshot();
        $productName = trim((string) ($snapshot['name'] ?? '')) ?: $item->getCommercialItem()->getName();
        $folio = $item->getServiceOrder()->getFolio();
        $line = $item->getDisplayLineNumber();
        $quantity = $this->formatQuantity($item->getQuantity());
        $unit = $item->getCommercialItem()->getMeasurementUnit()->getSymbol();
        $completedAt = $output->getExecution()->getCompletedAt();
        $completedAtLabel = $completedAt?->setTimezone(new \DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i');

        $qr = Builder::create()
            ->writer(new SvgWriter())
            ->writerOptions([SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true])
            ->data($output->getQrPayload())
            ->size(320)
            ->margin(16)
            ->build()
            ->getString();

        return sprintf(<<<'HTML'
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiqueta de salida · %1$s</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%%; }
        body {
            display: grid;
            place-items: start center;
            padding: 24px;
            background: #edf0f5;
            color: #142b20;
            font-family: Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .label {
            width: 70mm;
            min-height: 100mm;
            padding: 4mm;
            display: flex;
            flex-direction: column;
            gap: 1mm;
            overflow: hidden;
            background: #fff;
            border: .45mm solid #18251d;
            box-shadow: 0 8px 24px rgba(18, 38, 29, .16);
        }
        .brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 8.5mm;
            margin: -4mm -4mm 0;
            padding: 2mm 4mm;
            background: #91c31f;
            color: #132116;
        }
        .brand-name { font-size: 16pt; font-weight: 800; letter-spacing: .02em; }
        .brand-caption { font-size: 6.5pt; font-weight: 700; letter-spacing: .08em; text-align: right; text-transform: uppercase; }
        .heading { text-align: center; }
        .eyebrow { color: #52705a; font-size: 7pt; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; }
        .product { display: -webkit-box; margin: 1mm 0 0; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; font-size: 11pt; line-height: 1.08; font-weight: 800; overflow-wrap: anywhere; }
        .tracking { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5mm; }
        .datum { min-width: 0; padding: 1.6mm 2mm; border: .25mm solid #d6ddd4; border-radius: 1.5mm; }
        .datum-label { display: block; color: #617066; font-size: 6.4pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .datum-value { display: block; margin-top: .7mm; font-size: 8.5pt; font-weight: 800; overflow-wrap: anywhere; }
        .quantity { text-align: center; font-size: 9pt; font-weight: 700; }
        .quantity strong { font-size: 12pt; }
        .qr-wrap { display: grid; place-items: center; padding: 1mm 0; }
        .qr-wrap svg { display: block; width: 32mm !important; height: 32mm !important; max-width: 32mm; max-height: 32mm; shape-rendering: crispEdges; }
        .output-code { margin: 0; color: #101813; font-size: 12pt; line-height: 1.1; font-weight: 900; letter-spacing: .025em; text-align: center; overflow-wrap: anywhere; }
        .output-meta { margin: -1.5mm 0 0; color: #526258; font-size: 7pt; font-weight: 700; text-align: center; }
        .footer { margin-top: auto; padding-top: 2mm; border-top: .3mm solid #cbd5ca; display: flex; justify-content: space-between; gap: 2mm; font-size: 7pt; }
        .footer-label { display: block; color: #617066; font-size: 6pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .footer strong { display: block; margin-top: .7mm; font-size: 8pt; }
        .screen-actions { width: 70mm; margin-top: 12px; text-align: center; }
        .screen-actions button { padding: 9px 16px; border: 0; border-radius: 6px; background: #006e06; color: #fff; font-weight: 700; cursor: pointer; }
        .screen-hint { margin: 8px 0 0; color: #5a665f; font-size: 12px; }
        @page { size: 70mm 100mm; margin: 0; }
        @media print {
            html, body { width: 70mm; height: 100mm; min-height: 0; padding: 0; background: #fff; }
            body { display: block; }
            .label { width: 70mm; height: 100mm; min-height: 100mm; border: 0; box-shadow: none; }
            .screen-actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="label" aria-label="Etiqueta de salida impresa">
        <header class="brand">
            <span class="brand-name">OOXCorp</span>
            <span class="brand-caption">Control de<br>producción</span>
        </header>
        <section class="heading">
            <div class="eyebrow">Salida impresa</div>
            <h1 class="product">%2$s</h1>
        </section>
        <section class="tracking" aria-label="Datos de trazabilidad">
            <div class="datum"><span class="datum-label">Orden</span><span class="datum-value">%3$s</span></div>
            <div class="datum"><span class="datum-label">Partida</span><span class="datum-value">%4$s</span></div>
        </section>
        <div class="quantity">Cantidad contratada<br><strong>%5$s %6$s</strong></div>
        <div class="qr-wrap" aria-label="Código QR de trazabilidad">%7$s</div>
        <p class="output-code">%8$s</p>
        <p class="output-meta">Salida versión %9$d%10$s</p>
        <footer class="footer">
            <div><span class="footer-label">Proceso</span><strong>Impresión</strong></div>
            <div style="text-align:right"><span class="footer-label">Código de trazabilidad</span><strong>PrintFlow · OOXCorp</strong></div>
        </footer>
    </main>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir etiqueta</button>
        <p class="screen-hint">Formato vertical · 70 × 100 mm</p>
    </div>
</body>
</html>
HTML,
            $this->escape($folio),
            $this->escape($productName),
            $this->escape($folio),
            $this->escape($line),
            $this->escape($quantity),
            $this->escape($unit),
            $qr,
            $this->escape($output->getLabelCode()),
            $output->getVersionNumber(),
            $completedAtLabel !== null ? ' · '.$this->escape($completedAtLabel) : '',
        );
    }

    private function formatQuantity(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
