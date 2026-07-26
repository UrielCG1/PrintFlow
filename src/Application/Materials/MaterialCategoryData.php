<?php

namespace App\Application\Materials;

use App\Entity\Materials\MaterialCategory;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: MaterialCategory::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe una categoría de materiales con este código.',
)]
#[UniqueEntity(
    fields: ['name'],
    entityClass: MaterialCategory::class,
    identifierFieldNames: ['id'],
    errorPath: 'name',
    message: 'Ya existe una categoría de materiales con este nombre.',
)]
final class MaterialCategoryData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'El código es obligatorio.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9][A-Z0-9._-]*$/i',
        message: 'Usa letras, números, puntos, guiones o guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'El nombre es obligatorio.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;
}