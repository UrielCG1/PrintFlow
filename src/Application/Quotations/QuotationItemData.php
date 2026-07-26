<?php

namespace App\Application\Quotations;

use App\Entity\Catalog\CommercialItem;
use Symfony\Component\Validator\Constraints as Assert;

final class QuotationItemData
{
    #[Assert\NotNull(message: 'Selecciona un concepto comercial.')]
    public ?CommercialItem $commercialItem = null;

    #[Assert\NotBlank(message: 'Captura la cantidad.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:[.,]\d{1,4})?$/',
        message: 'La cantidad debe usar hasta cuatro decimales.',
    )]
    public ?string $quantity = '1.0000';
}