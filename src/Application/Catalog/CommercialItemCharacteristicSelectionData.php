<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use Symfony\Component\Validator\Constraints as Assert;

final class CommercialItemCharacteristicSelectionData
{
    #[Assert\NotNull(message: 'Selecciona una característica.')]
    public ?CommercialCharacteristic $characteristic = null;
}
