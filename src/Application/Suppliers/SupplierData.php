<?php

namespace App\Application\Suppliers;

use App\Entity\Suppliers\Supplier;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['code'],
    entityClass: Supplier::class,
    identifierFieldNames: ['id'],
    errorPath: 'code',
    message: 'Ya existe un proveedor con este código.',
)]
#[UniqueEntity(
    fields: ['taxId'],
    entityClass: Supplier::class,
    identifierFieldNames: ['id'],
    errorPath: 'taxId',
    ignoreNull: true,
    message: 'Ya existe un proveedor con este RFC.',
)]
final class SupplierData
{
    public ?int $id = null;

    #[Assert\NotBlank(message: 'El código es obligatorio.')]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9][A-Z0-9._-]*$/i',
        message: 'Usa letras, números, puntos, guiones o guiones bajos.',
    )]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Captura el nombre comercial.')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 160)]
    public ?string $legalName = null;

    #[Assert\Length(max: 20)]
    public ?string $taxId = null;

    #[Assert\Email(message: 'Captura un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[0-9+\-() ]*$/',
        message: 'El teléfono contiene caracteres no permitidos.',
    )]
    public ?string $phone = null;

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;
}