<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Orders\ServiceOrder;
use App\Enum\Inventory\InventoryShortageDecisionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryShortageDecision> */
final class InventoryShortageDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryShortageDecision::class);
    }

    public function findActiveForCommitment(InventoryCommitment $commitment): ?InventoryShortageDecision
    {
        return $this->findOneBy(['commitment' => $commitment, 'isActive' => true]);
    }

    public function findActiveForCommitmentForUpdate(InventoryCommitment $commitment): ?InventoryShortageDecision
    {
        /** @var InventoryShortageDecision|null $decision */
        $decision = $this->createQueryBuilder('decision')
            ->andWhere('decision.commitment = :commitment')
            ->andWhere('decision.isActive = true')
            ->setParameter('commitment', $commitment)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $decision;
    }

    /** @return list<InventoryShortageDecision> */
    public function findActiveForOrder(ServiceOrder $order): array
    {
        /** @var list<InventoryShortageDecision> $decisions */
        $decisions = $this->createQueryBuilder('decision')
            ->innerJoin('decision.commitment', 'commitment')
            ->addSelect('commitment')
            ->andWhere('commitment.serviceOrder = :order')
            ->andWhere('decision.isActive = true')
            ->setParameter('order', $order)
            ->orderBy('decision.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $decisions;
    }

    /** @return list<int> */
    public function findActiveWaitingCommitmentIds(): array
    {
        $rows = $this->createQueryBuilder('decision')
            ->select('IDENTITY(decision.commitment) AS commitment_id')
            ->andWhere('decision.isActive = true')
            ->andWhere('decision.type = :type')
            ->setParameter('type', InventoryShortageDecisionType::WAIT_FOR_PURCHASE)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): int => (int) $row['commitment_id'], $rows));
    }

    /** @return list<InventoryShortageDecision> */
    public function findActiveWaitingForVariant(\App\Entity\Materials\MaterialVariant $variant): array
    {
        /** @var list<InventoryShortageDecision> $decisions */
        $decisions = $this->createQueryBuilder('decision')
            ->innerJoin('decision.commitment', 'commitment')
            ->addSelect('commitment')
            ->andWhere('decision.isActive = true')
            ->andWhere('decision.type = :type')
            ->andWhere('commitment.variant = :variant')
            ->setParameter('type', InventoryShortageDecisionType::WAIT_FOR_PURCHASE)
            ->setParameter('variant', $variant)
            ->orderBy('commitment.createdAt', 'ASC')
            ->addOrderBy('commitment.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $decisions;
    }

    /** @param list<InventoryCommitment> $commitments @return array<int, InventoryShortageDecision> */
    public function findActiveForCommitments(array $commitments): array
    {
        if ($commitments === []) {
            return [];
        }

        /** @var list<InventoryShortageDecision> $decisions */
        $decisions = $this->createQueryBuilder('decision')
            ->andWhere('decision.isActive = true')
            ->andWhere('decision.commitment IN (:commitments)')
            ->setParameter('commitments', $commitments)
            ->getQuery()
            ->getResult();

        $byCommitment = [];
        foreach ($decisions as $decision) {
            $commitmentId = $decision->getCommitment()->getId();
            if ($commitmentId !== null) {
                $byCommitment[$commitmentId] = $decision;
            }
        }

        return $byCommitment;
    }

    public function countActiveWaiting(): int
    {
        return (int) $this->createQueryBuilder('decision')
            ->select('COUNT(decision.id)')
            ->andWhere('decision.isActive = true')
            ->andWhere('decision.type = :type')
            ->setParameter('type', InventoryShortageDecisionType::WAIT_FOR_PURCHASE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<InventoryShortageDecision> */
    public function findActiveWaitingForIndicators(int $limit = 10): array
    {
        /** @var list<InventoryShortageDecision> $decisions */
        $decisions = $this->createQueryBuilder('decision')
            ->innerJoin('decision.commitment', 'commitment')
            ->innerJoin('commitment.serviceOrder', 'serviceOrder')
            ->innerJoin('commitment.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('commitment', 'serviceOrder', 'variant', 'material', 'unit')
            ->andWhere('decision.isActive = true')
            ->andWhere('decision.type = :type')
            ->setParameter('type', InventoryShortageDecisionType::WAIT_FOR_PURCHASE)
            ->orderBy('decision.createdAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();

        return $decisions;
    }
}
