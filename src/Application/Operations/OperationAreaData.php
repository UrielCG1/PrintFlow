<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Entity\Operations\OperationArea;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: OperationArea::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un área operativa con este código.',
)]
#[UniqueEntity(
    fields: ['name'],
    entityClass: OperationArea::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe un área operativa con este nombre.',
)]
final class OperationAreaData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Captura el código del área operativa.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
        message: 'El código solo puede contener letras, números, guiones y guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre del área operativa.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;
}