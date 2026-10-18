<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Service\Inventory\InventoryShortageEmailNotifier;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Aparta las necesidades inventariables ya definidas por Producción para una orden. */
final class InventoryOrderCommitmentManager
{
    public function __construct(
        private readonly InventoryOrderRequirementCollector $requirementCollector,
        private readonly InventoryCommitmentManager $commitmentManager,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryShortageEmailNotifier $shortageEmailNotifier,
    ) {
    }

    /** @return list<InventoryCommitment> */
    public function commitForOrder(ServiceOrder $order, User $actor): array
    {
        if ($order->isCancelled()) {
            throw new \DomainException('No se pueden apartar materiales para una orden cancelada.');
        }
        [$commitments, $newShortages] = $this->entityManager->wrapInTransaction(function () use ($order, $actor): array {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->isCancelled()) {
                throw new \DomainException('No se pueden apartar materiales para una orden cancelada.');
            }
            $requirements = $this->requirementCollector->collect($order);
            $existing = $this->commitmentRepository->findForOrderForUpdate($order);
            $previousShortages = [];
            foreach ($existing as $commitment) {
                $previousShortages[(int) $commitment->getVariant()->getId()] = $commitment->getShortageQuantity();
            }
            if ($requirements === [] && $existing === []) {
                throw new \DomainException('La orden todavía no tiene materiales inventariables previstos. Registra la variante y su cantidad antes de apartar existencias.');
            }

            $requiredByVariant = [];
            foreach ($requirements as $requirement) {
                $id = $requirement['variant']->getId();
                if ($id === null) {
                    throw new \DomainException('La presentación de material debe estar registrada antes de apartar existencias.');
                }
                $requiredByVariant[$id] = $requirement;
            }

            // Una variante retirada de la previsión conserva sus consumos y
            // libera únicamente la porción que todavía estaba apartada.
            foreach ($existing as $commitment) {
                $id = $commitment->getVariant()->getId();
                if ($id !== null && !isset($requiredByVariant[$id])) {
                    $requiredByVariant[$id] = [
                        'variant' => $commitment->getVariant(),
                        'requiredQuantity' => $commitment->getConsumedQuantity(),
                    ];
                }
            }
            ksort($requiredByVariant, SORT_NUMERIC);

            $commitments = [];
            $newShortages = [];
            foreach ($requiredByVariant as $requirement) {
                $commitment = $this->commitmentManager->reconcile(
                    $order,
                    $requirement['variant'],
                    $requirement['requiredQuantity'],
                    $actor,
                );
                $commitments[] = $commitment;
                $previous = $previousShortages[(int) $requirement['variant']->getId()] ?? '0.000000';
                if (BigDecimal::of($commitment->getShortageQuantity())->isGreaterThan(BigDecimal::of($previous))
                    && $commitment->getStatus() !== \App\Enum\Inventory\InventoryCommitmentStatus::SUBSTITUTED) {
                    $newShortages[] = [$commitment, $previous];
                }
            }

            return [$commitments, $newShortages];
        });
        foreach ($newShortages as [$commitment, $previous]) {
            $this->shortageEmailNotifier->queue($commitment, $previous);
        }

        return $commitments;
    }
}
