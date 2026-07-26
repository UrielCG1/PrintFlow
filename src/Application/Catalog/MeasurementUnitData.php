<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: MeasurementUnit::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una unidad de medida con este código.',
)]
final class MeasurementUnitData
{
    public ?int $id = null;
    #[Assert\NotBlank(message: 'Captura el código de la unidad de medida.')]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9²_-]*$/u', message: 'El código solo puede contener letras, números, guiones y guiones bajos.')]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre de la unidad de medida.')]
    #[Assert\Length(max: 80)]
    public ?string $name = null;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;
}