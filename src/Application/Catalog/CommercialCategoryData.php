<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCategory;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: CommercialCategory::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una categoría comercial con este código.',
)]
final class CommercialCategoryData
{
    public ?int $id = null;
    #[Assert\NotBlank(message: 'Captura el código de la categoría.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', message: 'El código solo puede contener letras, números, guiones y guiones bajos.')]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre de la categoría.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;
}