<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Operations\Operation;
use App\Enum\Production\RouteCondition;

final class ProductionRouteStepData
{
    public ?Operation $operation = null;
    public RouteCondition $condition = RouteCondition::ALWAYS;
    public ?string $specificationKey = null;
    public ?string $specificationValue = null;
    public bool $isRequired = true;

    /** @return array<string, string>|null */
    public function conditionConfig(): ?array
    {
        if ($this->condition !== RouteCondition::SPECIFICATION_EQUALS) {
            return null;
        }

        return [
            'key' => strtoupper(trim((string) $this->specificationKey)),
            'value' => trim((string) $this->specificationValue),
        ];
    }
}
