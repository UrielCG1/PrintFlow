<?php

namespace App\Application\Clients;

use App\Entity\Clients\ClientCategory;
use Symfony\Component\Validator\Constraints as Assert;

final class ClientData
{
    #[Assert\Choice(choices: ['COMPANY', 'INDIVIDUAL'])]
    public string $clientType = 'COMPANY';

    #[Assert\NotBlank(message: 'Captura el nombre o razón social.')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 20)]
    public ?string $taxId = null;

    #[Assert\Length(max: 160)]
    public ?string $legalName = null;

    #[Assert\Length(max: 160)] public ?string $businessActivity = null;
    #[Assert\Url(message: 'Captura una URL válida.')] #[Assert\Length(max: 255)] public ?string $website = null;
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\Regex(
        pattern: '/^\d{3}$/',
        message: 'El régimen fiscal debe contener exactamente 3 dígitos.'
    )]
    public ?string $taxRegimeCode = null;

    #[Assert\Regex(
        pattern: '/^\d{5}$/',
        message: 'El código postal fiscal debe contener exactamente 5 dígitos.'
    )]
    public ?string $fiscalPostalCode = null;

    #[Assert\Email(message: 'Captura un correo de facturación válido.')]
    #[Assert\Length(max: 180)]
    public ?string $billingEmail = null;

    #[Assert\Length(max: 10)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z][A-Za-z0-9]*$/',
        message: 'El uso CFDI solo puede contener letras y números.'
    )]
    public ?string $defaultCfdiUseCode = null;

    public ?ClientCategory $category = null;

    #[Assert\Range(
        min: 0,
        max: 100,
        notInRangeMessage: 'El descuento debe estar entre {{ min }} y {{ max }} %.'
    )]
    public float $defaultDiscountPercent = 0.0;

    #[Assert\Email(message: 'Captura un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[0-9+\-() ]*$/',
        message: 'El teléfono contiene caracteres no permitidos.'
    )]
    public ?string $phone = null;

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;
}
