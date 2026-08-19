<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: CommercialCharacteristic::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una característica con este código.',
)]
#[UniqueEntity(
    fields: ['name'],
    entityClass: CommercialCharacteristic::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe una característica con este nombre.',
)]
final class CommercialCharacteristicData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Captura el código de la característica.')]
    #[Assert\Length(max: 60)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9][A-Za-z0-9_]*$/',
        message: 'El código solo puede contener letras, números y guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre visible de la característica.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\NotNull(message: 'Selecciona el tipo de dato.')]
    public ?CommercialCharacteristicInputType $inputType = CommercialCharacteristicInputType::SELECT;

    #[Assert\Length(max: 20)]
    public ?string $unitLabel = null;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;
}
