<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristicOption;
use Symfony\Component\Validator\Constraints as Assert;

final class CommercialItemCharacteristicData
{
    public bool $isRequired = false;

    #[Assert\Range(min: 0, notInRangeMessage: 'El orden de visualización no puede ser negativo.')]
    public int $displayOrder = 0;

    /** @var list<CommercialCharacteristicOption> */
    public array $allowedOptions = [];
}
