<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Confirma una cobertura consultada previamente bajo bloqueos de orden y variante. */
final class InventoryCommitmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly InventoryCoverageEvaluator $coverageEvaluator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function commit(ServiceOrder $order, MaterialVariant $variant, string $requestedQuantity, User $actor): InventoryCommitment
    {
        return $this->reconcileWithinTransaction($order, $variant, $requestedQuantity, $actor, false);
    }

    /**
     * Relee el saldo y aplica solo la diferencia de una necesidad vigente.
     * Permite cero para retirar una previsión que aún no ha tenido consumo.
     */
    public function reconcile(ServiceOrder $order, MaterialVariant $variant, string $requestedQuantity, User $actor): InventoryCommitment
    {
        return $this->reconcileWithinTransaction($order, $variant, $requestedQuantity, $actor, true);
    }

    private function reconcileWithinTransaction(
        ServiceOrder $order,
        MaterialVariant $variant,
        string $requestedQuantity,
        User $actor,
        bool $allowZero,
    ): InventoryCommitment {
        $requestedQuantity = $this->normalizeRequestedQuantity($requestedQuantity, $allowZero);

        return $this->entityManager->wrapInTransaction(function () use ($order, $variant, $requestedQuantity, $actor): InventoryCommitment {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->commitmentRepository->findOneForOrderAndVariant($order, $variant);
            if ($existing instanceof InventoryCommitment) {
                // El requisito original queda como evidencia cuando existe un
                // sustituto autorizado. El collector ya devuelve su parte
                // cubierta y no debe volver a reservar ni borrar ese hecho.
                if ($existing->getStatus() === \App\Enum\Inventory\InventoryCommitmentStatus::SUBSTITUTED) {
                    return $existing;
                }

                $balance = $this->balanceRepository->findForUpdate($variant);
                if (!$balance instanceof MaterialVariantCost) {
                    $balance = new MaterialVariantCost($variant);
                    $this->entityManager->persist($balance);
                }

                $before = $this->snapshot($existing);
                $releasedQuantity = $existing->reconcileRequestedQuantity($requestedQuantity);
                if (!BigDecimal::of($releasedQuantity)->isZero()) {
                    $balance->releaseCommitment($releasedQuantity);
                    $this->entityManager->persist($this->movement(
                        $variant,
                        $actor,
                        MovementType::RELEASE,
                        $releasedQuantity,
                        'inventory_commitment_reconciliation',
                        $existing->getId(),
                    ));
                }

                $coveredQuantity = '0.000000';
                if (!BigDecimal::of($existing->getShortageQuantity())->isZero()) {
                    $coverage = $this->coverageEvaluator->evaluate(
                        $existing->getShortageQuantity(),
                        $balance->getAvailableQuantity(),
                    );
                    $coveredQuantity = $coverage->coveredQuantity;
                    if (!BigDecimal::of($coveredQuantity)->isZero()) {
                        $existing->coverShortageQuantity($coveredQuantity);
                        $balance->commit($coveredQuantity);
                        $this->entityManager->persist($this->movement(
                            $variant,
                            $actor,
                            MovementType::RESERVATION,
                            '-'.$coveredQuantity,
                            'inventory_commitment_reconciliation',
                            $existing->getId(),
                        ));
                    }
                }

                if ($before['requested_quantity'] !== $existing->getRequestedQuantity()
                    || BigDecimal::of($existing->getShortageQuantity())->isGreaterThan(BigDecimal::of($before['shortage_quantity']))
                    || BigDecimal::of($existing->getShortageQuantity())->isZero()) {
                    $this->decisionRepository->findActiveForCommitmentForUpdate($existing)?->supersede();
                }

                $after = $this->snapshot($existing);
                if ($before !== $after) {
                    $this->auditLogger->record(
                        $actor,
                        'inventory.commitment.reconciled',
                        'inventory_commitment',
                        $existing->getId(),
                        $before,
                        $after + [
                            'released_quantity_now' => $releasedQuantity,
                            'covered_quantity_now' => $coveredQuantity,
                            'service_order_id' => $order->getId(),
                        ],
                    );
                }
                $this->entityManager->flush();

                return $existing;
            }
            if (BigDecimal::of($requestedQuantity)->isZero()) {
                throw new \DomainException('No se puede crear un apartado nuevo con necesidad cero.');
            }
            $balance = $this->balanceRepository->findForUpdate($variant);
            if (!$balance instanceof MaterialVariantCost) { $balance = new MaterialVariantCost($variant); $this->entityManager->persist($balance); }
            $coverage = $this->coverageEvaluator->evaluate($requestedQuantity, $balance->getAvailableQuantity());
            $commitment = new InventoryCommitment($order, $variant, $actor, $coverage);
            $this->entityManager->persist($commitment);
            if ($coverage->coveredQuantity !== '0.000000') { $balance->commit($coverage->coveredQuantity); }
            $this->entityManager->flush();
            if ($coverage->coveredQuantity !== '0.000000') {
                $movement = new InventoryMovement($variant, $variant->getInventoryUnit(), $actor, MovementType::RESERVATION, '-'.$coverage->coveredQuantity, 'inventory_commitment');
                $movement->setSourceId($commitment->getId());
                $this->entityManager->persist($movement);
            }
            $this->auditLogger->record($actor, 'inventory.commitment.recorded', 'inventory_commitment', $commitment->getId(), null, ['service_order_id' => $order->getId(), 'variant_id' => $variant->getId(), 'committed_quantity' => $coverage->coveredQuantity, 'shortage_quantity' => $coverage->shortageQuantity]);
            $this->entityManager->flush();
            return $commitment;
        });
    }

    private function normalizeRequestedQuantity(string $quantity, bool $allowZero): string
    {
        $quantity = trim(str_replace(',', '.', $quantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new \InvalidArgumentException('La cantidad requerida no tiene un formato válido.');
        }

        $normalized = BigDecimal::of($quantity)->toScale(6)->__toString();
        if (!$allowZero && BigDecimal::of($normalized)->isZero()) {
            throw new \InvalidArgumentException('La cantidad requerida debe ser mayor que cero.');
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function snapshot(InventoryCommitment $commitment): array
    {
        return [
            'requested_quantity' => $commitment->getRequestedQuantity(),
            'committed_quantity' => $commitment->getCommittedQuantity(),
            'consumed_quantity' => $commitment->getConsumedQuantity(),
            'released_quantity' => $commitment->getReleasedQuantity(),
            'shortage_quantity' => $commitment->getShortageQuantity(),
            'status' => $commitment->getStatus()->value,
        ];
    }

    private function movement(
        MaterialVariant $variant,
        User $actor,
        MovementType $type,
        string $quantity,
        string $sourceType,
        ?int $sourceId,
    ): InventoryMovement {
        return (new InventoryMovement($variant, $variant->getInventoryUnit(), $actor, $type, $quantity, $sourceType))
            ->setSourceId($sourceId);
    }
}
