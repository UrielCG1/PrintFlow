<?php

declare(strict_types=1);

namespace App\Repository\Operations;

use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Operation> */
final class OperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operation::class);
    }

    /**
     * @return array{items: list<Operation>, currentPage: int, totalPages: int, totalItems: int}
     */
    public function paginateForAdministration(
        string $search = '',
        ?OperationArea $operationArea = null,
        ?bool $isActive = true,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('area');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('operation.code LIKE :search OR operation.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($operationArea !== null) {
            $queryBuilder
                ->andWhere('operation.operationArea = :operationArea')
                ->setParameter('operationArea', $operationArea);
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('operation.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(operation.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<Operation> $items */
        $items = $queryBuilder
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('operation.name', 'ASC')
            ->addOrderBy('operation.id', 'ASC')
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

    public function hasActiveForArea(OperationArea $operationArea): bool
    {
        return (int) $this->createQueryBuilder('operation')
            ->select('COUNT(operation.id)')
            ->andWhere('operation.operationArea = :operationArea')
            ->andWhere('operation.isActive = :isActive')
            ->setParameter('operationArea', $operationArea)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<Operation> */
    public function findActiveOrderedForUpdate(OperationArea $operationArea): array
    {
        /** @var list<Operation> $operations */
        $operations = $this->createQueryBuilder('operation')
            ->andWhere('operation.operationArea = :operationArea')
            ->andWhere('operation.isActive = :isActive')
            ->setParameter('operationArea', $operationArea)
            ->setParameter('isActive', true)
            ->orderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('operation.name', 'ASC')
            ->addOrderBy('operation.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $operations;
    }

    public function nextDisplayOrder(OperationArea $operationArea, bool $onlyActive = false): int
    {
        $queryBuilder = $this->createQueryBuilder('operation')
            ->select('COALESCE(MAX(operation.displayOrder), 0)')
            ->andWhere('operation.operationArea = :operationArea')
            ->setParameter('operationArea', $operationArea);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('operation.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        $maximum = $queryBuilder->getQuery()->getSingleScalarResult();

        return (int) $maximum + 10;
    }

    /** @return list<Operation> */
    public function findAllOrdered(): array
    {
        /** @var list<Operation> $operations */
        $operations = $this->createQueryBuilder('operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('area')
            ->orderBy('area.isActive', 'DESC')
            ->addOrderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.isActive', 'DESC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('operation.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $operations;
    }
    
    /** @return list<Operation> */
    public function findAvailableForEquipmentForm(?Operation $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('area');

        $availableCondition = $queryBuilder->expr()->andX(
            'operation.isActive = :active',
            'area.isActive = :active',
        );

        if ($selected === null) {
            $queryBuilder->andWhere($availableCondition);
        } else {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    $availableCondition,
                    'operation.id = :selectedId',
                ))
                ->setParameter('selectedId', $selected->getId());
        }

        /** @var list<Operation> $operations */
        $operations = $queryBuilder
            ->setParameter('active', true)
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('operation.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $operations;
    }

    /**
     * @return list<Operation>
     *
     * Esta consulta es para rutas nuevas: no incluye operaciones o áreas
     * inactivas, aunque una orden histórica pueda conservarlas en snapshot.
     */
    public function findActiveForServiceOrderPlanning(): array
    {
        /** @var list<Operation> $operations */
        $operations = $this->createQueryBuilder('operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('area')
            ->andWhere('operation.isActive = :active')
            ->andWhere('area.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('operation.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $operations;
    }
}
