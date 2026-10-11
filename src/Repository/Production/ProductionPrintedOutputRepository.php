<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionPrintedOutput;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionPrintedOutput> */
final class ProductionPrintedOutputRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionPrintedOutput::class);
    }

    public function findCurrent(ServiceOrderItem $item): ?ProductionPrintedOutput
    {
        return $this->findOneBy(['serviceOrderItem' => $item, 'currentMarker' => 1]);
    }

    public function findCurrentForUpdate(ServiceOrderItem $item): ?ProductionPrintedOutput
    {
        /** @var ProductionPrintedOutput|null $output */
        $output = $this->createQueryBuilder('output')
            ->andWhere('output.serviceOrderItem = :item')
            ->andWhere('output.currentMarker = 1')
            ->setParameter('item', $item)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        return $output;
    }

    public function nextVersion(ServiceOrderItem $item): int
    {
        return (int) $this->createQueryBuilder('output')
            ->select('COALESCE(MAX(output.versionNumber), 0)')
            ->andWhere('output.serviceOrderItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult() + 1;
    }
}
