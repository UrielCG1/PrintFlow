<?php

namespace App\Application\Clients;

use Symfony\Component\Validator\Constraints as Assert;

final class ClientContactData
{
    #[Assert\NotBlank(message: 'Captura el nombre completo del contacto.')]
    #[Assert\Length(max: 160)]
    public ?string $fullName = null;

    #[Assert\Length(max: 120)]
    public ?string $jobTitle = null;

    #[Assert\NotBlank(message: 'Captura el correo electrónico laboral del contacto.')]
    #[Assert\Email(message: 'Captura un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(
        pattern: '/^[0-9+\-() ]*$/',
        message: 'El teléfono contiene caracteres no permitidos.'
    )]
    public ?string $phone = null;

    #[Assert\Length(max: 100)]
    public ?string $workDays = null;

    #[Assert\Length(max: 160)]
    public ?string $workHours = null;

    public bool $isPrimary = false;
}
