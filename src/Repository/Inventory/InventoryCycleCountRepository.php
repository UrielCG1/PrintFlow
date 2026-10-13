<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryCycleCountStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryCycleCount> */
final class InventoryCycleCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryCycleCount::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?InventoryCycleCount
    {
        return $this->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
    }

    public function findPendingForStockUnit(InventoryStockUnit $stockUnit): ?InventoryCycleCount
    {
        return $this->findOneBy([
            'stockUnit' => $stockUnit,
            'status' => InventoryCycleCountStatus::PENDING_REVIEW,
        ]);
    }

    public function findForUpdate(int $id): ?InventoryCycleCount
    {
        return $this->createQueryBuilder('cycleCount')
            ->andWhere('cycleCount.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** @return list<InventoryCycleCount> */
    public function findPendingForReview(int $limit = 30): array
    {
        /** @var list<InventoryCycleCount> $counts */
        $counts = $this->baseListQuery()
            ->andWhere('cycleCount.status = :status')
            ->setParameter('status', InventoryCycleCountStatus::PENDING_REVIEW)
            ->orderBy('cycleCount.countedAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $counts;
    }

    /** @return list<InventoryCycleCount> */
    public function findRecentForRequester(User $requester, int $limit = 25): array
    {
        /** @var list<InventoryCycleCount> $counts */
        $counts = $this->baseListQuery()
            ->andWhere('cycleCount.requestedBy = :requester')
            ->setParameter('requester', $requester)
            ->orderBy('cycleCount.countedAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $counts;
    }

    /** @return list<InventoryCycleCount> */
    public function findRecent(int $limit = 25): array
    {
        /** @var list<InventoryCycleCount> $counts */
        $counts = $this->baseListQuery()
            ->orderBy('cycleCount.countedAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $counts;
    }

    private function baseListQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('cycleCount')
            ->innerJoin('cycleCount.stockUnit', 'stockUnit')
            ->innerJoin('cycleCount.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->innerJoin('cycleCount.requestedBy', 'requestedBy')
            ->leftJoin('cycleCount.reviewedBy', 'reviewedBy')
            ->addSelect('stockUnit', 'variant', 'material', 'unit', 'requestedBy', 'reviewedBy');
    }
}
