<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Enum\Catalog\CommercialItemType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialItemCharacteristic> */
final class CommercialItemCharacteristicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialItemCharacteristic::class);
    }

    /** @return list<CommercialItemCharacteristic> */
    public function findForItem(CommercialItem $item): array
    {
        /** @var list<CommercialItemCharacteristic> $configurations */
        $configurations = $this->createQueryBuilder('configuration')
            ->innerJoin('configuration.characteristic', 'characteristic')
            ->leftJoin('configuration.allowedOptions', 'allowedOption')
            ->leftJoin('allowedOption.characteristicOption', 'characteristicOption')
            ->addSelect('characteristic', 'allowedOption', 'characteristicOption')
            ->andWhere('configuration.commercialItem = :item')
            ->setParameter('item', $item)
            ->orderBy('configuration.displayOrder', 'ASC')
            ->addOrderBy('characteristic.name', 'ASC')
            ->addOrderBy('allowedOption.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $configurations;
    }

    /** @return list<CommercialItemCharacteristic> */
    public function findForItemForUpdate(CommercialItem $item): array
    {
        /** @var list<CommercialItemCharacteristic> $configurations */
        $configurations = $this->createQueryBuilder('configuration')
            ->andWhere('configuration.commercialItem = :item')
            ->setParameter('item', $item)
            ->orderBy('configuration.displayOrder', 'ASC')
            ->addOrderBy('configuration.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $configurations;
    }

    public function nextDisplayOrderForItem(CommercialItem $item): int
    {
        $max = $this->createQueryBuilder('configuration')
            ->select('MAX(configuration.displayOrder)')
            ->andWhere('configuration.commercialItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }

    public function hasForItem(CommercialItem $item): bool
    {
        return $this->createQueryBuilder('configuration')
            ->select('1')
            ->andWhere('configuration.commercialItem = :item')
            ->setParameter('item', $item)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, int>
     */
    public function countByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($itemIds === []) {
            return [];
        }

        /** @var list<array{itemId: int|string, configurationCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('configuration')
            ->select('IDENTITY(configuration.commercialItem) AS itemId')
            ->addSelect('COUNT(configuration.id) AS configurationCount')
            ->andWhere('IDENTITY(configuration.commercialItem) IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->groupBy('configuration.commercialItem')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['itemId']] = (int) $row['configurationCount'];
        }

        return $counts;
    }

    /**
     * @param list<int> $characteristicIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeUsageByCharacteristicIds(array $characteristicIds): array
    {
        $characteristicIds = array_values(array_unique(array_filter(
            array_map('intval', $characteristicIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($characteristicIds === []) {
            return [];
        }

        /** @var list<array{characteristicId: int|string, totalCount: int|string, activeCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('configuration')
            ->select('IDENTITY(configuration.characteristic) AS characteristicId')
            ->addSelect('COUNT(configuration.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN item.isActive = true AND item.type = :productType THEN 1 ELSE 0 END) AS activeCount')
            ->innerJoin('configuration.commercialItem', 'item')
            ->andWhere('IDENTITY(configuration.characteristic) IN (:ids)')
            ->setParameter('ids', $characteristicIds)
            ->setParameter('productType', CommercialItemType::PRODUCT->value)
            ->groupBy('configuration.characteristic')
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

    /**
     * @param list<int> $optionIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeUsageByOptionIds(array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_filter(
            array_map('intval', $optionIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($optionIds === []) {
            return [];
        }

        /** @var list<array{optionId: int|string, totalCount: int|string, activeCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('configuration')
            ->select('IDENTITY(allowedOption.characteristicOption) AS optionId')
            ->addSelect('COUNT(allowedOption.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN item.isActive = true AND item.type = :productType THEN 1 ELSE 0 END) AS activeCount')
            ->innerJoin('configuration.commercialItem', 'item')
            ->innerJoin('configuration.allowedOptions', 'allowedOption')
            ->andWhere('IDENTITY(allowedOption.characteristicOption) IN (:ids)')
            ->setParameter('ids', $optionIds)
            ->setParameter('productType', CommercialItemType::PRODUCT->value)
            ->groupBy('allowedOption.characteristicOption')
            ->getQuery()
            ->getArrayResult();

        $summary = [];
        foreach ($rows as $row) {
            $summary[(int) $row['optionId']] = [
                'total' => (int) $row['totalCount'],
                'active' => (int) $row['activeCount'],
            ];
        }

        return $summary;
    }

    /** @return list<CommercialItemCharacteristic> */
    public function findUsageForCharacteristic(CommercialCharacteristic $characteristic): array
    {
        /** @var list<CommercialItemCharacteristic> $configurations */
        $configurations = $this->createQueryBuilder('configuration')
            ->innerJoin('configuration.commercialItem', 'item')
            ->innerJoin('item.category', 'category')
            ->innerJoin('item.measurementUnit', 'measurementUnit')
            ->leftJoin('configuration.allowedOptions', 'allowedOption')
            ->leftJoin('allowedOption.characteristicOption', 'characteristicOption')
            ->addSelect('item', 'category', 'measurementUnit', 'allowedOption', 'characteristicOption')
            ->andWhere('configuration.characteristic = :characteristic')
            ->setParameter('characteristic', $characteristic)
            ->orderBy('item.isActive', 'DESC')
            ->addOrderBy('item.name', 'ASC')
            ->addOrderBy('allowedOption.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $configurations;
    }

    /** @return list<CommercialItemCharacteristic> */
    public function findForQuotationItem(CommercialItem $item): array
    {
        return $this->findForItem($item);
    }

    public function findOneForItemAndCharacteristic(
        CommercialItem $item,
        CommercialCharacteristic $characteristic,
    ): ?CommercialItemCharacteristic {
        /** @var CommercialItemCharacteristic|null $configuration */
        $configuration = $this->findOneBy([
            'commercialItem' => $item,
            'characteristic' => $characteristic,
        ]);

        return $configuration;
    }

    public function hasConfigurationForCharacteristic(CommercialCharacteristic $characteristic): bool
    {
        return $this->createQueryBuilder('configuration')
            ->select('1')
            ->andWhere('configuration.characteristic = :characteristic')
            ->setParameter('characteristic', $characteristic)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasProductForCharacteristicOption(CommercialCharacteristicOption $option): bool
    {
        return $this->createQueryBuilder('configuration')
            ->select('1')
            ->innerJoin('configuration.allowedOptions', 'allowedOption')
            ->andWhere('allowedOption.characteristicOption = :option')
            ->setParameter('option', $option)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasActiveProductForCharacteristic(CommercialCharacteristic $characteristic): bool
    {
        return $this->createQueryBuilder('configuration')
            ->select('1')
            ->innerJoin('configuration.commercialItem', 'item')
            ->andWhere('configuration.characteristic = :characteristic')
            ->andWhere('item.isActive = :isActive')
            ->andWhere('item.type = :productType')
            ->setParameter('characteristic', $characteristic)
            ->setParameter('isActive', true)
            ->setParameter('productType', CommercialItemType::PRODUCT->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasActiveProductForCharacteristicOption(CommercialCharacteristicOption $option): bool
    {
        return $this->createQueryBuilder('configuration')
            ->select('1')
            ->innerJoin('configuration.commercialItem', 'item')
            ->innerJoin('configuration.allowedOptions', 'allowedOption')
            ->andWhere('allowedOption.characteristicOption = :option')
            ->andWhere('item.isActive = :isActive')
            ->andWhere('item.type = :productType')
            ->setParameter('option', $option)
            ->setParameter('isActive', true)
            ->setParameter('productType', CommercialItemType::PRODUCT->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Carga las configuraciones de varios Productos en una sola consulta para
     * que el diagnóstico no produzca N+1 al revisar características y opciones.
     *
     * @param list<int> $itemIds
     * @return list<CommercialItemCharacteristic>
     */
    public function findForItemIdsForHealthAssessment(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($itemIds === []) {
            return [];
        }

        /** @var list<CommercialItemCharacteristic> $configurations */
        $configurations = $this->createQueryBuilder('configuration')
            ->innerJoin('configuration.commercialItem', 'item')
            ->innerJoin('configuration.characteristic', 'characteristic')
            ->leftJoin('configuration.allowedOptions', 'allowedOption')
            ->leftJoin('allowedOption.characteristicOption', 'characteristicOption')
            ->addSelect('item', 'characteristic', 'allowedOption', 'characteristicOption')
            ->andWhere('IDENTITY(configuration.commercialItem) IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->orderBy('item.id', 'ASC')
            ->addOrderBy('configuration.displayOrder', 'ASC')
            ->addOrderBy('allowedOption.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $configurations;
    }


}
