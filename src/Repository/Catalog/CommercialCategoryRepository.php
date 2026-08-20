<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialCategory> */
final class CommercialCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialCategory::class);
    }

    /**
     * @return array{
     *     items: list<CommercialCategory>,
     *     currentPage: int,
     *     totalPages: int,
     *     totalItems: int
     * }
     */
    public function paginateForAdministration(
        string $search = '',
        ?bool $isActive = true,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('category');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('category.code LIKE :search OR category.name LIKE :search OR category.description LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('category.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(category.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<CommercialCategory> $items */
        $items = $queryBuilder
            ->orderBy('category.displayOrder', 'ASC')
            ->addOrderBy('category.name', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }

    /** @return list<CommercialCategory> */
    public function findActiveOrdered(): array
    {
        /** @var list<CommercialCategory> $categories */
        $categories = $this->createQueryBuilder('category')
            ->andWhere('category.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('category.displayOrder', 'ASC')
            ->addOrderBy('category.name', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    /** @return list<CommercialCategory> */
    public function findActiveOrderedForUpdate(): array
    {
        /** @var list<CommercialCategory> $categories */
        $categories = $this->createQueryBuilder('category')
            ->andWhere('category.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('category.displayOrder', 'ASC')
            ->addOrderBy('category.name', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $categories;
    }

    /** @return list<CommercialCategory> */
    public function findAvailableForItemForm(?CommercialCategory $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('category')
            ->andWhere('category.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('category.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        /** @var list<CommercialCategory> $categories */
        $categories = $queryBuilder
            ->orderBy('category.isActive', 'DESC')
            ->addOrderBy('category.displayOrder', 'ASC')
            ->addOrderBy('category.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function nextDisplayOrder(): int
    {
        $max = $this->createQueryBuilder('category')
            ->select('MAX(category.displayOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }
}
