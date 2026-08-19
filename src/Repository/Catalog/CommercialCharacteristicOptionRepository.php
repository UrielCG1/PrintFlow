<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->orderBy('option.displayOrder', 'ASC')
            ->addOrderBy('option.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $options;
    }
}
