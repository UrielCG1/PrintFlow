<?php

declare(strict_types=1);

namespace App\Repository\Materials;

use App\Entity\Materials\MaterialVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MaterialVariant> */
final class MaterialVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialVariant::class);
    }

    public function findActiveByBarcode(string $barcode): ?MaterialVariant
    {
        /** @var MaterialVariant|null $variant */
        $variant = $this->createQueryBuilder('variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.consumptionUnit', 'unit')
            ->addSelect('material', 'unit')
            ->andWhere('variant.barcode = :barcode')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isConsumable = true')
            ->andWhere('unit.isActive = true')
            ->setParameter('barcode', strtoupper(trim($barcode)))
            ->getQuery()
            ->getOneOrNullResult();

        return $variant;
    }

    /** @return list<MaterialVariant> */
    public function findActiveConsumable(): array
    {
        /** @var list<MaterialVariant> $variants */
        $variants = $this->createQueryBuilder('variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.consumptionUnit', 'unit')
            ->addSelect('material', 'unit')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isConsumable = true')
            ->andWhere('unit.isActive = true')
            ->orderBy('material.name', 'ASC')
            ->addOrderBy('variant.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $variants;
    }
}
