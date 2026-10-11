<?php

declare(strict_types=1);

namespace App\Application\Production;

final class ProductionRouteTemplateData
{
    public ?string $code = null;
    public ?string $name = null;
    public ?string $description = null;
    public bool $isActive = true;

    /** @var list<ProductionRouteStepData> */
    public array $steps = [];
}
