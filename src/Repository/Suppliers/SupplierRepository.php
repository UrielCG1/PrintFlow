<?php

namespace App\Repository\Suppliers;

use App\Application\Suppliers\SupplierPage;
use App\Entity\Suppliers\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
final class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /**
     * @return list<Supplier>
     */
    public function findAvailableForMaterialForm(?Supplier $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('supplier')
            ->andWhere('supplier.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('supplier.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        return $queryBuilder
            ->orderBy('supplier.isActive', 'DESC')
            ->addOrderBy('supplier.businessName', 'ASC')
            ->addOrderBy('supplier.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function paginateForAdministration(
        string $search,
        ?bool $isActive,
        int $page,
        int $perPage = 20,
    ): SupplierPage {
        $queryBuilder = $this->createQueryBuilder('supplier')
            ->orderBy('supplier.isActive', 'DESC')
            ->addOrderBy('supplier.businessName', 'ASC')
            ->addOrderBy('supplier.code', 'ASC');

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('supplier.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(
                    'LOWER(supplier.code) LIKE :search
                    OR LOWER(supplier.businessName) LIKE :search
                    OR LOWER(supplier.legalName) LIKE :search
                    OR LOWER(supplier.taxId) LIKE :search
                    OR LOWER(supplier.email) LIKE :search
                    OR supplier.phone LIKE :search'
                )
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(supplier.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pageCount);

        /** @var list<Supplier> $items */
        $items = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new SupplierPage($items, $total, $page, $pageCount);
    }
}