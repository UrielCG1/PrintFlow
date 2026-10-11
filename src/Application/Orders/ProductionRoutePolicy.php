<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Entity\Production\CommercialItemRouteVersion;
use App\Enum\Production\RouteCondition;

/** Rules shared by route publishing and work-order route snapshots. */
final class ProductionRoutePolicy
{
    public const DESIGN_OPERATION_CODE = 'PRE-DISENO';
    public const DESIGN_AREA_CODE = 'PREPRESS';

    /** @param list<ProductionRouteStepData> $steps */
    public static function assertStartsWithDesign(array $steps): void
    {
        $first = $steps[0] ?? null;
        $operation = $first?->operation;

        if ($operation === null
            || $operation->getCode() !== self::DESIGN_OPERATION_CODE
            || $operation->getOperationArea()->getCode() !== self::DESIGN_AREA_CODE
            || $first->condition !== RouteCondition::ALWAYS
            || !$first->isRequired) {
            throw new \DomainException('La primera operación obligatoria de toda ruta debe ser Diseño (PRE-DISENO), sin condición.');
        }
    }

    public static function designFirstIssue(CommercialItemRouteVersion $version): ?string
    {
        $steps = $version->getSteps()->toArray();
        usort($steps, static fn ($left, $right): int => $left->getSequenceNumber() <=> $right->getSequenceNumber());
        $first = $steps[0] ?? null;

        if ($first === null
            || $first->getOperation()->getCode() !== self::DESIGN_OPERATION_CODE
            || $first->getOperation()->getOperationArea()->getCode() !== self::DESIGN_AREA_CODE
            || $first->getCondition() !== RouteCondition::ALWAYS
            || !$first->isRequired()) {
            return 'La ruta publicada no inicia con Diseño obligatorio; requiere corrección antes de planificar.';
        }

        return null;
    }
}
