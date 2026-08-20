<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialCharacteristicOption> */
final class CommercialCharacteristicOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialCharacteristicOption::class);
    }

    /**
     * Incluye opciones inactivas que ya estén seleccionadas para no perder una
     * configuración histórica al editarla.
     *
     * @param list<int> $selectedOptionIds
     * @return list<CommercialCharacteristicOption>
     */
    public function findAvailableForConfiguration(
        CommercialCharacteristic $characteristic,
        array $selectedOptionIds = [],
    ): array {
        $queryBuilder = $this->createQueryBuilder('option')
            ->andWhere('option.characteristic = :characteristic')
            ->setParameter('characteristic', $characteristic);

        if ($selectedOptionIds === []) {
            $queryBuilder
                ->andWhere('option.isActive = :isActive')
                ->setParameter('isActive', true);
        } else {
            $queryBuilder
                ->andWhere('option.isActive = :isActive OR option.id IN (:selectedOptionIds)')
                ->setParameter('isActive', true)
                ->setParameter('selectedOptionIds', $selectedOptionIds);
        }

        /** @var list<CommercialCharacteristicOption> $options */
        $options = $queryBuilder
            ->orderBy('option.isActive', 'DESC')
            ->addOrderBy('option.displayOrder', 'ASC')
            ->addOrderBy('option.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $options;
    }

    /** @return list<CommercialCharacteristicOption> */
    public function findForCharacteristic(CommercialCharacteristic $characteristic): array
    {
        /** @var list<CommercialCharacteristicOption> $options */
        $options = $this->createQueryBuilder('option')
            ->andWhere('option.characteristic = :characteristic')
            ->setParameter('characteristic', $characteristic)
            ->orderBy('option.isActive', 'DESC')
            ->addOrderBy('option.displayOrder', 'ASC')
            ->addOrderBy('option.name', 'ASC')
            ->addOrderBy('option.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $options;
    }

    /** @return list<CommercialCharacteristicOption> */
    public function findActiveForCharacteristic(CommercialCharacteristic $characteristic): array
    {
        /** @var list<CommercialCharacteristicOption> $options */
        $options = $this->createQueryBuilder('option')
            ->andWhere('option.characteristic = :characteristic')
            ->andWhere('option.isActive = :isActive')
            ->setParameter('characteristic', $characteristic)
            ->setParameter('isActive', true)
            ->orderBy('option.displayOrder', 'ASC')
            ->addOrderBy('option.name', 'ASC')
            ->addOrderBy('option.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $options;
    }

    /** @return list<CommercialCharacteristicOption> */
    public function findActiveForCharacteristicForUpdate(CommercialCharacteristic $characteristic): array
    {
        /** @var list<CommercialCharacteristicOption> $options */
        $options = $this->createQueryBuilder('option')
            ->andWhere('option.characteristic = :characteristic')
            ->andWhere('option.isActive = :isActive')
            ->setParameter('characteristic', $characteristic)
            ->setParameter('isActive', true)
            ->orderBy('option.displayOrder', 'ASC')
            ->addOrderBy('option.name', 'ASC')
            ->addOrderBy('option.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $options;
    }

    public function nextDisplayOrder(CommercialCharacteristic $characteristic): int
    {
        $max = $this->createQueryBuilder('option')
            ->select('MAX(option.displayOrder)')
            ->andWhere('option.characteristic = :characteristic')
            ->setParameter('characteristic', $characteristic)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }

    /**
     * @param list<int> $characteristicIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeByCharacteristicIds(array $characteristicIds): array
    {
        $characteristicIds = array_values(array_unique(array_filter(
            array_map('intval', $characteristicIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($characteristicIds === []) {
            return [];
        }

        /** @var list<array{characteristicId: int|string, totalCount: int|string, activeCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('option')
            ->select('IDENTITY(option.characteristic) AS characteristicId')
            ->addSelect('COUNT(option.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN option.isActive = true THEN 1 ELSE 0 END) AS activeCount')
            ->andWhere('IDENTITY(option.characteristic) IN (:ids)')
            ->setParameter('ids', $characteristicIds)
            ->groupBy('option.characteristic')
            ->getQuery()
            ->getArrayResult();

        $summary = [];
        foreach ($rows as $row) {
            $summary[(int) $row['characteristicId']] = [
                'total' => (int) $row['totalCount'],
                'active' => (int) $row['activeCount'],
            ];
        }

        return $summary;
    }
}
