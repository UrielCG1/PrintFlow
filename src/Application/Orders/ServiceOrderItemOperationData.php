<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Entity\Operations\Operation;
use Symfony\Component\Validator\Constraints as Assert;

final class ServiceOrderItemOperationData
{
    #[Assert\NotNull(message: 'Selecciona una operación activa para la partida.')]
    public ?Operation $operation = null;
}
