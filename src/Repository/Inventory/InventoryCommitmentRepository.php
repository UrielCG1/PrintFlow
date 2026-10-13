<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Enum\Inventory\InventoryCommitmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryCommitment> */
final class InventoryCommitmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryCommitment::class);
    }

    public function findOneForOrderAndVariant(ServiceOrder $order, MaterialVariant $variant): ?InventoryCommitment
    {
        return $this->findOneBy(['serviceOrder' => $order, 'variant' => $variant]);
    }

    /** @return list<InventoryCommitment> */
    public function findForOrder(ServiceOrder $order): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('variant', 'material', 'unit')
            ->andWhere('commitment.serviceOrder = :order')
            ->setParameter('order', $order)
            ->orderBy('material.name', 'ASC')
            ->addOrderBy('variant.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $commitments;
    }

    /** @return list<InventoryCommitment> */
    public function findForOrderForUpdate(ServiceOrder $order): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('variant', 'material', 'unit')
            ->andWhere('commitment.serviceOrder = :order')
            ->setParameter('order', $order)
            ->orderBy('variant.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $commitments;
    }

    /** @return list<InventoryCommitment> */
    public function findShortages(int $limit = 8): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.serviceOrder', 'serviceOrder')
            ->innerJoin('commitment.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('serviceOrder', 'variant', 'material', 'unit')
            ->andWhere('commitment.status IN (:statuses)')
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->orderBy('commitment.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();

        return $commitments;
    }

    /** @return list<InventoryCommitment> */
    public function findShortagesForReplenishment(): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.serviceOrder', 'serviceOrder')
            ->innerJoin('commitment.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('serviceOrder', 'variant', 'material', 'unit')
            ->andWhere('commitment.status IN (:statuses)')
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->orderBy('variant.id', 'ASC')
            ->addOrderBy('commitment.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $commitments;
    }

    /** @return list<InventoryCommitment> */
    public function findShortagesForOrder(ServiceOrder $order): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.variant', 'variant')
            ->addSelect('variant')
            ->andWhere('commitment.serviceOrder = :order')
            ->andWhere('commitment.status IN (:statuses)')
            ->setParameter('order', $order)
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->orderBy('commitment.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $commitments;
    }
}
