<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Quotations\Quotation;
use App\Enum\Orders\ProductionOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrder> */
final class ServiceOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrder::class);
    }

    public function findOneBySourceQuotation(Quotation $quotation): ?ServiceOrder
    {
        return $this->findOneBy(['sourceQuotation' => $quotation]);
    }

    /** @return list<ServiceOrder> */
    public function findRecentForAdministration(): array
    {
        $idRows = $this->createQueryBuilder('serviceOrder')
            ->select('serviceOrder.id')
            ->orderBy('serviceOrder.createdAt', 'DESC')
            ->addOrderBy('serviceOrder.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getScalarResult();
        $ids = array_column($idRows, 'id');

        if ($ids === []) {
            return [];
        }

        /** @var list<ServiceOrder> $orders */
        $orders = $this->createQueryBuilder('serviceOrder')
            ->innerJoin('serviceOrder.sourceQuotation', 'quotation')
            ->leftJoin('serviceOrder.items', 'item')
            ->addSelect('quotation', 'item')
            ->andWhere('serviceOrder.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('serviceOrder.createdAt', 'DESC')
            ->addOrderBy('serviceOrder.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /** @return list<ServiceOrder> */
    public function findRecentForQualityReview(): array
    {
        $idRows = $this->createQueryBuilder('serviceOrder')
            ->select('serviceOrder.id')
            ->andWhere('serviceOrder.productionStatus = :status')
            ->setParameter('status', ProductionOrderStatus::WAITING_QUALITY->value)
            ->orderBy('serviceOrder.createdAt', 'DESC')
            ->addOrderBy('serviceOrder.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getScalarResult();
        $ids = array_column($idRows, 'id');

        if ($ids === []) {
            return [];
        }

        /** @var list<ServiceOrder> $orders */
        $orders = $this->createQueryBuilder('serviceOrder')
            ->innerJoin('serviceOrder.sourceQuotation', 'quotation')
            ->leftJoin('serviceOrder.items', 'item')
            ->leftJoin('item.commercialItem', 'commercialItem')
            ->leftJoin('item.operationPlans', 'plan')
            ->leftJoin('plan.executions', 'execution')
            ->addSelect('quotation', 'item', 'commercialItem', 'plan', 'execution')
            ->andWhere('serviceOrder.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('serviceOrder.createdAt', 'DESC')
            ->addOrderBy('serviceOrder.id', 'DESC')
            ->addOrderBy('item.lineNumber', 'ASC')
            ->addOrderBy('plan.sequenceNumber', 'ASC')
            ->addOrderBy('execution.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $orders;
    }
}
