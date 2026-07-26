<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Repository\Catalog\ItemPriceRuleRepository;

final class CommercialItemPriceResolver
{
    public function __construct(
        private readonly ItemPriceRuleRepository $itemPriceRuleRepository,
    ) {
    }

    public function resolve(
        CommercialItem $item,
        string $quantity,
    ): CommercialItemPriceResolution {
        $normalizedQuantity = ItemPriceRule::normalizeMinimumQuantity($quantity);

        $rule = $this->itemPriceRuleRepository->findApplicableQuantityTier(
            $item,
            $normalizedQuantity,
        );

        return new CommercialItemPriceResolution(
            item: $item,
            quantity: $normalizedQuantity,
            unitPrice: $rule?->getUnitPrice() ?? $item->getBasePrice(),
            appliedRule: $rule,
        );
    }
}