<?php

namespace App\Service\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;

final readonly class PriceResolution
{
    public const SOURCE_BASE_PRICE = 'BASE_PRICE';
    public const SOURCE_QUANTITY_TIER = 'QUANTITY_TIER';

    public function __construct(
        private CommercialItem $commercialItem,
        private string $quantity,
        private string $unitPrice,
        private string $source,
        private ?ItemPriceRule $appliedRule,
    ) {
    }

    public function getCommercialItem(): CommercialItem
    {
        return $this->commercialItem;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAppliedRule(): ?ItemPriceRule
    {
        return $this->appliedRule;
    }

    /** @return array<string, int|string|null> */
    public function toSnapshot(): array
    {
        return [
            'commercial_item_id' => $this->commercialItem->getId(),
            'commercial_item_code' => $this->commercialItem->getCode(),
            'commercial_item_name' => $this->commercialItem->getName(),
            'measurement_unit_code' => $this->commercialItem->getMeasurementUnit()->getCode(),
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'price_source' => $this->source,
            'item_price_rule_id' => $this->appliedRule?->getId(),
            'item_price_rule_min_quantity' => $this->appliedRule?->getMinQuantity(),
        ];
    }
}