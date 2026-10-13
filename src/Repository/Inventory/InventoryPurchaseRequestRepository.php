<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Enum\Inventory\InventoryPurchaseRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryPurchaseRequest> */
final class InventoryPurchaseRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryPurchaseRequest::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?InventoryPurchaseRequest
    {
        return $this->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
    }

    public function findForUpdate(int $id): ?InventoryPurchaseRequest
    {
        return $this->createQueryBuilder('request')
            ->andWhere('request.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** @return list<InventoryPurchaseRequest> */
    public function findRecent(int $limit = 30): array
    {
        /** @var list<InventoryPurchaseRequest> $requests */
        $requests = $this->createQueryBuilder('request')
            ->innerJoin('request.createdBy', 'createdBy')
            ->leftJoin('request.lines', 'line')
            ->leftJoin('line.variant', 'variant')
            ->leftJoin('variant.material', 'material')
            ->leftJoin('variant.inventoryUnit', 'unit')
            ->addSelect('createdBy', 'line', 'variant', 'material', 'unit')
            ->orderBy('request.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $requests;
    }

    /** @return list<InventoryPurchaseRequest> */
    public function findOpen(int $limit = 30): array
    {
        /** @var list<InventoryPurchaseRequest> $requests */
        $requests = $this->createQueryBuilder('request')
            ->innerJoin('request.createdBy', 'createdBy')
            ->leftJoin('request.lines', 'line')
            ->leftJoin('line.variant', 'variant')
            ->leftJoin('variant.material', 'material')
            ->leftJoin('variant.inventoryUnit', 'unit')
            ->addSelect('createdBy', 'line', 'variant', 'material', 'unit')
            ->andWhere('request.status IN (:statuses)')
            ->setParameter('statuses', [InventoryPurchaseRequestStatus::DRAFT, InventoryPurchaseRequestStatus::IN_PROGRESS])
            ->orderBy('request.createdAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $requests;
    }

    public function countInProgress(): int
    {
        return (int) $this->createQueryBuilder('request')
            ->select('COUNT(request.id)')
            ->andWhere('request.status = :status')
            ->setParameter('status', InventoryPurchaseRequestStatus::IN_PROGRESS)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<InventoryPurchaseRequest> */
    public function findInProgress(int $limit = 10): array
    {
        /** @var list<InventoryPurchaseRequest> $requests */
        $requests = $this->createQueryBuilder('request')
            ->andWhere('request.status = :status')
            ->setParameter('status', InventoryPurchaseRequestStatus::IN_PROGRESS)
            ->orderBy('request.trackingStartedAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();

        return $requests;
    }
}
