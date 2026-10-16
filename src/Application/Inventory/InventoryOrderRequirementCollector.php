<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Repository\Inventory\InventoryCommitmentSubstitutionRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Convierte las previsiones vigentes de Producción a cantidades apartables por variante. */
final class InventoryOrderRequirementCollector
{
    public function __construct(private readonly ProductionMaterialUsageRepository $usageRepository, private readonly InventoryCommitmentSubstitutionRepository $substitutionRepository) {}

    /** @return list<array{variant: MaterialVariant, requiredQuantity: string}> */
    public function collect(ServiceOrder $order): array
    {
        $requirements = [];
        foreach ($this->usageRepository->findPlannedForServiceOrder($order) as $usage) {
            $variant = $usage->getVariant();
            $planned = $usage->getPlannedQuantity();
            if ($variant === null || $planned === null) { continue; }
            $factor = BigDecimal::of($variant->getInventoryToConsumptionFactor());
            if ($factor->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new \DomainException('La variante '.$variant->getCode().' no tiene una conversión válida de inventario a consumo.');
            }
            $required = BigDecimal::of($planned)->dividedBy($factor, 6, RoundingMode::Up)->__toString();
            $key = (int) $variant->getId();
            if (isset($requirements[$key])) {
                $requirements[$key]['requiredQuantity'] = BigDecimal::of($requirements[$key]['requiredQuantity'])
                    ->plus($required)->toScale(6)->__toString();
                continue;
            }
            $requirements[$key] = ['variant' => $variant, 'requiredQuantity' => $required];
        }

        foreach ($this->substitutionRepository->findForOrder($order) as $substitution) {
            $original = $substitution->getOriginalCommitment();
            $originalKey = (int) $original->getVariant()->getId();
            $plannedOriginal = $requirements[$originalKey]['requiredQuantity'] ?? null;
            if ($plannedOriginal === null || !BigDecimal::of($plannedOriginal)->isEqualTo(BigDecimal::of($original->getRequestedQuantity()))) {
                throw new \DomainException('La necesidad de '.$original->getVariant()->getCode().' cambió después de autorizar un sustituto. Revisa la sustitución antes de recalcular los apartados.');
            }
            if ($original->getCommittedQuantity() === '0.000000') {
                unset($requirements[$originalKey]);
            } else {
                $requirements[$originalKey] = ['variant' => $original->getVariant(), 'requiredQuantity' => $original->getCommittedQuantity()];
            }
            $substitute = $substitution->getSubstituteCommitment();
            $requirements[(int) $substitute->getVariant()->getId()] = ['variant' => $substitute->getVariant(), 'requiredQuantity' => $substitution->getSubstituteQuantity()];
        }

        return array_values($requirements);
    }
}
