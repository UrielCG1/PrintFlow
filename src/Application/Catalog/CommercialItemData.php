<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use Symfony\Component\Validator\Constraints as Assert;

final class CommercialItemData
{
    #[Assert\NotNull(message: 'Selecciona una categoría comercial.')]
    public ?CommercialCategory $category = null;

    #[Assert\NotNull(message: 'Selecciona una unidad de medida.')]
    public ?MeasurementUnit $measurementUnit = null;

    #[Assert\NotBlank(message: 'Captura el código interno.')]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', message: 'El código solo puede contener letras, números, guiones y guiones bajos.')]
    public ?string $code = null;

    #[Assert\Choice(choices: [CommercialItem::TYPE_SERVICE, CommercialItem::TYPE_PRODUCT], message: 'Selecciona un tipo comercial válido.')]
    public string $type = CommercialItem::TYPE_SERVICE;

    #[Assert\NotBlank(message: 'Captura el nombre del concepto comercial.')]
    #[Assert\Length(max: 160)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    /** Solo se utiliza al crear el concepto; los cambios posteriores van por updateBasePrice(). */
    #[Assert\NotBlank(message: 'Captura el precio base.')]
    #[Assert\Regex(pattern: '/^\d+(?:[.,]\d{1,2})?$/', message: 'El precio base debe ser un decimal no negativo con máximo dos decimales.')]
    public ?string $basePrice = '0.00';
}