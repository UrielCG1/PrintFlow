<?php

namespace App\Application\Clients;

use Symfony\Component\Validator\Constraints as Assert;

final class ClientData
{
    #[Assert\NotBlank(message: 'Captura el nombre o razón social.')]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 20)]
    public ?string $taxId = null;

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