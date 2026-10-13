<?php

declare(strict_types=1);

namespace App\Repository\Suppliers;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Suppliers\SupplierMaterialVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SupplierMaterialVariant> */
final class SupplierMaterialVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierMaterialVariant::class);
    }

    /** @param list<MaterialVariant> $variants @return list<SupplierMaterialVariant> */
    public function findActiveForVariants(array $variants): array
    {
        if ($variants === []) {
            return [];
        }

        /** @var list<SupplierMaterialVariant> $offers */
        $offers = $this->createQueryBuilder('offer')
            ->innerJoin('offer.supplier', 'supplier')
            ->innerJoin('offer.purchaseUnit', 'purchaseUnit')
            ->innerJoin('offer.variant', 'variant')
            ->addSelect('supplier', 'purchaseUnit', 'variant')
            ->andWhere('offer.variant IN (:variants)')
            ->andWhere('offer.isActive = true')
            ->andWhere('supplier.isActive = true')
            ->andWhere('supplier.deletedAt IS NULL')
            ->andWhere('(offer.validUntil IS NULL OR offer.validUntil >= :now)')
            ->setParameter('variants', $variants)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->orderBy('variant.id', 'ASC')
            ->addOrderBy('offer.isPreferred', 'DESC')
            ->addOrderBy('offer.priority', 'ASC')
            ->addOrderBy('offer.validFrom', 'DESC')
            ->getQuery()
            ->getResult();

        return $offers;
    }
}
