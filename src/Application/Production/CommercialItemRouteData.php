<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Production\ProductionRouteTemplate;

final class CommercialItemRouteData
{
    public ?ProductionRouteTemplate $sourceTemplate = null;

    /** @var list<ProductionRouteStepData> */
    public array $steps = [];
}
