<?php

namespace App\Application\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Suppliers\Supplier;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: Material::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un material con este código.',
)]
final class MaterialData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'El código es obligatorio.')]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9][A-Z0-9._-]*$/i',
        message: 'Usa letras, números, puntos, guiones o guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'El nombre es obligatorio.')]
    #[Assert\Length(max: 160)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Selecciona una categoría de materiales.')]
    public ?MaterialCategory $category = null;

    #[Assert\NotNull(message: 'Selecciona la unidad de inventario.')]
    public ?MeasurementUnit $measurementUnit = null;

    #[Assert\NotBlank(message: 'El costo de referencia es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
        message: 'Captura un importe válido con máximo dos decimales.',
    )]
    public ?string $referenceCost = '0.00';

    #[Assert\NotBlank(message: 'El stock mínimo es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,8})(?:\.\d{1,3})?$/',
        message: 'Captura una cantidad válida con máximo tres decimales.',
    )]
    public ?string $minimumStock = '0.000';

    public ?Supplier $primarySupplier = null;

    #[Assert\Length(max: 65535)]
    public ?string $notes = null;
}