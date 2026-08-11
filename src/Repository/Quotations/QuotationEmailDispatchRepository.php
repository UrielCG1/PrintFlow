<?php

declare(strict_types=1);

namespace App\Repository\Quotations;

use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationEmailDispatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuotationEmailDispatch>
 */
final class QuotationEmailDispatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuotationEmailDispatch::class);
    }

    /** @return list<QuotationEmailDispatch> */
    public function findForQuotation(Quotation $quotation): array
    {
        return $this->createQueryBuilder('dispatch')
            ->andWhere('dispatch.quotation = :quotation')
            ->setParameter('quotation', $quotation)
            ->orderBy('dispatch.sentAt', 'DESC')
            ->addOrderBy('dispatch.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
