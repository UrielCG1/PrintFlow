<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;

/** Aparta las necesidades inventariables ya definidas por Producción para una orden. */
final class InventoryOrderCommitmentManager
{
    public function __construct(
        private readonly InventoryOrderRequirementCollector $requirementCollector,
        private readonly InventoryCommitmentManager $commitmentManager,
    ) {
    }

    /** @return list<InventoryCommitment> */
    public function commitForOrder(ServiceOrder $order, User $actor): array
    {
        $requirements = $this->requirementCollector->collect($order);
        if ($requirements === []) {
            throw new \DomainException('La orden todavía no tiene materiales inventariables previstos. Registra la variante y su cantidad antes de apartar existencias.');
        }

        $commitments = [];
        foreach ($requirements as $requirement) {
            $commitments[] = $this->commitmentManager->commit(
                $order,
                $requirement['variant'],
                $requirement['requiredQuantity'],
                $actor,
            );
        }

        return $commitments;
    }
}
