<?php

namespace App\Repository\Quotations;

use App\Entity\Quotations\Quotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quotation>
 */
final class QuotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quotation::class);
    }

    public function findOneByFolio(string $folio): ?Quotation
    {
        return $this->findOneBy([
            'folio' => strtoupper(trim($folio)),
        ]);
    }

    /**
     * @return list<Quotation>
     */
    public function findRecentForAdministration(): array
    {
        return $this->createQueryBuilder('quotation')
            ->orderBy('quotation.updatedAt', 'DESC')
            ->addOrderBy('quotation.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
    }
}