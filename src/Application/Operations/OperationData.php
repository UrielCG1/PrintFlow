<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: Operation::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una operación con este código.',
)]
#[UniqueEntity(
    fields: ['operationArea', 'name'],
    entityClass: Operation::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe una operación con este nombre en el área seleccionada.',
)]
final class OperationData
{
    public ?int $id = null;

    #[Assert\NotNull(message: 'Selecciona el área operativa.')]
    public ?OperationArea $operationArea = null;

    #[Assert\NotBlank(message: 'Captura el código de la operación.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
        message: 'El código solo puede contener letras, números, guiones y guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre de la operación.')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;
}