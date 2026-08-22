<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CommercialItemType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: CommercialItem::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un producto o servicio con este código.',
)]
final class CommercialItemData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'El código es obligatorio.')]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9][A-Z0-9._-]*$/i',
        message: 'Usa letras, números, puntos, guiones o guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotNull(message: 'Selecciona si se trata de un producto o un servicio.')]
    public ?CommercialItemType $type = CommercialItemType::SERVICE;

    #[Assert\NotNull(message: 'Selecciona el perfil de especificaciones para cotización.')]
    public ?QuotationItemSpecificationProfile $quotationSpecificationProfile = QuotationItemSpecificationProfile::NONE;

    #[Assert\NotBlank(message: 'El nombre es obligatorio.')]
    #[Assert\Length(max: 160)]
    public ?string $name = null;

    #[Assert\Length(max: 65535)]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Selecciona una categoría comercial.')]
    public ?CommercialCategory $category = null;

    #[Assert\NotNull(message: 'Selecciona una unidad de medida.')]
    public ?MeasurementUnit $measurementUnit = null;

    #[Assert\NotBlank(message: 'El precio base es obligatorio.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',
        message: 'Captura un importe válido con máximo dos decimales.',
    )]
    public ?string $basePrice = '0.00';
}
