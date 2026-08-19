<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('characteristic');
        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere('characteristic.code LIKE :search OR characteristic.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('characteristic.isActive = :isActive')
                ->setParameter('isActive', $isActive);
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
