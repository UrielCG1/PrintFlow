<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Production\ProductionCalendarException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionCalendarException> */
final class ProductionCalendarExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ProductionCalendarException::class); }

    /** @return list<ProductionCalendarException> */
    public function findActiveForEquipmentBetween(Equipment $equipment, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);
        $end = $end->setTimezone($utc);

        /** @var list<ProductionCalendarException> $rows */
        $rows = $this->createQueryBuilder('exception')
            ->andWhere('exception.isActive = true')
            ->andWhere('exception.startsAt < :end AND exception.endsAt > :start')
            ->andWhere('(exception.equipment IS NULL OR exception.equipment = :equipment)')
            ->setParameter('equipment', $equipment)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('exception.startsAt', 'ASC')
            ->getQuery()->getResult();
        return $rows;
    }
}
