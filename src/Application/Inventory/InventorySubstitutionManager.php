<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryCommitmentSubstitution;
use App\Entity\Inventory\MaterialSubstitutionRule;
use App\Entity\Users\User;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Reserva un sustituto viable y conserva evidencia del faltante original. */
final class InventorySubstitutionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryCommitmentManager $commitmentManager,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function substitute(InventoryCommitment $original, MaterialSubstitutionRule $rule, string $reason, User $actor): InventoryCommitmentSubstitution
    {
        return $this->entityManager->wrapInTransaction(function () use ($original, $rule, $reason, $actor): InventoryCommitmentSubstitution {
            $order = $original->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($original, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($rule, LockMode::PESSIMISTIC_WRITE);
            $substitute = $rule->getSubstituteVariant();
            $this->entityManager->lock($substitute, LockMode::PESSIMISTIC_WRITE);

            if (!$rule->isActive() || $rule->getOriginalVariant()->getId() !== $original->getVariant()->getId()) { throw new \DomainException('La regla de sustitución ya no aplica a este faltante.'); }
            if ($original->getShortageQuantity() === '0.000000' || $original->getStatus()->value === 'SUBSTITUTED') { throw new \DomainException('Este faltante ya fue resuelto o sustituido.'); }
            if (!$substitute->isActive() || !$substitute->isConsumable()) { throw new \DomainException('El sustituto no está disponible para nuevas operaciones.'); }
            if ($this->commitmentRepository->findOneForOrderAndVariant($order, $substitute) !== null) { throw new \DomainException('La orden ya tiene un apartado para la variante sustituta; requiere conciliación manual.'); }

            $originalShortage = $original->getShortageQuantity();
            $substituteQuantity = BigDecimal::of($originalShortage)->multipliedBy($rule->getSubstitutePerOriginal())->toScale(6, RoundingMode::UP)->__toString();
            $substituteCommitment = $this->commitmentManager->commit($order, $substitute, $substituteQuantity, $actor);
            if ($substituteCommitment->getShortageQuantity() !== '0.000000') { throw new \DomainException('El sustituto viable no tiene saldo suficiente para cubrir el faltante completo.'); }

            $this->decisionRepository->findActiveForCommitmentForUpdate($original)?->supersede();
            $original->markShortageSubstituted();
            $substitution = new InventoryCommitmentSubstitution($original, $substituteCommitment, $rule, $originalShortage, $substituteQuantity, $reason, $actor);
            $this->entityManager->persist($substitution);

            $before = $order->traceabilityStateSnapshot();
            $order->touchTraceability();
            $after = $order->traceabilityStateSnapshot();
            $this->traceabilityRecorder->record($order, 'inventory.shortage_substituted', $actor, $before, $after, null, null, null, $reason, ['original_commitment_id' => $original->getId(), 'substitute_commitment_id' => $substituteCommitment->getId(), 'rule_id' => $rule->getId(), 'substitute_quantity' => $substituteQuantity]);
            $this->auditLogger->record($actor, 'inventory.shortage_substituted', 'inventory_commitment', $original->getId(), null, ['substitute_variant_id' => $substitute->getId(), 'substitute_quantity' => $substituteQuantity, 'rule_id' => $rule->getId()]);
            $this->entityManager->flush();
            return $substitution;
        });
    }
}
