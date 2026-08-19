<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['characteristic', 'code'],
    entityClass: CommercialCharacteristicOption::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una opción con este código para la característica.',
)]
#[UniqueEntity(
    fields: ['characteristic', 'name'],
    entityClass: CommercialCharacteristicOption::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe una opción con este nombre para la característica.',
)]
final class CommercialCharacteristicOptionData
{
    public ?int $id = null;

    public ?CommercialCharacteristic $characteristic = null;

    #[Assert\NotBlank(message: 'Captura el código de la opción.')]
    #[Assert\Length(max: 60)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9][A-Za-z0-9_]*$/',
        message: 'El código solo puede contener letras, números y guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre visible de la opción.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;
}
