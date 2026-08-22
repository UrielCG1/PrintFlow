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


    /**
     * @param list<int> $itemIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeQuantityTiersForItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($itemIds === []) {
            return [];
        }

        /** @var list<array{itemId: int|string, total: int|string, active: int|string}> $rows */
        $rows = $this->createQueryBuilder('rule')
            ->select('IDENTITY(rule.commercialItem) AS itemId')
            ->addSelect('COUNT(rule.id) AS total')
            ->addSelect('SUM(CASE WHEN rule.isActive = true THEN 1 ELSE 0 END) AS active')
            ->andWhere('IDENTITY(rule.commercialItem) IN (:itemIds)')
            ->andWhere('rule.ruleType = :ruleType')
            ->setParameter('itemIds', $itemIds)
            ->setParameter('ruleType', ItemPriceRuleType::QUANTITY_TIER->value)
            ->groupBy('rule.commercialItem')
            ->getQuery()
            ->getArrayResult();

        $summary = [];
        foreach ($rows as $row) {
            $summary[(int) $row['itemId']] = [
                'total' => (int) $row['total'],
                'active' => (int) $row['active'],
            ];
        }

        return $summary;
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