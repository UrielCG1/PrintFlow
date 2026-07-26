<?php

namespace App\Service\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Repository\Catalog\ItemPriceRuleRepository;

final class CatalogPriceResolver
{
    public function __construct(
        private readonly ItemPriceRuleRepository $itemPriceRuleRepository,
    ) {
    }

    public function resolveForNewQuote(CommercialItem $item, string $quantity): PriceResolution
    {
        $quantity = $this->normalizeQuantity($quantity);

        if (!$item->isActive() || !$item->getCategory()->isActive() || !$item->getMeasurementUnit()->isActive()) {
            throw new \DomainException('El concepto comercial no está disponible para nuevas cotizaciones.');
        }

        $rule = $this->itemPriceRuleRepository->findApplicableActiveRule($item, $quantity);

        return new PriceResolution(
            commercialItem: $item,
            quantity: $quantity,
            unitPrice: $rule?->getUnitPrice() ?? $item->getBasePrice(),
            source: $rule === null ? PriceResolution::SOURCE_BASE_PRICE : PriceResolution::SOURCE_QUANTITY_TIER,
            appliedRule: $rule,
        );
    }

    private function normalizeQuantity(string $quantity): string
    {
        $quantity = str_replace(',', '.', trim($quantity));

        if (preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity) !== 1) {
            throw new \InvalidArgumentException('La cantidad debe ser un decimal válido con máximo cuatro decimales.');
        }

        [$integer, $decimal] = array_pad(explode('.', $quantity, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($decimal, 4, '0');

        if (str_replace(['0', '.'], '', $normalized) === '') {
            throw new \InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        return $normalized;
    }
}