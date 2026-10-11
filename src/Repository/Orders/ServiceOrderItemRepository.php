<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrderItem;
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
            ->innerJoin('item.commercialItem', 'commercialItem')
            ->addSelect('serviceOrder', 'commercialItem')
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
}
