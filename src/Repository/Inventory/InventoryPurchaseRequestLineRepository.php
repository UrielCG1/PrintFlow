<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryPurchaseRequestLine;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Inventory\InventoryPurchaseRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryPurchaseRequestLine> */
final class InventoryPurchaseRequestLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryPurchaseRequestLine::class);
    }

    public function findForUpdate(int $id): ?InventoryPurchaseRequestLine
    {
        return $this->createQueryBuilder('line')
            ->innerJoin('line.purchaseRequest', 'purchaseRequest')
            ->addSelect('purchaseRequest')
            ->andWhere('line.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findOpenForVariant(MaterialVariant $variant): ?InventoryPurchaseRequestLine
    {
        return $this->createQueryBuilder('line')
            ->innerJoin('line.purchaseRequest', 'purchaseRequest')
            ->andWhere('line.variant = :variant')
            ->andWhere('purchaseRequest.status IN (:statuses)')
            ->setParameter('variant', $variant)
            ->setParameter('statuses', [InventoryPurchaseRequestStatus::DRAFT, InventoryPurchaseRequestStatus::IN_PROGRESS])
            ->orderBy('line.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @param list<MaterialVariant> $variants @return list<InventoryPurchaseRequestLine> */
    public function findOpenForVariants(array $variants): array
    {
        if ($variants === []) {
            return [];
        }

        /** @var list<InventoryPurchaseRequestLine> $lines */
        $lines = $this->createQueryBuilder('line')
            ->innerJoin('line.purchaseRequest', 'purchaseRequest')
            ->innerJoin('line.variant', 'variant')
            ->addSelect('purchaseRequest', 'variant')
            ->andWhere('line.variant IN (:variants)')
            ->andWhere('purchaseRequest.status IN (:statuses)')
            ->setParameter('variants', $variants)
            ->setParameter('statuses', [InventoryPurchaseRequestStatus::DRAFT, InventoryPurchaseRequestStatus::IN_PROGRESS])
            ->orderBy('line.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lines;
    }

    /** @return array<int, string> keyed by material variant id */
    public function findTrackedPendingQuantitiesByVariant(): array
    {
        $rows = $this->createQueryBuilder('line')
            ->select('IDENTITY(line.variant) AS variant_id')
            ->addSelect('SUM(line.requestedQuantity - line.receivedQuantity) AS pending_quantity')
            ->innerJoin('line.purchaseRequest', 'purchaseRequest')
            ->andWhere('purchaseRequest.status = :status')
            ->setParameter('status', InventoryPurchaseRequestStatus::IN_PROGRESS)
            ->groupBy('line.variant')
            ->getQuery()
            ->getScalarResult();

        $quantities = [];
        foreach ($rows as $row) {
            $quantities[(int) $row['variant_id']] = (string) $row['pending_quantity'];
        }

        return $quantities;
    }
}
