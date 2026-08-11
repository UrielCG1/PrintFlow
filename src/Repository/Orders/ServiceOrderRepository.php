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
        /** @var list<ServiceOrder> $orders */
        $orders = $this->createQueryBuilder('serviceOrder')
            ->innerJoin('serviceOrder.sourceQuotation', 'quotation')
            ->addSelect('quotation')
            ->orderBy('serviceOrder.createdAt', 'DESC')
            ->addOrderBy('serviceOrder.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $orders;
    }
}
