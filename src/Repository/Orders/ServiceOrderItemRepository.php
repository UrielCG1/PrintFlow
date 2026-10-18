<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrderItem;
use App\Enum\Orders\ProductionPriority;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrderItem> */
final class ServiceOrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrderItem::class);
    }

    /** @return list<ServiceOrderItem> */
    public function findForDesignQueue(): array
    {
        /** @var list<ServiceOrderItem> $items */
        $items = $this->createQueryBuilder('item')
            ->innerJoin('item.serviceOrder', 'serviceOrder')
            ->innerJoin('serviceOrder.sourceQuotation', 'quotation')
            ->innerJoin('item.commercialItem', 'commercialItem')
            ->addSelect('serviceOrder', 'quotation', 'commercialItem')
            ->andWhere('item.designStatus IN (:statuses)')
            ->setParameter('statuses', [
                ServiceOrderItemDesignStatus::PENDING_REVIEW,
                ServiceOrderItemDesignStatus::IN_REVIEW,
                ServiceOrderItemDesignStatus::BLOCKED,
            ])
            ->orderBy('serviceOrder.createdAt', 'ASC')
            ->addOrderBy('item.lineNumber', 'ASC')
            ->addOrderBy('item.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * @param list<int> $serviceOrderIds
     * @return array<int, ProductionPriority>
     */
    public function findHighestPrioritiesByServiceOrderIds(array $serviceOrderIds): array
    {
        $serviceOrderIds = array_values(array_unique(array_filter($serviceOrderIds, static fn (int $id): bool => $id > 0)));
        if ($serviceOrderIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('item')
            ->select('IDENTITY(item.serviceOrder) AS serviceOrderId')
            ->addSelect('item.priority AS priority')
            ->andWhere('IDENTITY(item.serviceOrder) IN (:serviceOrderIds)')
            ->setParameter('serviceOrderIds', $serviceOrderIds)
            ->getQuery()
            ->getScalarResult();

        $priorities = [];
        foreach ($rows as $row) {
            $serviceOrderId = (int) $row['serviceOrderId'];
            $priority = $row['priority'] instanceof ProductionPriority
                ? $row['priority']
                : ProductionPriority::from((string) $row['priority']);
            $priorities[$serviceOrderId] ??= ProductionPriority::NORMAL;
            $currentPriority = $priorities[$serviceOrderId];

            if ($priority->weight() > $currentPriority->weight()) {
                $priorities[$serviceOrderId] = $priority;
            }
        }

        return $priorities;
    }
}
