<?php

declare(strict_types=1);

namespace App\Application\Orders;

use Symfony\Component\Validator\Constraints as Assert;

final class ServiceOrderPlanningData
{
    #[Assert\Date(message: 'Captura una fecha compromiso válida.')]
    public ?string $commitmentDate = null;

    #[Assert\Length(max: 500, maxMessage: 'El motivo no puede superar 500 caracteres.')]
    public string $reason = '';
}
