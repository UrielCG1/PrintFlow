<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Orders\ServiceOrder;
use App\Enum\Inventory\InventoryShortageDecisionType;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;

/** Impide iniciar o reanudar producción cuando un faltante no tiene decisión vigente apta. */
final class InventoryProductionAuthorizationGuard
{
    public function __construct(
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
    ) {
    }

    public function assertOrderCanStartProduction(ServiceOrder $order): void
    {
        if ($order->isCancelled()) {
            throw new \DomainException('La orden está cancelada y no puede iniciar ni reanudar producción.');
        }
        foreach ($this->commitmentRepository->findShortagesForOrder($order) as $commitment) {
            $decision = $this->decisionRepository->findActiveForCommitment($commitment);
            if ($decision?->getType() === InventoryShortageDecisionType::WAIT_FOR_PURCHASE) {
                throw new \DomainException('La orden está en espera de compra de '.$commitment->getVariant()->getCode().'.');
            }
            if ($decision?->getType() !== InventoryShortageDecisionType::AUTHORIZE_PARTIAL_PRODUCTION) {
                throw new \DomainException('El faltante de '.$commitment->getVariant()->getCode().' requiere autorización administrativa antes de iniciar producción.');
            }
        }
    }
}
