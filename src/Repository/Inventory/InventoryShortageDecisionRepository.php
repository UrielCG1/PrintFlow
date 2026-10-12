<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Orders\ServiceOrder;
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
}
