<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Quotations\Quotation;
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
}
