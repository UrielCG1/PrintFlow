<?php

namespace App\Repository\Quotations;

use App\Entity\Quotations\Quotation;
use App\Enum\Quotations\QuotationStatus;
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
            ->leftJoin('quotation.previousRevision', 'previousRevision')
            ->addSelect('previousRevision')
            ->orderBy('quotation.updatedAt', 'DESC')
            ->addOrderBy('quotation.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Quotation>
     */
    public function findExpirableBefore(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('quotation')
            ->andWhere('quotation.status IN (:statuses)')
            ->andWhere('quotation.expiresAt < :today')
            ->setParameter('statuses', [QuotationStatus::ISSUED->value, QuotationStatus::SENT->value])
            ->setParameter('today', $today)
            ->orderBy('quotation.expiresAt', 'ASC')
            ->addOrderBy('quotation.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
