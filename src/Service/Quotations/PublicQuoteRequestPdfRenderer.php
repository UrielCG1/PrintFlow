<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use App\Application\Quotations\QuotationItemPresentationBuilder;
use App\Entity\Quotations\Quotation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class PublicQuoteRequestPdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly QuotationItemPresentationBuilder $itemPresentationBuilder,
        private readonly string $issuerName,
        private readonly string $issuerTaxId,
        private readonly string $issuerEmail,
        private readonly string $issuerPhone,
        private readonly string $issuerAddress,
        private readonly PdfBrandLogoProvider $brandLogo,
    ) {
    }

    public function render(Quotation $quotation): string
    {
        $presentedItems = [];

        foreach ($quotation->getItems() as $item) {
            $presented = $this->itemPresentationBuilder->present($item);
            $details = $item->getRequestDetails() ?? [];
            $presented['request_notes'] = is_string($details['notes'] ?? null)
                ? $details['notes']
                : null;
            $presentedItems[] = $presented;
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(
            $this->twig->render('public_quote_request/pdf.html.twig', [
                'quotation' => $quotation,
                'presentedItems' => $presentedItems,
                'brandLogoSrc' => $this->brandLogo->dataUri(),
                'issuer' => [
                    'name' => $this->issuerName,
                    'tax_id' => $this->issuerTaxId,
                    'email' => $this->issuerEmail,
                    'phone' => $this->issuerPhone,
                    'address' => $this->issuerAddress,
                ],
            ]),
            'UTF-8',
        );
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    public function filename(Quotation $quotation): string
    {
        return 'Cotizacion-'.$quotation->getRequestReference().'.pdf';
    }
}
