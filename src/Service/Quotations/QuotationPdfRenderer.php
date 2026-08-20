<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use App\Application\Quotations\QuotationItemPresentationBuilder;
use App\Entity\Quotations\Quotation;
use App\Repository\Quotations\QuoteRequestRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class QuotationPdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly QuotationItemPresentationBuilder $itemPresentationBuilder,
        private readonly string $issuerName,
        private readonly string $issuerTaxId,
        private readonly string $issuerEmail,
        private readonly string $issuerPhone,
        private readonly string $issuerAddress,
    ) {
    }

    public function render(Quotation $quotation): string
    {
        if (!$quotation->hasBeenIssued()) {
            throw new \DomainException(
                'Solo puede generarse el PDF de una cotización emitida.',
            );
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $dompdf = new Dompdf($options);

        $quoteRequest = $this->quoteRequestRepository->findOneBy(['quotation' => $quotation]);

        $dompdf->loadHtml(
            $this->twig->render('admin/quotations/pdf.html.twig', [
                'quotation' => $quotation,
                'presentedItems' => $this->itemPresentationBuilder->presentAll($quotation->getItems()),
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

        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(Quotation $quotation): string
    {
        return sprintf('Cotizacion-%s.pdf', $quotation->getFolio());
    }
}
