<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialItem> */
final class CommercialItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialItem::class);
    }

    /** @return list<CommercialItem> */
    public function findSelectableForNewQuotes(): array
    {
        return $this->createQueryBuilder('item')
            ->innerJoin('item.category', 'category')
            ->addSelect('category')
            ->innerJoin('item.measurementUnit', 'unit')
            ->addSelect('unit')
            ->andWhere('item.isActive = :active')
            ->andWhere('category.isActive = :active')
            ->andWhere('unit.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('category.displayOrder', 'ASC')
            ->addOrderBy('item.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasActiveForCategory(CommercialCategory $category): bool
    {
        return $this->count([
            'category' => $category,
            'isActive' => true,
        ]) > 0;
    }

    public function hasActiveForMeasurementUnit(MeasurementUnit $unit): bool
    {
        return $this->count([
            'measurementUnit' => $unit,
            'isActive' => true,
        ]) > 0;
    }
}