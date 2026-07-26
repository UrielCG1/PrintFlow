<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Enum\Catalog\ItemPriceRuleType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ItemPriceRule> */
final class ItemPriceRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemPriceRule::class);
    }

    /**
     * @return list<ItemPriceRule>
     */
    public function findQuantityTiersForItem(CommercialItem $item): array
    {
        return $this->createQueryBuilder('rule')
            ->andWhere('rule.commercialItem = :item')
            ->andWhere('rule.ruleType = :ruleType')
            ->setParameter('item', $item)
            ->setParameter('ruleType', ItemPriceRuleType::QUANTITY_TIER->value)
            ->orderBy('rule.minQuantity', 'ASC')
            ->addOrderBy('rule.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findApplicableQuantityTier(
        CommercialItem $item,
        string $quantity,
    ): ?ItemPriceRule {
        /** @var ItemPriceRule|null $rule */
        $rule = $this->createQueryBuilder('rule')
            ->andWhere('rule.commercialItem = :item')
            ->andWhere('rule.ruleType = :ruleType')
            ->andWhere('rule.isActive = :isActive')
            ->andWhere('rule.minQuantity <= :quantity')
            ->setParameter('item', $item)
            ->setParameter('ruleType', ItemPriceRuleType::QUANTITY_TIER->value)
            ->setParameter('isActive', true)
            ->setParameter('quantity', $quantity)
            ->orderBy('rule.minQuantity', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $rule;
    }
}