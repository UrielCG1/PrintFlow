<?php

declare(strict_types=1);

namespace App\Repository\Equipment;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Enum\Equipment\EquipmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Equipment> */
final class EquipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipment::class);
    }

    /**
     * @return array{items: list<Equipment>, currentPage: int, totalPages: int, totalItems: int}
     */
    public function paginateForAdministration(
        string $search = '',
        ?OperationArea $operationArea = null,
        ?Operation $operation = null,
        ?EquipmentStatus $status = EquipmentStatus::AVAILABLE,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('equipment')
            ->innerJoin('equipment.primaryOperation', 'operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('operation', 'area');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere(
                    'equipment.code LIKE :search
                    OR equipment.name LIKE :search
                    OR equipment.brand LIKE :search
                    OR equipment.model LIKE :search
                    OR equipment.serialNumber LIKE :search
                    OR operation.code LIKE :search
                    OR operation.name LIKE :search
                    OR area.code LIKE :search
                    OR area.name LIKE :search',
                )
                ->setParameter('search', '%'.$search.'%');
        }

        if ($operationArea !== null) {
            $queryBuilder
                ->andWhere('operation.operationArea = :operationArea')
                ->setParameter('operationArea', $operationArea);
        }

        if ($operation !== null) {
            $queryBuilder
                ->andWhere('equipment.primaryOperation = :operation')
                ->setParameter('operation', $operation);
        }

        if ($status !== null) {
            $queryBuilder
                ->andWhere('equipment.status = :status')
                ->setParameter('status', $status->value);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(equipment.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<Equipment> $items */
        $items = $queryBuilder
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('equipment.name', 'ASC')
            ->addOrderBy('equipment.id', 'ASC')
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

    /** @return list<Equipment> */
    public function findAvailableForFutureExecution(?Operation $operation = null): array
    {
        $queryBuilder = $this->createQueryBuilder('equipment')
            ->innerJoin('equipment.primaryOperation', 'operation')
            ->innerJoin('operation.operationArea', 'area')
            ->addSelect('operation', 'area')
            ->andWhere('equipment.status = :available')
            ->andWhere('operation.isActive = :active')
            ->andWhere('area.isActive = :active')
            ->setParameter('available', EquipmentStatus::AVAILABLE->value)
            ->setParameter('active', true);

        if ($operation !== null) {
            $queryBuilder
                ->andWhere('equipment.primaryOperation = :operation')
                ->setParameter('operation', $operation);
        }

        /** @var list<Equipment> $equipment */
        $equipment = $queryBuilder
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('operation.displayOrder', 'ASC')
            ->addOrderBy('equipment.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $equipment;
    }
}