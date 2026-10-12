<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryStockUnit;

/** @param list<InventoryStockUnit> $items */
final readonly class InventoryStockUnitPage
{
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $pageCount,
    ) {
    }
}
