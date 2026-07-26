<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ItemPriceRule> */
final class ItemPriceRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ItemPriceRule::class); }

    public function findApplicableActiveRule(CommercialItem $item, string $quantity): ?ItemPriceRule
    {
        return $this->createQueryBuilder('rule')
            ->andWhere('rule.commercialItem = :item')->setParameter('item', $item)
            ->andWhere('rule.isActive = :active')->setParameter('active', true)
            ->andWhere('rule.ruleType = :ruleType')->setParameter('ruleType', ItemPriceRule::TYPE_QUANTITY_TIER)
            ->andWhere('rule.minQuantity <= :quantity')->setParameter('quantity', $quantity)
            ->orderBy('rule.minQuantity', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}