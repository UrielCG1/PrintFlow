<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialCharacteristic> */
final class CommercialCharacteristicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialCharacteristic::class);
    }

    /**
     * @return array{items: list<CommercialCharacteristic>, currentPage: int, totalPages: int, totalItems: int}
     */
    public function paginateForAdministration(
        string $search = '',
        ?bool $isActive = true,
        ?CommercialCharacteristicInputType $inputType = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('characteristic');
        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere('characteristic.code LIKE :search OR characteristic.name LIKE :search OR characteristic.unitLabel LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('characteristic.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if ($inputType !== null) {
            $queryBuilder
                ->andWhere('characteristic.inputType = :inputType')
                ->setParameter('inputType', $inputType->value);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(characteristic.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<CommercialCharacteristic> $items */
        $items = $queryBuilder
            ->orderBy('characteristic.displayOrder', 'ASC')
            ->addOrderBy('characteristic.name', 'ASC')
            ->addOrderBy('characteristic.id', 'ASC')
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

    /** @return list<CommercialCharacteristic> */
    public function findActiveOrdered(): array
    {
        /** @var list<CommercialCharacteristic> $characteristics */
        $characteristics = $this->createQueryBuilder('characteristic')
            ->andWhere('characteristic.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('characteristic.displayOrder', 'ASC')
            ->addOrderBy('characteristic.name', 'ASC')
            ->addOrderBy('characteristic.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $characteristics;
    }

    /** @return list<CommercialCharacteristic> */
    public function findActiveOrderedForUpdate(): array
    {
        /** @var list<CommercialCharacteristic> $characteristics */
        $characteristics = $this->createQueryBuilder('characteristic')
            ->andWhere('characteristic.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('characteristic.displayOrder', 'ASC')
            ->addOrderBy('characteristic.name', 'ASC')
            ->addOrderBy('characteristic.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $characteristics;
    }

    public function nextDisplayOrder(): int
    {
        $max = $this->createQueryBuilder('characteristic')
            ->select('MAX(characteristic.displayOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }

    /** @return list<CommercialCharacteristic> */
    public function findActiveNotConfiguredForItem(CommercialItem $item): array
    {
        /** @var list<CommercialCharacteristic> $characteristics */
        $characteristics = $this->createQueryBuilder('characteristic')
            ->andWhere('characteristic.isActive = :isActive')
            ->andWhere('NOT EXISTS (
                SELECT configuration.id
                FROM App\\Entity\\Catalog\\CommercialItemCharacteristic configuration
                WHERE configuration.commercialItem = :item
                    AND configuration.characteristic = characteristic
            )')
            ->setParameter('isActive', true)
            ->setParameter('item', $item)
            ->orderBy('characteristic.displayOrder', 'ASC')
            ->addOrderBy('characteristic.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $characteristics;
    }
}
