<?php

declare(strict_types=1);

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Enum\Catalog\CommercialItemType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
