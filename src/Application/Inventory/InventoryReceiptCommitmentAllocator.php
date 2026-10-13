<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;

/** Reserves freshly received physical stock against existing shortages in creation order. */
final class InventoryReceiptCommitmentAllocator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
    ) {
    }

    /** @return list<array{commitment_id: int|null, quantity: string}> */
    public function allocate(MaterialVariant $variant, User $actor, int $receiptId): array
    {
        $balance = $this->balanceRepository->findForUpdate($variant);
        if (!$balance instanceof MaterialVariantCost || BigDecimal::of($balance->getAvailableQuantity())->isZero()) {
            return [];
        }

        $allocations = [];
        foreach ($this->commitmentRepository->findShortagesForVariantForUpdate($variant) as $commitment) {
            $available = BigDecimal::of($balance->getAvailableQuantity());
            if ($available->isZero()) {
                break;
            }
            $shortage = BigDecimal::of($commitment->getShortageQuantity());
            $allocated = $shortage->isLessThan($available) ? $shortage : $available;
            if ($allocated->isZero()) {
                continue;
            }
            $quantity = $allocated->toScale(6)->__toString();
            $commitment->coverShortageQuantity($quantity);
            $balance->commit($quantity);
            if (BigDecimal::of($commitment->getShortageQuantity())->isZero()) {
                $this->decisionRepository->findActiveForCommitmentForUpdate($commitment)?->supersede();
            }
            $this->entityManager->persist((new InventoryMovement(
                $variant,
                $variant->getInventoryUnit(),
                $actor,
                MovementType::RESERVATION,
                '-'.$quantity,
                'inventory_receipt_replenishment',
            ))->setSourceId($receiptId));
            $allocations[] = ['commitment_id' => $commitment->getId(), 'quantity' => $quantity];
        }

        return $allocations;
    }
}
