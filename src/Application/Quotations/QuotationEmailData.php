<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Quotations\Quotation;
use Symfony\Component\Validator\Constraints as Assert;

final class QuotationEmailData
{
    #[Assert\NotBlank(message: 'Captura el correo destinatario.')]
    #[Assert\Email(message: 'Captura un correo destinatario válido.')]
    #[Assert\Length(max: 180)]
    public ?string $recipientEmail = null;

    #[Assert\Length(max: 160)]
    public ?string $recipientName = null;

    #[Assert\Email(message: 'La copia debe ser un correo válido.')]
    #[Assert\Length(max: 180)]
    public ?string $copyEmail = null;

    #[Assert\Length(max: 1000)]
    public ?string $message = null;

    public static function forQuotation(Quotation $quotation): self
    {
        $data = new self();
        $client = $quotation->getClientSnapshot();

        $data->recipientEmail = $client['billing_email']
            ?? $client['email']
            ?? null;
        $data->recipientName = $client['legal_name']
            ?? $client['business_name']
            ?? null;

        return $data;
    }
}
