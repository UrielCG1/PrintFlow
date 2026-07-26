<?php

namespace App\Application\Catalog;

use Symfony\Component\Validator\Constraints as Assert;

final class ItemPriceRuleData
{
    #[Assert\NotBlank(message: 'Captura la cantidad mínima del rango.')]
    #[Assert\Regex(pattern: '/^(?=.*[1-9])\d+(?:[.,]\d{1,4})?$/', message: 'La cantidad mínima debe ser mayor que cero y tener máximo cuatro decimales.')]
    public ?string $minQuantity = null;

    #[Assert\NotBlank(message: 'Captura el precio unitario del rango.')]
    #[Assert\Regex(pattern: '/^\d+(?:[.,]\d{1,2})?$/', message: 'El precio unitario debe ser un decimal no negativo con máximo dos decimales.')]
    public ?string $unitPrice = null;
}