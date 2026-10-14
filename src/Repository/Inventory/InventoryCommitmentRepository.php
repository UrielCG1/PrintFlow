<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Enum\Orders\ProductionOrderStatus;
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
            ->andWhere('serviceOrder.productionStatus <> :cancelled')
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->setParameter('cancelled', ProductionOrderStatus::CANCELLED)
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
            ->andWhere('serviceOrder.productionStatus <> :cancelled')
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->setParameter('cancelled', ProductionOrderStatus::CANCELLED)
            ->orderBy('variant.id', 'ASC')
            ->addOrderBy('commitment.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $commitments;
    }

    /** @return list<InventoryCommitment> */
    public function findShortagesForVariantForUpdate(MaterialVariant $variant): array
    {
        /** @var list<InventoryCommitment> $commitments */
        $commitments = $this->createQueryBuilder('commitment')
            ->innerJoin('commitment.serviceOrder', 'serviceOrder')
            ->addSelect('serviceOrder')
            ->andWhere('commitment.variant = :variant')
            ->andWhere('commitment.status IN (:statuses)')
            ->andWhere('serviceOrder.productionStatus <> :cancelled')
            ->setParameter('variant', $variant)
            ->setParameter('statuses', [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE])
            ->setParameter('cancelled', ProductionOrderStatus::CANCELLED)
            ->orderBy('commitment.createdAt', 'ASC')
            ->addOrderBy('commitment.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
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
