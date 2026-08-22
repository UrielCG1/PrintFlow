<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Enum\Catalog\ItemPriceRuleType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['commercialItem', 'ruleType', 'minQuantity'],
    entityClass: ItemPriceRule::class,
    identifierFieldNames: ['id'],
    errorPath: 'minQuantity',
    message: 'Ya existe un rango con esta cantidad mínima para el producto o servicio.',
)]
final class ItemPriceRuleData
{
    public ?int $id = null;

    public ?CommercialItem $commercialItem = null;

    public ?ItemPriceRuleType $ruleType = ItemPriceRuleType::QUANTITY_TIER;

    #[Assert\NotBlank(message: 'La cantidad mínima es obligatoria.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/',
        message: 'Captura una cantidad válida con máximo cuatro decimales.',
    )]
    #[Assert\GreaterThan(value: 0, message: 'La cantidad mínima debe ser mayor que cero.')]
    public ?string $minQuantity = null;

    #[Assert\NotBlank(message: 'El precio unitario es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
        message: 'Captura un importe válido con máximo dos decimales.',
    )]
    public ?string $unitPrice = null;
}