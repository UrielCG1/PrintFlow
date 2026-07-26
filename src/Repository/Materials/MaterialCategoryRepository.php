<?php

namespace App\Repository\Materials;

use App\Application\Materials\MaterialCategoryPage;
use App\Entity\Materials\MaterialCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaterialCategory>
 */
final class MaterialCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialCategory::class);
    }

    /**
     * @return list<MaterialCategory>
     */
    public function findAvailableForMaterialForm(
        ?MaterialCategory $selected = null,
    ): array {
        $queryBuilder = $this->createQueryBuilder('category')
            ->andWhere('category.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('category.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        return $queryBuilder
            ->orderBy('category.isActive', 'DESC')
            ->addOrderBy('category.name', 'ASC')
            ->addOrderBy('category.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function paginateForAdministration(
        ?string $search,
        ?bool $isActive,
        int $page,
        int $limit = 20,
    ): MaterialCategoryPage {
        $page = max(1, $page);
        $limit = max(1, min($limit, 100));

        $queryBuilder = $this->createQueryBuilder('category');

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('category.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->orX(
                        'LOWER(category.code) LIKE :search',
                        'LOWER(category.name) LIKE :search',
                        'LOWER(COALESCE(category.description, \'\')) LIKE :search',
                    ),
                )
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $totalQueryBuilder = clone $queryBuilder;

        $total = (int) $totalQueryBuilder
            ->select('COUNT(category.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        $page = min($page, $pageCount);

        $items = $queryBuilder
            ->orderBy('category.isActive', 'DESC')
            ->addOrderBy('category.name', 'ASC')
            ->addOrderBy('category.code', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new MaterialCategoryPage(
            items: $items,
            total: $total,
            currentPage: $page,
            pageCount: $pageCount,
        );
    }
}