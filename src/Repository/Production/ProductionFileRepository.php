<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionFile;
use App\Enum\Production\ProductionFilePurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionFile> */
final class ProductionFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionFile::class);
    }

    public function findLatestForItemAndPurpose(ServiceOrderItem $item, ProductionFilePurpose $purpose): ?ProductionFile
    {
        /** @var ProductionFile|null $file */
        $file = $this->createQueryBuilder('file')
            ->andWhere('file.serviceOrderItem = :item')
            ->andWhere('file.purpose = :purpose')
            ->setParameter('item', $item)
            ->setParameter('purpose', $purpose)
            ->orderBy('file.versionNumber', 'DESC')
            ->addOrderBy('file.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $file;
    }

    public function nextVersion(ServiceOrderItem $item, ProductionFilePurpose $purpose): int
    {
        return (int) $this->createQueryBuilder('file')
            ->select('COALESCE(MAX(file.versionNumber), 0)')
            ->andWhere('file.serviceOrderItem = :item')
            ->andWhere('file.purpose = :purpose')
            ->setParameter('item', $item)
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->getSingleScalarResult() + 1;
    }

    /** @return list<ProductionFile> */
    public function findForItem(ServiceOrderItem $item): array
    {
        /** @var list<ProductionFile> $files */
        $files = $this->createQueryBuilder('file')
            ->leftJoin('file.createdBy', 'createdBy')
            ->addSelect('createdBy')
            ->andWhere('file.serviceOrderItem = :item')
            ->setParameter('item', $item)
            ->orderBy('file.createdAt', 'DESC')
            ->addOrderBy('file.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $files;
    }
}
