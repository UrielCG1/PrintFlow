<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;

final readonly class CommercialItemPriceResolution
{
    public function __construct(
        public CommercialItem $item,
        public string $quantity,
        public string $unitPrice,
        public ?ItemPriceRule $appliedRule,
    ) {
    }
}