<?php

namespace App\Application\Catalog;

use Symfony\Component\Validator\Constraints as Assert;

final class CommercialItemBasePriceData
{
    #[Assert\NotBlank(message: 'El precio base es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
        message: 'Captura un importe válido con máximo dos decimales.',
    )]
    public ?string $basePrice = '0.00';
}
