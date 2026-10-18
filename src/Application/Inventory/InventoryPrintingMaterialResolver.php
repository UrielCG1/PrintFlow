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

        return self::authorizedVariants($plannedVariant, $this->substitutionRepository->findForOrder($plan->getServiceOrderItem()->getServiceOrder()));
    }

    /** @param iterable<\App\Entity\Inventory\InventoryCommitmentSubstitution> $substitutions @return list<MaterialVariant> */
    public static function authorizedVariants(MaterialVariant $plannedVariant, iterable $substitutions): array
    {
        /** @var array<int, MaterialVariant> $variants */
        $variants = [];
        $hasSubstitution = false;
        foreach ($substitutions as $substitution) {
            if (!self::sameVariant($substitution->getOriginalCommitment()->getVariant(), $plannedVariant)) {
                continue;
            }

            $hasSubstitution = true;
            if ($substitution->getOriginalCommitment()->getPendingQuantity() !== '0.000000') {
                $variants[$plannedVariant->getId()] = $plannedVariant;
            }

            $substitute = $substitution->getSubstituteCommitment()->getVariant();
            if ($substitute->getId() !== null) {
                $variants[$substitute->getId()] = $substitute;
            }
        }

        if (!$hasSubstitution) {
            $variants[$plannedVariant->getId()] = $plannedVariant;
        }

        return array_values($variants);
    }

    public function isVariantAllowedForPlan(ServiceOrderOperationPlan $plan, MaterialVariant $candidate): bool
    {
        foreach ($this->allowedVariantsForPlan($plan) as $allowedVariant) {
            if (self::sameVariant($allowedVariant, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function sameVariant(MaterialVariant $left, MaterialVariant $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
