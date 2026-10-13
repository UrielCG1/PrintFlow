<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Repository\Inventory\InventoryCommitmentSubstitutionRepository;

/** Resolves variants authorized for the physical-material scan of a print plan. */
final class InventoryPrintingMaterialResolver
{
    public function __construct(private readonly InventoryCommitmentSubstitutionRepository $substitutionRepository)
    {
    }

    /** @return list<MaterialVariant> */
    public function allowedVariantsForPlan(ServiceOrderOperationPlan $plan): array
    {
        $plannedVariant = $plan->getMaterialVariant();
        if (!$plannedVariant instanceof MaterialVariant || $plannedVariant->getId() === null) {
            return [];
        }

        /** @var array<int, MaterialVariant> $variants */
        $variants = [$plannedVariant->getId() => $plannedVariant];
        foreach ($this->substitutionRepository->findForOrder($plan->getServiceOrderItem()->getServiceOrder()) as $substitution) {
            if (!$this->sameVariant($substitution->getOriginalCommitment()->getVariant(), $plannedVariant)) {
                continue;
            }

            $substitute = $substitution->getSubstituteCommitment()->getVariant();
            if ($substitute->getId() !== null) {
                $variants[$substitute->getId()] = $substitute;
            }
        }

        return array_values($variants);
    }

    public function isVariantAllowedForPlan(ServiceOrderOperationPlan $plan, MaterialVariant $candidate): bool
    {
        foreach ($this->allowedVariantsForPlan($plan) as $allowedVariant) {
            if ($this->sameVariant($allowedVariant, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function sameVariant(MaterialVariant $left, MaterialVariant $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
