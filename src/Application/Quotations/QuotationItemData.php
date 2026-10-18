<?php

namespace App\Application\Quotations;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class QuotationItemData
{
    public const QUANTITY_MODE_AUTO = 'AUTO';
    public const QUANTITY_MODE_MANUAL = 'MANUAL';

    #[Assert\NotNull(message: 'Selecciona una categoría.')]
    public ?CommercialCategory $commercialCategory = null;

    #[Assert\NotNull(message: 'Selecciona un Producto.')]
    public ?CommercialItem $commercialItem = null;

    #[Assert\NotBlank(message: 'Captura la cantidad.')]
    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,9})(?:[.,]\d{1,6})?$/',
        message: 'La cantidad debe usar hasta seis decimales.',
    )]
    public ?string $quantity = '1.0000';

    /** @var array<string, string> */
    public array $specifications = [];

    #[Assert\Choice(
        choices: [self::QUANTITY_MODE_AUTO, self::QUANTITY_MODE_MANUAL],
        message: 'El origen de la cantidad no es válido.',
    )]
    public string $quantityMode = self::QUANTITY_MODE_AUTO;

    #[Assert\File(
        maxSize: '20M',
        extensions: ['pdf', 'ai', 'eps', 'svg', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'zip'],
        extensionsMessage: 'Adjunta un PDF, AI, EPS, SVG, JPG, PNG, TIFF o ZIP.',
    )]
    public ?UploadedFile $attachment = null;

    public ?int $existingItemId = null;
    public ?string $attachmentPath = null;
    public ?string $attachmentOriginalName = null;
}
